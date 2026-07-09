// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  fetchPollsAPI,
  type Poll,
  type PollOption,
  type PollVote,
  type PollComment,
  type PollShare,
} from '../../client/polls.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Polls App Tools
 * Uses the Polls REST API v1.0 (/index.php/apps/polls/api/v1.0)
 */

const pollsStatusMap: Record<number, string> = {
  403: 'Access denied to this poll.',
  404: 'Poll, option, share, or comment not found.',
  409: 'Conflict — the option or share already exists.',
};

function formatPoll(p: Poll): string {
  const cfg = p.configuration ?? { title: '(untitled)' };
  const status = p.status?.deleted
    ? 'deleted'
    : p.status?.expired
      ? 'expired'
      : (cfg.expire ?? 0) < 0
        ? 'closed'
        : 'open';
  const owner = p.owner?.displayName || p.owner?.userId || 'unknown';
  return `[${p.id}] ${cfg.title} (${p.type}) — owner: ${owner}, status: ${status}`;
}

function formatPollDetailed(p: Poll): string {
  const cfg = p.configuration ?? { title: '(untitled)' };
  const lines: string[] = [formatPoll(p)];
  if (cfg.description) lines.push(`Description: ${cfg.description}`);
  if (cfg.access) lines.push(`Access: ${cfg.access}`);
  if (cfg.expire && cfg.expire > 0) {
    lines.push(`Expires: ${new Date(cfg.expire * 1000).toISOString()}`);
  }
  if (cfg.showResults) lines.push(`Show results: ${cfg.showResults}`);
  const flags = [
    cfg.anonymous ? 'anonymous' : null,
    cfg.allowComment ? 'comments allowed' : null,
    cfg.allowMaybe ? 'maybe allowed' : null,
    cfg.allowProposals ? 'proposals allowed' : null,
    cfg.useNo ? 'no-votes allowed' : null,
  ].filter(Boolean);
  if (flags.length) lines.push(`Flags: ${flags.join(', ')}`);
  if (p.currentUserStatus) {
    const s = p.currentUserStatus;
    lines.push(
      `You: role=${s.userRole ?? '?'}, votes=${s.countVotes ?? 0}, subscribed=${s.isSubscribed ?? false}`
    );
  }
  return lines.join('\n');
}

function formatOption(o: PollOption): string {
  const label = o.text || o.pollOptionText || `option ${o.id}`;
  const tallies: string[] = [];
  if (o.yes !== undefined) tallies.push(`yes=${o.yes}`);
  if (o.no !== undefined) tallies.push(`no=${o.no}`);
  if (o.maybe !== undefined) tallies.push(`maybe=${o.maybe}`);
  const confirmed = o.confirmed ? ' (confirmed)' : '';
  let timing = '';
  if (o.timestamp) {
    const start = new Date(o.timestamp * 1000).toISOString();
    timing = ` — ${start}${o.duration ? ` +${o.duration}s` : ''}`;
  }
  return `[${o.id}] ${label}${timing}${confirmed}${tallies.length ? ` (${tallies.join(', ')})` : ''}`;
}

function formatVote(v: PollVote): string {
  const who = v.userId ?? 'unknown';
  const answer = v.voteAnswer ?? '?';
  const what = v.optionText ?? (v.optionId !== undefined ? `option ${v.optionId}` : '');
  return `${who} → ${answer}${what ? ` on ${what}` : ''}`;
}

function formatComment(c: PollComment): string {
  const who = c.user?.displayName || c.userId || 'unknown';
  const when = c.timestamp ? new Date(c.timestamp * 1000).toISOString() : (c.dt ?? '');
  return `[${c.id}] ${who}${when ? ` at ${when}` : ''}: ${c.comment ?? ''}`;
}

