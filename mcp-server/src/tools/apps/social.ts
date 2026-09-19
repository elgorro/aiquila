// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  fetchSocialAPI,
  type SocialAccount,
  type SocialList,
  type SocialMediaAttachment,
  type SocialNotificationsPage,
  type SocialSearchResults,
  type SocialStatus,
  type SocialStatusContext,
  type SocialStatusEdit,
} from '../../client/social.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Social App Tools
 *
 * Reads and writes the fediverse through the Mastodon client API that
 * Nextcloud Social exposes. Pinned against Social v0.24.1 — the app is pre-1.0
 * and its API is still moving.
 *
 * Out of scope in this module, deliberately: reports, blocks, mutes and flags;
 * the Pixelfed and PeerTube routes; admin and moderation; filters; list,
 * scheduled-status and migration management.
 */

// ── Constants ───────────────────────────────────────────────────────────────

/** Social clamps timeline reads to `ProbeOptions::MAX_LIMIT`; mirror it here. */
const TIMELINE_MAX = 50;
/** `/api/v2/search` clamps to 40. */
const SEARCH_MAX = 40;

const VISIBILITIES = ['public', 'unlisted', 'private', 'direct'] as const;

/** What every write tool says, because none of it can be taken back. */
const FEDERATION_WARNING =
  'This publishes to the fediverse: the action is delivered to other servers immediately and cannot be recalled. ' +
  'A public post is world-readable.';

const STATUS_ERRORS = {
  401: 'Not authenticated to Nextcloud Social. Check NEXTCLOUD_USER and NEXTCLOUD_PASSWORD, and that the Social app is enabled for that user.',
  404: 'No such status — it may have been deleted, or it is not visible to you.',
};

const ACCOUNT_ERRORS = {
  401: 'Not authenticated to Nextcloud Social. Check NEXTCLOUD_USER and NEXTCLOUD_PASSWORD, and that the Social app is enabled for that user.',
  404: 'No such account. Use the full handle (user@server) for a remote account.',
};

// ── Helpers ─────────────────────────────────────────────────────────────────

function text(body: string) {
  return { content: [{ type: 'text' as const, text: body }] };
}

/** Strip HTML tags and collapse whitespace, then truncate. */
function stripHtml(html: string, max = 280): string {
  const plain = html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  return plain.length > max ? `${plain.slice(0, max)}…` : plain;
}

/** The pagination arguments every timeline-shaped tool accepts. */
interface PageArgs {
  limit?: number;
  max_id?: number;
  min_id?: number;
}

const pageSchema = {
  limit: z.number().optional().describe(`Statuses to return (default 20, max ${TIMELINE_MAX})`),
  max_id: z.number().optional().describe('Only statuses older than this status id (page back)'),
  min_id: z.number().optional().describe('Only statuses newer than this status id (page forward)'),
};

function pageParams(args: PageArgs): Record<string, string | number | boolean | undefined> {
  return {
    limit: Math.min(args.limit ?? 20, TIMELINE_MAX),
    max_id: args.max_id,
    min_id: args.min_id,
  };
}

function handle(a: SocialAccount | undefined): string {
  if (!a) return 'unknown';
  return a.display_name ? `${a.display_name} (@${a.acct})` : `@${a.acct}`;
}

function formatStatus(s: SocialStatus): string {
  const shown = s.reblog ?? s;
  const lines = [
    s.reblog
      ? `- **${handle(s.account)}** boosted **${handle(shown.account)}** (ID: ${shown.id})`
      : `- **${handle(s.account)}** (ID: ${s.id})`,
  ];
  lines.push(`  ${shown.created_at} | ${shown.visibility}${shown.edited_at ? ' | edited' : ''}`);
  if (shown.spoiler_text) lines.push(`  ⚠ CW: ${shown.spoiler_text}`);
  lines.push(`  ${stripHtml(shown.content) || '(no text)'}`);
  if (shown.media_attachments?.length) {
    const media = shown.media_attachments
      .map((m) => `${m.type}${m.description ? ` — ${m.description}` : ''}`)
      .join('; ');
    lines.push(`  📎 ${shown.media_attachments.length} attachment(s): ${media}`);
  }
  if (shown.poll) {
    const opts = shown.poll.options.map((o) => `${o.title} (${o.votes_count ?? 0})`).join(', ');
    lines.push(`  📊 Poll${shown.poll.expired ? ' (closed)' : ''}: ${opts}`);
  }
  const flags = [
    shown.favourited ? 'favourited' : null,
    shown.reblogged ? 'boosted' : null,
    shown.bookmarked ? 'bookmarked' : null,
  ].filter(Boolean);
  lines.push(
    `  ↩ ${shown.replies_count} | 🔁 ${shown.reblogs_count} | ⭐ ${shown.favourites_count}` +
      (flags.length ? ` | ${flags.join(', ')}` : '')
  );
  return lines.join('\n');
}

