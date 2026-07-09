// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { getNextcloudConfig } from '../types.js';
import { handleAppError } from '../error-utils.js';

/**
 * Social Sharing Integration
 *
 * The upstream `nextcloud/socialsharing` apps are frontend-only: each registers a
 * sidebar action that opens a network's share URL built from a public-link token.
 * There is no backend API, so this tool replicates those URL templates server-side
 * for an existing public-link share, exposing only the networks whose
 * `socialsharing_<network>` app is enabled.
 */

interface ShareEntry {
  id: number;
  share_type: number;
  token?: string;
  url?: string;
}

const SHARE_TYPE_PUBLIC_LINK = 3;

/** Default share message used by the upstream apps. */
const DEFAULT_MESSAGE = 'I shared a file with you';

interface NetworkDef {
  /** Short network key used in output and the `networks` filter. */
  key: string;
  /** Enabled-app id gating this network. */
  appId: string;
  /** Builds the share URL from the public link and an (encoded) message. */
  build: (link: string, message: string) => string;
}

const NETWORKS: NetworkDef[] = [
  {
    key: 'email',
    appId: 'socialsharing_email',
    build: (link, msg) => `mailto:?subject=${msg}&body=${encodeURIComponent(link)}`,
  },
  {
    key: 'twitter',
    appId: 'socialsharing_twitter',
    build: (link) => `https://twitter.com/intent/tweet?url=${encodeURIComponent(link)}`,
  },
  {
    key: 'facebook',
    appId: 'socialsharing_facebook',
    build: (link) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(link)}`,
  },
  {
    key: 'telegram',
    appId: 'socialsharing_telegram',
    build: (link, msg) =>
      `https://telegram.me/share/url?url=${encodeURIComponent(link)}&text=${msg}`,
  },
  {
    key: 'whatsapp',
    appId: 'socialsharing_whatsapp',
    build: (link, msg) => `whatsapp://send?text=${msg}:%0A%0A${encodeURIComponent(link)}`,
  },
  {
    key: 'bluesky',
    appId: 'socialsharing_bluesky',
    build: (link, msg) =>
      `https://bsky.app/intent/compose?text=${msg}:%0A%0A${encodeURIComponent(link)}`,
  },
  {
    key: 'diaspora',
    appId: 'socialsharing_diaspora',
    build: (link) => `https://share.diasporafoundation.org/?url=${encodeURIComponent(link)}`,
  },
];

/** Fetch the set of enabled Nextcloud app ids. */
async function getEnabledApps(): Promise<Set<string>> {
  const result = await fetchOCS<{ apps: string[] }>('/ocs/v2.php/cloud/apps', {
    queryParams: { filter: 'enabled' },
  });
  return new Set(result.ocs.data.apps);
}

/** Resolve a public-link share by id or path. */
async function resolvePublicShare(args: { share_id?: number; path?: string }): Promise<ShareEntry> {
  if (args.share_id !== undefined) {
    const result = await fetchOCS<ShareEntry[] | ShareEntry>(
      `/ocs/v2.php/apps/files_sharing/api/v1/shares/${args.share_id}`
    );
    // The single-share endpoint returns an array with one entry.
    const data = result.ocs.data;
    const share = Array.isArray(data) ? data[0] : data;
    if (!share) throw new Error(`Share ${args.share_id} not found`);
    return share;
  }

  const result = await fetchOCS<ShareEntry[]>('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
    queryParams: { path: args.path as string },
  });
  const link = result.ocs.data.find((s) => s.share_type === SHARE_TYPE_PUBLIC_LINK && s.token);
  if (!link) {
    throw new Error(`No public link share found for path "${args.path}". Create one first.`);
  }
  return link;
}

export const generateSocialShareLinksTool = {
  name: 'generate_social_share_links',
  title: 'Generate Social Share Links',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Generate social-network share URLs (email, X/Twitter, Facebook, Telegram, WhatsApp, ' +
    'Bluesky, Diaspora) for an existing Nextcloud public-link share. Only networks whose ' +
    'socialsharing_<network> app is enabled are returned. Provide either share_id or path.',
  inputSchema: z.object({
    share_id: z.number().optional().describe('ID of an existing public-link share'),
    path: z
      .string()
      .optional()
      .describe('File/folder path; its public-link share is used (must already exist)'),
    networks: z
      .array(z.string())
      .optional()
      .describe('Optional filter, e.g. ["twitter","email"]. Defaults to all enabled networks.'),
  }),
  handler: async (args: { share_id?: number; path?: string; networks?: string[] }) => {
    if (args.share_id === undefined && !args.path) {
      return {
        content: [{ type: 'text' as const, text: 'Provide either share_id or path.' }],
        isError: true as const,
      };
    }

    try {
      const share = await resolvePublicShare(args);
      if (share.share_type !== SHARE_TYPE_PUBLIC_LINK || !share.token) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'The referenced share is not a public link (no shareable token).',
            },
          ],
          isError: true as const,
        };
      }

      const { url } = getNextcloudConfig();
      const link = share.url || `${url}/s/${share.token}`;
      const message = encodeURIComponent(DEFAULT_MESSAGE);

      const filter = args.networks ? new Set(args.networks.map((n) => n.toLowerCase())) : null;
      const enabledApps = await getEnabledApps();

      const lines = NETWORKS.filter((n) => enabledApps.has(n.appId))
        .filter((n) => !filter || filter.has(n.key))
        .map((n) => `${n.key}: ${n.build(link, message)}`);

      if (lines.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text:
                'No matching social sharing networks are available. Install/enable a ' +
                'socialsharing_<network> app (or adjust the networks filter).',
            },
          ],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Social share links for ${link}:\n${lines.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error generating social share links');
    }
  },
};

export const socialSharingTools = [generateSocialShareLinksTool];