function formatShare(s: PollShare): string {
  const label = s.label || s.userId || '';
  const who = label ? ` ${label}` : '';
  return `[${s.type}] token=${s.token}${who}${s.URL ? ` — ${s.URL}` : ''}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Polls
// ─────────────────────────────────────────────────────────────────────────────

export const listPollsTool = {
  name: 'list_polls',
  title: 'List Polls',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List all polls the current user can access (own, shared, or public). Returns id, title, type, owner, and status.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const polls = await fetchPollsAPI<Poll[]>('/polls');
      if (!polls || polls.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No polls found.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Polls (${polls.length}):\n\n${polls.map(formatPoll).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing polls', pollsStatusMap);
    }
  },
};

export const getPollTool = {
  name: 'get_poll',
  title: 'Get Poll',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get full details of a poll by ID: configuration, owner, status, and current user state.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID (from list_polls)'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { poll } = await fetchPollsAPI<{ poll: Poll }>(`/poll/${args.pollId}`);
      return {
        content: [{ type: 'text' as const, text: formatPollDetailed(poll) }],
      };
    } catch (error) {
      return handleAppError(error, 'Error getting poll', pollsStatusMap);
    }
  },
};

export const createPollTool = {
  name: 'create_poll',
  title: 'Create Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new poll. Type "textPoll" for text options (e.g. lunch choices), "datePoll" for date/time options (e.g. meeting scheduling).',
  inputSchema: z.object({
    title: z.string().describe('Poll title'),
    type: z.enum(['textPoll', 'datePoll']).describe('Poll type'),
  }),
  handler: async (args: { title: string; type: 'textPoll' | 'datePoll' }) => {
    try {
      const { poll } = await fetchPollsAPI<{ poll: Poll }>('/poll', {
        method: 'POST',
        body: { title: args.title, type: args.type },
      });
      return {
        content: [{ type: 'text' as const, text: `Poll created: ${formatPoll(poll)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating poll', pollsStatusMap);
    }
  },
};