function formatAccount(a: SocialAccount): string {
  const lines = [`- **${handle(a)}** (ID: ${a.id})${a.locked ? ' 🔒' : ''}${a.bot ? ' 🤖' : ''}`];
  lines.push(
    `  ${a.followers_count} followers | ${a.following_count} following | ${a.statuses_count} posts`
  );
  if (a.note) lines.push(`  ${stripHtml(a.note, 200)}`);
  lines.push(`  ${a.url}`);
  return lines.join('\n');
}

function formatStatuses(statuses: SocialStatus[], heading: string, empty: string) {
  if (!statuses || statuses.length === 0) return text(empty);
  return text(`${heading} (${statuses.length}):\n\n${statuses.map(formatStatus).join('\n\n')}`);
}

/** Read one timeline, with the same pagination and rendering everywhere. */
async function timeline(
  endpoint: string,
  args: PageArgs,
  extra: Record<string, string | number | boolean | undefined>,
  heading: string,
  empty: string
) {
  const statuses = await fetchSocialAPI<SocialStatus[]>(endpoint, {
    queryParams: { ...pageParams(args), ...extra },
  });
  return formatStatuses(statuses, heading, empty);
}

const READ_ONLY = {
  readOnlyHint: true,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: true,
} as const;

// ── Timelines ───────────────────────────────────────────────────────────────

export const homeTimelineTool = {
  name: 'social_home_timeline',
  title: 'Read Social Home Timeline',
  annotations: READ_ONLY,
  description:
    'Read the home timeline in Nextcloud Social: posts from the accounts this user follows, newest first.',
  inputSchema: z.object({ ...pageSchema }),
  handler: async (args: PageArgs = {}) => {
    try {
      // the trailing slash is part of the registered route
      return await timeline(
        '/api/v1/timelines/home/',
        args,
        {},
        'Home timeline',
        'Home timeline is empty. Follow some accounts to see posts here.'
      );
    } catch (error) {
      return handleAppError(error, 'Error reading the home timeline', ACCOUNT_ERRORS);
    }
  },
};

export const publicTimelineTool = {
  name: 'social_public_timeline',
  title: 'Read Social Public Timeline',
  annotations: READ_ONLY,
  description:
    'Read the public timeline in Nextcloud Social — every public post this server knows about. Set local to true for posts from this server only.',
  inputSchema: z.object({
    ...pageSchema,
    local: z.boolean().optional().describe('Only posts from this Nextcloud server (default false)'),
  }),
  handler: async (args: PageArgs & { local?: boolean } = {}) => {
    try {
      return await timeline(
        '/api/v1/timelines/public/',
        args,
        { local: args.local ?? false },
        args.local ? 'Local timeline' : 'Public timeline',
        'The public timeline is empty.'
      );
    } catch (error) {
      return handleAppError(error, 'Error reading the public timeline', ACCOUNT_ERRORS);
    }
  },
};

