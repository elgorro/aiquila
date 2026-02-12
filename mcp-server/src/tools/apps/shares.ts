import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Share Management Tools
 * Provides share listing and diagnostics via OCS Sharing API
 */

interface ShareEntry {
  id: number;
  share_type: number;
  uid_owner: string;
  displayname_owner: string;
  permissions: number;
  stime: number;
  path: string;
  share_with?: string;
  share_with_displayname?: string;
  token?: string;
  expiration?: string;
  file_target?: string;
  item_type?: string;
}

const SHARE_TYPE_LABELS: Record<number, string> = {
  0: 'User',
  1: 'Group',
  3: 'Public link',
  4: 'Email',
  6: 'Federated',
};

/**
 * List all file shares (for diagnostics and auditing)
 */
export const listSharesTool = {
  name: 'list_shares',
  description: 'List file shares in Nextcloud (for diagnostics and security auditing)',
  inputSchema: z.object({
    path: z.string().optional().describe('Filter shares for a specific file/folder path'),
    reshares: z.boolean().optional().describe('Include reshares (default: false)'),
    subfiles: z
      .boolean()
      .optional()
      .describe('Show shares for all files in a folder (requires path)'),
  }),
  handler: async (args: { path?: string; reshares?: boolean; subfiles?: boolean }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.path) queryParams.path = args.path;
      if (args.reshares) queryParams.reshares = 'true';
      if (args.subfiles) queryParams.subfiles = 'true';

      const result = await fetchOCS<ShareEntry[]>('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
        queryParams,
      });

      const shares = result.ocs.data;

      if (shares.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'No shares found.',
            },
          ],
        };
      }

      const formatted = shares.map((share) => {
        const typeLabel = SHARE_TYPE_LABELS[share.share_type] ?? `Type ${share.share_type}`;
        const target = share.share_with_displayname || share.share_with || share.token || '';
        const expiry = share.expiration ? ` (expires: ${share.expiration})` : '';
        return `- [${typeLabel}] ${share.path} → ${target}${expiry} (owner: ${share.uid_owner})`;
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Shares (${shares.length}):\n${formatted.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing shares: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Share Management tools
 */
export const sharesTools = [listSharesTool];