export const updatePollTool = {
  name: 'update_poll',
  title: 'Update Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Update a poll configuration. Only provided fields are changed. Set expire to 0 for no expiration, or a unix timestamp to auto-close on that date.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
    title: z.string().optional(),
    description: z.string().optional(),
    expire: z
      .number()
      .int()
      .optional()
      .describe('Unix timestamp; 0 = no expiration; negative = close immediately'),
    access: z.enum(['open', 'private']).optional(),
    anonymous: z.boolean().optional(),
    allowComment: z.boolean().optional(),
    allowMaybe: z.boolean().optional(),
    allowProposals: z.boolean().optional(),
    showResults: z.enum(['never', 'always', 'closed']).optional(),
    autoReminder: z.boolean().optional(),
    hideBookedUp: z.boolean().optional(),
    useNo: z.boolean().optional(),
    maxVotesPerOption: z.number().int().min(0).optional(),
    maxVotesPerUser: z.number().int().min(0).optional(),
  }),
  handler: async (args: {
    pollId: number;
    title?: string;
    description?: string;
    expire?: number;
    access?: 'open' | 'private';
    anonymous?: boolean;
    allowComment?: boolean;
    allowMaybe?: boolean;
    allowProposals?: boolean;
    showResults?: 'never' | 'always' | 'closed';
    autoReminder?: boolean;
    hideBookedUp?: boolean;
    useNo?: boolean;
    maxVotesPerOption?: number;
    maxVotesPerUser?: number;
  }) => {
    try {
      const { pollId, ...rest } = args;
      const pollBody: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(rest)) {
        if (v !== undefined) pollBody[k] = v;
      }
      if (Object.keys(pollBody).length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No fields provided to update.' }],
          isError: true,
        };
      }
      const { poll } = await fetchPollsAPI<{ poll: Poll }>(`/poll/${pollId}`, {
        method: 'PUT',
        body: { poll: pollBody },
      });
      return {
        content: [{ type: 'text' as const, text: `Poll updated: ${formatPoll(poll)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating poll', pollsStatusMap);
    }
  },
};

export const deletePollTool = {
  name: 'delete_poll',
  title: 'Delete Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Permanently delete a poll. This cannot be undone.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      await fetchPollsAPI(`/poll/${args.pollId}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Poll ${args.pollId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting poll', pollsStatusMap);
    }
  },
};

export const closePollTool = {
  name: 'close_poll',
  title: 'Close Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Close a poll immediately so no further votes are accepted.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { poll } = await fetchPollsAPI<{ poll: Poll }>(`/poll/${args.pollId}/close`, {
        method: 'PUT',
      });
      return {
        content: [{ type: 'text' as const, text: `Poll closed: ${formatPoll(poll)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error closing poll', pollsStatusMap);
    }
  },
};

export const reopenPollTool = {
  name: 'reopen_poll',
  title: 'Reopen Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Reopen a previously closed poll so voting can resume.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { poll } = await fetchPollsAPI<{ poll: Poll }>(`/poll/${args.pollId}/reopen`, {
        method: 'PUT',
      });
      return {
        content: [{ type: 'text' as const, text: `Poll reopened: ${formatPoll(poll)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error reopening poll', pollsStatusMap);
    }
  },
};

export const clonePollTool = {
  name: 'clone_poll',
  title: 'Clone Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Clone an existing poll, copying its configuration and options into a new poll.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID to clone'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { poll } = await fetchPollsAPI<{ poll: Poll }>(`/poll/${args.pollId}/clone`, {
        method: 'POST',
      });
      return {
        content: [{ type: 'text' as const, text: `Poll cloned: ${formatPoll(poll)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error cloning poll', pollsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Options
// ─────────────────────────────────────────────────────────────────────────────

export const listPollOptionsTool = {
  name: 'list_poll_options',
  title: 'List Poll Options',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all options for a poll, with vote tallies per option.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { options } = await fetchPollsAPI<{ options: PollOption[] }>(
        `/poll/${args.pollId}/options`
      );
      if (!options || options.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No options for this poll.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Options (${options.length}):\n\n${options.map(formatOption).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing options', pollsStatusMap);
    }
  },
};

export const addTextPollOptionTool = {
  name: 'add_text_poll_option',
  title: 'Add Text Poll Option',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add a text option to a textPoll (e.g. "Pizza", "Sushi"). Use add_date_poll_option for datePolls.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID (must be a textPoll)'),
    text: z.string().describe('Option text'),
  }),
  handler: async (args: { pollId: number; text: string }) => {
    try {
      const { option } = await fetchPollsAPI<{ option: PollOption }>(
        `/poll/${args.pollId}/option`,
        {
          method: 'POST',
          body: { pollOptionText: args.text },
        }
      );
      return {
        content: [{ type: 'text' as const, text: `Option added: ${formatOption(option)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error adding text option', pollsStatusMap);
    }
  },
};

export const addDatePollOptionTool = {
  name: 'add_date_poll_option',
  title: 'Add Date Poll Option',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add a date/time option to a datePoll. Provide startAt as ISO-8601 (e.g. "2026-05-12T14:00:00Z") and durationSeconds (e.g. 3600 for 1 hour).',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID (must be a datePoll)'),
    startAt: z.string().describe('Start date/time as ISO-8601 (e.g. "2026-05-12T14:00:00Z")'),
    durationSeconds: z
      .number()
      .int()
      .min(0)
      .describe('Duration in seconds (0 = single point in time)'),
  }),
  handler: async (args: { pollId: number; startAt: string; durationSeconds: number }) => {
    try {
      const ms = Date.parse(args.startAt);
      if (Number.isNaN(ms)) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Invalid startAt "${args.startAt}": expected an ISO-8601 date/time string.`,
            },
          ],
          isError: true,
        };
      }
      const timestamp = Math.floor(ms / 1000);
      const { option } = await fetchPollsAPI<{ option: PollOption }>(
        `/poll/${args.pollId}/option`,
        {
          method: 'POST',
          body: {
            option: {
              text: '',
              timestamp,
              duration: args.durationSeconds,
            },
          },
        }
      );
      return {
        content: [{ type: 'text' as const, text: `Option added: ${formatOption(option)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error adding date option', pollsStatusMap);
    }
  },
};

export const deletePollOptionTool = {
  name: 'delete_poll_option',
  title: 'Delete Poll Option',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete an option from a poll by option ID.',
  inputSchema: z.object({
    optionId: z.number().int().describe('Option ID (from list_poll_options)'),
  }),
  handler: async (args: { optionId: number }) => {
    try {
      await fetchPollsAPI(`/option/${args.optionId}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Option ${args.optionId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting option', pollsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Votes
// ─────────────────────────────────────────────────────────────────────────────

export const listPollVotesTool = {
  name: 'list_poll_votes',
  title: 'List Poll Votes',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "List all votes for a poll. Anonymous polls redact voters' identities.",
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { votes } = await fetchPollsAPI<{ votes: PollVote[] }>(`/poll/${args.pollId}/votes`);
      if (!votes || votes.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No votes yet.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Votes (${votes.length}):\n\n${votes.map(formatVote).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing votes', pollsStatusMap);
    }
  },
};

export const voteOnPollTool = {
  name: 'vote_on_poll',
  title: 'Vote on Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Cast or change your vote on a poll option. setTo: "yes" to vote yes, "no" to vote no, "maybe" for a tentative yes (only if allowed).',
  inputSchema: z.object({
    optionId: z.number().int().describe('Option ID to vote on (from list_poll_options)'),
    setTo: z.enum(['yes', 'no', 'maybe']).describe('Vote value'),
  }),
  handler: async (args: { optionId: number; setTo: 'yes' | 'no' | 'maybe' }) => {
    try {
      const result = await fetchPollsAPI<{ vote: PollVote }>('/vote', {
        method: 'POST',
        body: { optionId: args.optionId, setTo: args.setTo },
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Vote recorded: ${formatVote(result.vote)}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error voting', pollsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Comments
// ─────────────────────────────────────────────────────────────────────────────

export const listPollCommentsTool = {
  name: 'list_poll_comments',
  title: 'List Poll Comments',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all comments on a poll.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { comments } = await fetchPollsAPI<{ comments: PollComment[] }>(
        `/poll/${args.pollId}/comments`
      );
      if (!comments || comments.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No comments yet.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Comments (${comments.length}):\n\n${comments.map(formatComment).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing comments', pollsStatusMap);
    }
  },
};

export const addPollCommentTool = {
  name: 'add_poll_comment',
  title: 'Add Poll Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Post a comment on a poll (requires comments to be enabled).',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
    message: z.string().describe('Comment text'),
  }),
  handler: async (args: { pollId: number; message: string }) => {
    try {
      const { comment } = await fetchPollsAPI<{ comment: PollComment }>('/comment', {
        method: 'POST',
        body: { pollId: args.pollId, message: args.message },
      });
      return {
        content: [{ type: 'text' as const, text: `Comment added: ${formatComment(comment)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error adding comment', pollsStatusMap);
    }
  },
};

export const deletePollCommentTool = {
  name: 'delete_poll_comment',
  title: 'Delete Poll Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete one of your poll comments by comment ID.',
  inputSchema: z.object({
    commentId: z.number().int().describe('Comment ID (from list_poll_comments)'),
  }),
  handler: async (args: { commentId: number }) => {
    try {
      await fetchPollsAPI(`/comment/${args.commentId}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Comment ${args.commentId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting comment', pollsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Shares
// ─────────────────────────────────────────────────────────────────────────────

export const listPollSharesTool = {
  name: 'list_poll_shares',
  title: 'List Poll Shares',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all shares (public link, user invitations, email invitations) for a poll.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
  }),
  handler: async (args: { pollId: number }) => {
    try {
      const { shares } = await fetchPollsAPI<{ shares: PollShare[] }>(
        `/poll/${args.pollId}/shares`
      );
      if (!shares || shares.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No shares for this poll.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Shares (${shares.length}):\n\n${shares.map(formatShare).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing shares', pollsStatusMap);
    }
  },
};

export const addPollShareTool = {
  name: 'add_poll_share',
  title: 'Add Poll Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Share a poll. type="public" creates a public link (no extra fields). type="user" invites a Nextcloud user (requires userId). type="email" invites by email (requires userId=email address, displayName).',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
    type: z.enum(['public', 'user', 'email']).describe('Share type'),
    userId: z
      .string()
      .optional()
      .describe('Nextcloud user ID (for type=user) or email address (for type=email)'),
    displayName: z.string().optional().describe('Display name (required for type=email)'),
  }),
  handler: async (args: {
    pollId: number;
    type: 'public' | 'user' | 'email';
    userId?: string;
    displayName?: string;
  }) => {
    try {
      let body: Record<string, unknown> = { type: args.type };
      if (args.type === 'user') {
        if (!args.userId) {
          return {
            content: [{ type: 'text' as const, text: 'userId is required for type="user".' }],
            isError: true,
          };
        }
        body = { type: 'user', userId: args.userId };
      } else if (args.type === 'email') {
        if (!args.userId || !args.displayName) {
          return {
            content: [
              {
                type: 'text' as const,
                text: 'userId (email) and displayName are required for type="email".',
              },
            ],
            isError: true,
          };
        }
        body = {
          type: 'email',
          userId: args.userId,
          displayName: args.displayName,
        };
      }
      const { share } = await fetchPollsAPI<{ share: PollShare }>(
        `/poll/${args.pollId}/share/${args.type}`,
        {
          method: 'POST',
          body,
        }
      );
      return {
        content: [{ type: 'text' as const, text: `Share created: ${formatShare(share)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating share', pollsStatusMap);
    }
  },
};

export const deletePollShareTool = {
  name: 'delete_poll_share',
  title: 'Delete Poll Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Revoke a poll share by its token (obtained from list_poll_shares or add_poll_share).',
  inputSchema: z.object({
    token: z.string().describe('Share token'),
  }),
  handler: async (args: { token: string }) => {
    try {
      await fetchPollsAPI(`/share/${args.token}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Share ${args.token} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting share', pollsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Subscription
// ─────────────────────────────────────────────────────────────────────────────

export const setPollSubscriptionTool = {
  name: 'set_poll_subscription',
  title: 'Set Poll Subscription',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Subscribe or unsubscribe the current user to poll notifications (new votes, comments). Use subscribe=true to subscribe, false to unsubscribe.',
  inputSchema: z.object({
    pollId: z.number().int().describe('Poll ID'),
    subscribe: z.boolean().describe('true to subscribe, false to unsubscribe'),
  }),
  handler: async (args: { pollId: number; subscribe: boolean }) => {
    try {
      await fetchPollsAPI(`/poll/${args.pollId}/subscription`, {
        method: args.subscribe ? 'PUT' : 'DELETE',
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: args.subscribe
              ? `Subscribed to poll ${args.pollId}.`
              : `Unsubscribed from poll ${args.pollId}.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(
        error,
        args.subscribe ? 'Error subscribing' : 'Error unsubscribing',
        pollsStatusMap
      );
    }
  },
};

export const pollsTools = [
  // Polls
  listPollsTool,
  getPollTool,
  createPollTool,
  updatePollTool,
  deletePollTool,
  closePollTool,
  reopenPollTool,
  clonePollTool,
  // Options
  listPollOptionsTool,
  addTextPollOptionTool,
  addDatePollOptionTool,
  deletePollOptionTool,
  // Votes
  listPollVotesTool,
  voteOnPollTool,
  // Comments
  listPollCommentsTool,
  addPollCommentTool,
  deletePollCommentTool,
  // Shares
  listPollSharesTool,
  addPollShareTool,
  deletePollShareTool,
  // Subscription
  setPollSubscriptionTool,
];