export const hashtagTimelineTool = {
  name: 'social_hashtag_timeline',
  title: 'Read Social Hashtag Timeline',
  annotations: READ_ONLY,
  description: 'Read the public posts carrying a given hashtag in Nextcloud Social.',
  inputSchema: z.object({
    ...pageSchema,
    hashtag: z.string().describe('The hashtag, without the leading #'),
    local: z.boolean().optional().describe('Only posts from this Nextcloud server (default false)'),
  }),
  handler: async (args: PageArgs & { hashtag: string; local?: boolean }) => {
    try {
      const tag = args.hashtag.replace(/^#/, '');
      return await timeline(
        `/api/v1/timelines/tag/${encodeURIComponent(tag)}`,
        args,
        { local: args.local ?? false },
        `#${tag}`,
        `No posts tagged #${tag}.`
      );
    } catch (error) {
      return handleAppError(error, 'Error reading the hashtag timeline', ACCOUNT_ERRORS);
    }
  },
};

export const listTimelineTool = {
  name: 'social_list_timeline',
  title: 'Read Social List Timeline',
  annotations: READ_ONLY,
  description:
    'Read the timeline of one of this user’s Social lists. Call it without list_id first to see the available lists and their ids.',
  inputSchema: z.object({
    ...pageSchema,
    list_id: z
      .number()
      .optional()
      .describe('The list to read; omit to list the available lists instead'),
  }),
  handler: async (args: PageArgs & { list_id?: number } = {}) => {
    try {
      if (args.list_id === undefined) {
        const lists = await fetchSocialAPI<SocialList[]>('/api/v1/lists');
        if (!lists || lists.length === 0) {
          return text('No lists. Create one in the Social app first.');
        }
        const formatted = lists.map((l) => `- **${l.title}** (ID: ${l.id})`).join('\n');
        return text(`Lists (${lists.length}):\n\n${formatted}`);
      }
      return await timeline(
        `/api/v1/timelines/list/${args.list_id}`,
        args,
        {},
        `List ${args.list_id}`,
        'That list has no posts yet.'
      );
    } catch (error) {
      return handleAppError(error, 'Error reading the list timeline', {
        ...ACCOUNT_ERRORS,
        404: 'No such list, or it does not belong to this user.',
      });
    }
  },
};

export const listSavedStatusesTool = {
  name: 'social_list_saved_statuses',
  title: 'List Saved Social Statuses',
  annotations: READ_ONLY,
  description: 'List the posts this user has favourited or bookmarked in Nextcloud Social.',
  inputSchema: z.object({
    ...pageSchema,
    kind: z
      .enum(['favourites', 'bookmarks'])
      .describe('Which collection to read: favourites (starred) or bookmarks (saved)'),
  }),
  handler: async (args: PageArgs & { kind: 'favourites' | 'bookmarks' }) => {
    try {
      // `/favourites/` keeps its trailing slash; `/bookmarks` has none
      const endpoint = args.kind === 'favourites' ? '/api/v1/favourites/' : '/api/v1/bookmarks';
      return await timeline(
        endpoint,
        args,
        {},
        args.kind === 'favourites' ? 'Favourites' : 'Bookmarks',
        `No ${args.kind} yet.`
      );
    } catch (error) {
      return handleAppError(error, `Error listing ${args.kind}`, ACCOUNT_ERRORS);
    }
  },
};

// ── Statuses ────────────────────────────────────────────────────────────────

export const getStatusTool = {
  name: 'social_get_status',
  title: 'Get Social Status',
  annotations: READ_ONLY,
  description:
    'Read one post in Nextcloud Social by its id, optionally with the thread around it (the posts it replies to and the replies to it).',
  inputSchema: z.object({
    status_id: z.number().describe('The numeric status id'),
    include_context: z
      .boolean()
      .optional()
      .describe('Also fetch the surrounding thread (default false)'),
  }),
  handler: async (args: { status_id: number; include_context?: boolean }) => {
    try {
      const status = await fetchSocialAPI<SocialStatus>(`/api/v1/statuses/${args.status_id}`);
      const sections = [formatStatus(status)];

      if (args.include_context) {
        const context = await fetchSocialAPI<SocialStatusContext>(
          `/api/v1/statuses/${args.status_id}/context`
        );
        const ancestors = context.ancestors ?? [];
        const descendants = context.descendants ?? [];
        if (ancestors.length) {
          sections.unshift(
            `In reply to (${ancestors.length}):\n\n${ancestors.map(formatStatus).join('\n\n')}`
          );
        }
        if (descendants.length) {
          sections.push(
            `Replies (${descendants.length}):\n\n${descendants.map(formatStatus).join('\n\n')}`
          );
        }
      }

      return text(sections.join('\n\n---\n\n'));
    } catch (error) {
      return handleAppError(error, 'Error reading the status', STATUS_ERRORS);
    }
  },
};

export const statusHistoryTool = {
  name: 'social_status_history',
  title: 'Get Social Status Edit History',
  annotations: READ_ONLY,
  description: 'List the successive versions of a post that has been edited in Nextcloud Social.',
  inputSchema: z.object({
    status_id: z.number().describe('The numeric status id'),
  }),
  handler: async (args: { status_id: number }) => {
    try {
      const history = await fetchSocialAPI<SocialStatusEdit[]>(
        `/api/v1/statuses/${args.status_id}/history`
      );
      if (!history || history.length === 0) {
        return text('This post has never been edited.');
      }
      const formatted = history
        .map((h, i) => {
          const lines = [`- **Version ${i + 1}** — ${h.created_at}`];
          if (h.spoiler_text) lines.push(`  ⚠ CW: ${h.spoiler_text}`);
          lines.push(`  ${stripHtml(h.content) || '(no text)'}`);
          return lines.join('\n');
        })
        .join('\n');
      return text(`Edit history (${history.length}):\n\n${formatted}`);
    } catch (error) {
      return handleAppError(error, 'Error reading the edit history', STATUS_ERRORS);
    }
  },
};

export const uploadMediaTool = {
  name: 'social_upload_media',
  title: 'Attach Nextcloud File to Social Post',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Stage a file from this user’s Nextcloud storage as a media attachment for Nextcloud Social, and return the media id to pass to social_post_status. The file is copied at upload time, so moving or deleting the original later does not empty the post. Nothing is published until the post is made.',
  inputSchema: z.object({
    path: z
      .string()
      .describe('Path to the file inside the user’s Nextcloud files, e.g. Photos/sunset.jpg'),
    description: z.string().optional().describe('Alt text describing the media, for accessibility'),
  }),
  handler: async (args: { path: string; description?: string }) => {
    try {
      const media = await fetchSocialAPI<SocialMediaAttachment>('/api/v1/media/from-file', {
        method: 'POST',
        body: { path: args.path, description: args.description ?? '' },
      });
      return text(
        `Attachment ready (media ID: ${media.id}, type: ${media.type}).\n` +
          `Pass it to social_post_status as media_ids: ["${media.id}"].`
      );
    } catch (error) {
      return handleAppError(error, 'Error attaching the file', {
        ...ACCOUNT_ERRORS,
        404: `No such file: ${args.path}. The path is resolved inside this user’s own Nextcloud files.`,
        413: 'The file is too large for Nextcloud Social to accept as an attachment.',
      });
    }
  },
};

export const postStatusTool = {
  name: 'social_post_status',
  title: 'Post to the Fediverse',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: true,
  },
  description:
    `Publish a post from this user's Nextcloud Social account. ${FEDERATION_WARNING} ` +
    'Supports replies, media attachments (see social_upload_media), polls, content warnings and visibility. ' +
    'Confirm the wording and the visibility with the user before calling this.',
  inputSchema: z.object({
    status: z.string().describe('The text of the post'),
    visibility: z
      .enum(VISIBILITIES)
      .optional()
      .describe(
        'Who sees it: public (default, world-readable and listed), unlisted, private (followers only), direct (mentioned accounts only). An unrecognised value is treated as direct'
      ),
    in_reply_to_id: z.number().optional().describe('Numeric id of the status this replies to'),
    media_ids: z.array(z.string()).optional().describe('Media ids returned by social_upload_media'),
    poll: z
      .object({
        options: z.array(z.string()).describe('The poll choices, 2 or more'),
        expires_in: z.number().describe('Seconds until the poll closes, e.g. 86400 for a day'),
        multiple: z.boolean().optional().describe('Allow more than one choice (default false)'),
      })
      .optional()
      .describe('Attach a poll to the post'),
    spoiler_text: z
      .string()
      .optional()
      .describe('Content warning shown in place of the post until the reader expands it'),
    sensitive: z.boolean().optional().describe('Mark attached media as sensitive'),
    language: z.string().optional().describe('ISO 639 language code of the post, e.g. en'),
  }),
  handler: async (args: {
    status: string;
    visibility?: (typeof VISIBILITIES)[number];
    in_reply_to_id?: number;
    media_ids?: string[];
    poll?: { options: string[]; expires_in: number; multiple?: boolean };
    spoiler_text?: string;
    sensitive?: boolean;
    language?: string;
  }) => {
    try {
      const body: Record<string, unknown> = {
        status: args.status,
        visibility: args.visibility ?? 'public',
      };
      if (args.in_reply_to_id !== undefined) body.in_reply_to_id = args.in_reply_to_id;
      if (args.media_ids?.length) body.media_ids = args.media_ids;
      if (args.poll) body.poll = args.poll;
      if (args.spoiler_text !== undefined) body.spoiler_text = args.spoiler_text;
      if (args.sensitive !== undefined) body.sensitive = args.sensitive;
      if (args.language !== undefined) body.language = args.language;

      const status = await fetchSocialAPI<SocialStatus>('/api/v1/statuses', {
        method: 'POST',
        body,
      });
      return text(
        `Posted (ID: ${status.id}, visibility: ${status.visibility}).\n${status.url ?? ''}\n\n` +
          formatStatus(status)
      );
    } catch (error) {
      return handleAppError(error, 'Error posting', {
        ...ACCOUNT_ERRORS,
        404: 'The status being replied to does not exist.',
        429: 'Rate limited by Nextcloud Social (30 posts per minute). Wait and try again.',
      });
    }
  },
};

export const deleteStatusTool = {
  name: 'social_delete_status',
  title: 'Delete Social Post',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    'Delete one of this user’s own posts in Nextcloud Social. A delete is federated to the servers that received the post, but copies already shown or cached elsewhere may remain.',
  inputSchema: z.object({
    status_id: z.number().describe('The numeric status id'),
  }),
  handler: async (args: { status_id: number }) => {
    try {
      await fetchSocialAPI(`/api/v1/statuses/${args.status_id}`, { method: 'DELETE' });
      return text(`Deleted status ${args.status_id}.`);
    } catch (error) {
      return handleAppError(error, 'Error deleting the status', {
        ...STATUS_ERRORS,
        403: 'Only the author can delete a post.',
      });
    }
  },
};

// ── Interactions ────────────────────────────────────────────────────────────

/** The six interaction tools are one route with a different `act`. */
function interactionTool(config: {
  name: string;
  title: string;
  act: string;
  description: string;
  destructive: boolean;
  past: string;
}) {
  return {
    name: config.name,
    title: config.title,
    annotations: {
      readOnlyHint: false,
      destructiveHint: config.destructive,
      idempotentHint: true,
      openWorldHint: true,
    },
    description: config.description,
    inputSchema: z.object({
      status_id: z.number().describe('The numeric status id'),
    }),
    handler: async (args: { status_id: number }) => {
      try {
        await fetchSocialAPI(`/api/v1/statuses/${args.status_id}/${config.act}`, {
          method: 'POST',
        });
        return text(`${config.past} status ${args.status_id}.`);
      } catch (error) {
        return handleAppError(error, `Error on ${config.act}`, STATUS_ERRORS);
      }
    },
  };
}

export const favouriteTool = interactionTool({
  name: 'social_favourite',
  title: 'Favourite Social Post',
  act: 'favourite',
  destructive: false,
  past: 'Favourited',
  description: `Favourite (star) a post in Nextcloud Social. ${FEDERATION_WARNING} The author's server is told who favourited it.`,
});

export const unfavouriteTool = interactionTool({
  name: 'social_unfavourite',
  title: 'Unfavourite Social Post',
  act: 'unfavourite',
  destructive: true,
  past: 'Unfavourited',
  description: 'Remove this user’s favourite from a post in Nextcloud Social.',
});

export const boostTool = interactionTool({
  name: 'social_boost',
  title: 'Boost Social Post',
  act: 'reblog',
  destructive: false,
  past: 'Boosted',
  description: `Boost (reblog) a post in Nextcloud Social, republishing it to this user's followers. ${FEDERATION_WARNING} Confirm with the user before boosting.`,
});

export const unboostTool = interactionTool({
  name: 'social_unboost',
  title: 'Undo Social Boost',
  act: 'unreblog',
  destructive: true,
  past: 'Removed the boost of',
  description: 'Undo a boost of a post in Nextcloud Social.',
});

export const bookmarkTool = interactionTool({
  name: 'social_bookmark',
  title: 'Bookmark Social Post',
  act: 'bookmark',
  destructive: false,
  past: 'Bookmarked',
  description:
    'Bookmark a post in Nextcloud Social so it can be found again. Bookmarks are private to this user and are not federated.',
});

export const unbookmarkTool = interactionTool({
  name: 'social_unbookmark',
  title: 'Remove Social Bookmark',
  act: 'unbookmark',
  destructive: true,
  past: 'Removed the bookmark from',
  description: 'Remove a bookmark from a post in Nextcloud Social.',
});

// ── Accounts and follows ────────────────────────────────────────────────────

export const lookupAccountTool = {
  name: 'social_lookup_account',
  title: 'Look Up Social Account',
  annotations: READ_ONLY,
  description:
    'Look up one fediverse account by handle in Nextcloud Social and return its profile and counts.',
  inputSchema: z.object({
    acct: z.string().describe('The handle: user for a local account, user@server for a remote one'),
  }),
  handler: async (args: { acct: string }) => {
    try {
      const account = await fetchSocialAPI<SocialAccount>('/api/v1/accounts/lookup', {
        queryParams: { acct: args.acct.replace(/^@/, '') },
      });
      return text(formatAccount(account));
    } catch (error) {
      return handleAppError(error, 'Error looking up the account', ACCOUNT_ERRORS);
    }
  },
};

export const accountStatusesTool = {
  name: 'social_account_statuses',
  title: 'Read Social Account Posts',
  annotations: READ_ONLY,
  description: 'Read the posts of one fediverse account in Nextcloud Social, newest first.',
  inputSchema: z.object({
    ...pageSchema,
    account: z.string().describe('Account id, or the handle (user@server)'),
    only_media: z.boolean().optional().describe('Only posts carrying media (default false)'),
  }),
  handler: async (args: PageArgs & { account: string; only_media?: boolean }) => {
    try {
      return await timeline(
        `/api/v1/accounts/${encodeURIComponent(args.account.replace(/^@/, ''))}/statuses`,
        args,
        { only_media: args.only_media ?? false },
        `Posts by ${args.account}`,
        `No posts by ${args.account}.`
      );
    } catch (error) {
      return handleAppError(error, 'Error reading the account’s posts', ACCOUNT_ERRORS);
    }
  },
};

export const listAccountFollowsTool = {
  name: 'social_list_account_follows',
  title: 'List Social Followers or Following',
  annotations: READ_ONLY,
  description: 'List the followers of a fediverse account, or the accounts it follows.',
  inputSchema: z.object({
    ...pageSchema,
    account: z.string().describe('Account id, or the handle (user@server)'),
    direction: z
      .enum(['followers', 'following'])
      .describe('followers = who follows this account; following = who this account follows'),
  }),
  handler: async (args: PageArgs & { account: string; direction: 'followers' | 'following' }) => {
    try {
      const accounts = await fetchSocialAPI<SocialAccount[]>(
        `/api/v1/accounts/${encodeURIComponent(args.account.replace(/^@/, ''))}/${args.direction}`,
        { queryParams: pageParams(args) }
      );
      if (!accounts || accounts.length === 0) {
        return text(`No ${args.direction} found for ${args.account}.`);
      }
      return text(
        `${args.direction === 'followers' ? 'Followers of' : 'Followed by'} ${args.account} ` +
          `(${accounts.length}):\n\n${accounts.map(formatAccount).join('\n\n')}`
      );
    } catch (error) {
      return handleAppError(error, `Error listing ${args.direction}`, ACCOUNT_ERRORS);
    }
  },
};

export const followAccountTool = {
  name: 'social_follow_account',
  title: 'Follow Fediverse Account',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    `Follow a fediverse account from this user's Nextcloud Social account. ${FEDERATION_WARNING} ` +
    'The other server is told who followed; a locked account has to approve the request first.',
  inputSchema: z.object({
    account: z.string().describe('Account id, or the handle (user@server)'),
  }),
  handler: async (args: { account: string }) => {
    try {
      await fetchSocialAPI(
        `/api/v1/accounts/${encodeURIComponent(args.account.replace(/^@/, ''))}/follow`,
        { method: 'POST' }
      );
      return text(`Now following ${args.account} (a locked account has to approve first).`);
    } catch (error) {
      return handleAppError(error, 'Error following the account', ACCOUNT_ERRORS);
    }
  },
};

export const unfollowAccountTool = {
  name: 'social_unfollow_account',
  title: 'Unfollow Fediverse Account',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: true,
  },
  description: 'Stop following a fediverse account from this user’s Nextcloud Social account.',
  inputSchema: z.object({
    account: z.string().describe('Account id, or the handle (user@server)'),
  }),
  handler: async (args: { account: string }) => {
    try {
      await fetchSocialAPI(
        `/api/v1/accounts/${encodeURIComponent(args.account.replace(/^@/, ''))}/unfollow`,
        { method: 'POST' }
      );
      return text(`No longer following ${args.account}.`);
    } catch (error) {
      return handleAppError(error, 'Error unfollowing the account', ACCOUNT_ERRORS);
    }
  },
};

export const listFollowRequestsTool = {
  name: 'social_list_follow_requests',
  title: 'List Social Follow Requests',
  annotations: READ_ONLY,
  description:
    'List the accounts waiting for this user to approve their follow request in Nextcloud Social.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const accounts = await fetchSocialAPI<SocialAccount[]>('/api/v1/follow_requests');
      if (!accounts || accounts.length === 0) {
        return text('No pending follow requests.');
      }
      return text(
        `Pending follow requests (${accounts.length}):\n\n${accounts.map(formatAccount).join('\n\n')}`
      );
    } catch (error) {
      return handleAppError(error, 'Error listing follow requests', ACCOUNT_ERRORS);
    }
  },
};

export const respondFollowRequestTool = {
  name: 'social_respond_follow_request',
  title: 'Approve or Reject Social Follow Request',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    `Approve or reject a pending follow request in Nextcloud Social. ${FEDERATION_WARNING} ` +
    'Approving lets that account see this user’s followers-only posts.',
  inputSchema: z.object({
    account_id: z
      .string()
      .describe('The id of the requesting account, from social_list_follow_requests'),
    action: z.enum(['authorize', 'reject']).describe('authorize to approve, reject to decline'),
  }),
  handler: async (args: { account_id: string; action: 'authorize' | 'reject' }) => {
    try {
      await fetchSocialAPI(
        `/api/v1/follow_requests/${encodeURIComponent(args.account_id)}/${args.action}`,
        { method: 'POST' }
      );
      return text(
        args.action === 'authorize'
          ? `Approved the follow request from account ${args.account_id}.`
          : `Rejected the follow request from account ${args.account_id}.`
      );
    } catch (error) {
      return handleAppError(error, 'Error answering the follow request', {
        ...ACCOUNT_ERRORS,
        404: 'No pending follow request from that account.',
      });
    }
  },
};

// ── Notifications ───────────────────────────────────────────────────────────

export const listNotificationsTool = {
  name: 'social_list_notifications',
  title: 'List Social Notifications',
  annotations: READ_ONLY,
  description:
    'List this user’s Nextcloud Social notifications — mentions, follows, favourites, boosts and poll results — grouped so that forty favourites of one post read as one line.',
  inputSchema: z.object({
    limit: z
      .number()
      .optional()
      .describe(`Notifications to read before grouping (default 20, max ${TIMELINE_MAX})`),
    max_id: z.number().optional().describe('Only notifications older than this id (page back)'),
    types: z
      .array(z.string())
      .optional()
      .describe('Only these types, e.g. ["mention","follow","favourite","reblog"]'),
    exclude_types: z.array(z.string()).optional().describe('Skip these types'),
  }),
  handler: async (
    args: { limit?: number; max_id?: number; types?: string[]; exclude_types?: string[] } = {}
  ) => {
    try {
      const queryParams: Record<string, string | number | boolean | undefined> = {
        limit: Math.min(args.limit ?? 20, TIMELINE_MAX),
        max_id: args.max_id,
      };
      // the controller takes these as repeated array params
      args.types?.forEach((t, i) => (queryParams[`types[${i}]`] = t));
      args.exclude_types?.forEach((t, i) => (queryParams[`exclude_types[${i}]`] = t));

      const page = await fetchSocialAPI<SocialNotificationsPage>('/api/v2/notifications', {
        queryParams,
      });
      const groups = page.notification_groups ?? [];
      if (groups.length === 0) {
        return text('No notifications.');
      }

      const accounts = new Map((page.accounts ?? []).map((a) => [a.id, a]));
      const statuses = new Map((page.statuses ?? []).map((s) => [s.id, s]));

      const formatted = groups
        .map((g) => {
          const who = g.sample_account_ids.map((id) => handle(accounts.get(id))).join(', ');
          const extra =
            g.notifications_count > g.sample_account_ids.length
              ? ` and ${g.notifications_count - g.sample_account_ids.length} more`
              : '';
          const lines = [
            `- **${g.type}** — ${who}${extra} (${g.notifications_count}) — ${g.latest_page_notification_at}`,
            `  Notification ID: ${g.most_recent_notification_id}`,
          ];
          const status = g.status_id ? statuses.get(g.status_id) : undefined;
          if (status) {
            lines.push(`  Status ${status.id}: ${stripHtml(status.content, 160) || '(no text)'}`);
          }
          return lines.join('\n');
        })
        .join('\n');

      return text(`Notifications (${groups.length} group(s)):\n\n${formatted}`);
    } catch (error) {
      return handleAppError(error, 'Error listing notifications', ACCOUNT_ERRORS);
    }
  },
};

export const dismissNotificationsTool = {
  name: 'social_dismiss_notifications',
  title: 'Dismiss Social Notifications',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Dismiss one Nextcloud Social notification, or clear all of them when no id is given. Dismissed notifications cannot be brought back.',
  inputSchema: z.object({
    notification_id: z
      .number()
      .optional()
      .describe('The notification to dismiss; omit to clear every notification'),
  }),
  handler: async (args: { notification_id?: number } = {}) => {
    try {
      if (args.notification_id === undefined) {
        await fetchSocialAPI('/api/v1/notifications/clear', { method: 'POST' });
        return text('Cleared all notifications.');
      }
      await fetchSocialAPI(`/api/v1/notifications/${args.notification_id}/dismiss`, {
        method: 'POST',
      });
      return text(`Dismissed notification ${args.notification_id}.`);
    } catch (error) {
      return handleAppError(error, 'Error dismissing notifications', {
        ...ACCOUNT_ERRORS,
        404: 'No such notification.',
      });
    }
  },
};

// ── Search ──────────────────────────────────────────────────────────────────

export const searchTool = {
  name: 'social_search',
  title: 'Search the Fediverse',
  annotations: READ_ONLY,
  description:
    'Search Nextcloud Social for accounts, hashtags or post content. Set resolve to true to fetch a handle or post URL this server has never seen — that contacts the remote server.',
  inputSchema: z.object({
    q: z.string().describe('The search query: text, a handle, a hashtag or a post URL'),
    type: z
      .enum(['accounts', 'hashtags', 'statuses'])
      .optional()
      .describe('Restrict the search to one kind of result; omit to search all three'),
    limit: z.number().optional().describe(`Results per kind (default 20, max ${SEARCH_MAX})`),
    resolve: z
      .boolean()
      .optional()
      .describe('Fetch an unknown handle or URL from its home server (default false)'),
  }),
  handler: async (args: {
    q: string;
    type?: 'accounts' | 'hashtags' | 'statuses';
    limit?: number;
    resolve?: boolean;
  }) => {
    try {
      const results = await fetchSocialAPI<SocialSearchResults>('/api/v2/search', {
        queryParams: {
          q: args.q,
          type: args.type ?? '',
          limit: Math.min(args.limit ?? 20, SEARCH_MAX),
          resolve: args.resolve ?? false,
        },
      });

      const sections: string[] = [];
      if (results.accounts?.length) {
        sections.push(
          `Accounts (${results.accounts.length}):\n\n${results.accounts.map(formatAccount).join('\n\n')}`
        );
      }
      if (results.hashtags?.length) {
        sections.push(
          `Hashtags (${results.hashtags.length}):\n\n` +
            results.hashtags.map((h) => `- **#${h.name}** — ${h.url}`).join('\n')
        );
      }
      if (results.statuses?.length) {
        sections.push(
          `Statuses (${results.statuses.length}):\n\n${results.statuses.map(formatStatus).join('\n\n')}`
        );
      }
      if (sections.length === 0) {
        return text(`No results for "${args.q}".`);
      }
      return text(sections.join('\n\n---\n\n'));
    } catch (error) {
      return handleAppError(error, 'Error searching', ACCOUNT_ERRORS);
    }
  },
};

// ── Export ──────────────────────────────────────────────────────────────────

export const socialTools = [
  homeTimelineTool,
  publicTimelineTool,
  hashtagTimelineTool,
  listTimelineTool,
  listSavedStatusesTool,
  getStatusTool,
  statusHistoryTool,
  uploadMediaTool,
  postStatusTool,
  deleteStatusTool,
  favouriteTool,
  unfavouriteTool,
  boostTool,
  unboostTool,
  bookmarkTool,
  unbookmarkTool,
  lookupAccountTool,
  accountStatusesTool,
  listAccountFollowsTool,
  followAccountTool,
  unfollowAccountTool,
  listFollowRequestsTool,
  respondFollowRequestTool,
  listNotificationsTool,
  dismissNotificationsTool,
  searchTool,
];
