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
  url?: string;
  expiration?: string;
  label?: string;
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
 * Create a file or folder share
 */
export const createShareTool = {
  name: 'create_share',
  description: 'Create a file or folder share in Nextcloud',
  inputSchema: z.object({
    path: z.string().describe('File or folder path to share'),
    shareType: z
      .number()
      .describe('Share type: 0=user, 1=group, 3=public link, 4=email, 6=federated'),
    shareWith: z
      .string()
      .optional()
      .describe('Recipient (required for user/group/email/federated; omit for public link)'),
    permissions: z
      .number()
      .optional()
      .describe('Permission bitmask: 1=read, 2=update, 4=create, 8=delete, 16=share'),
    password: z.string().optional().describe('Password-protect the share'),
    expireDate: z.string().optional().describe('Expiration date (YYYY-MM-DD)'),
    label: z.string().optional().describe('Label for public link shares'),
  }),
  handler: async (args: {
    path: string;
    shareType: number;
    shareWith?: string;
    permissions?: number;
    password?: string;
    expireDate?: string;
    label?: string;
  }) => {
    try {
      const body: Record<string, string> = {
        path: args.path,
        shareType: String(args.shareType),
      };
      if (args.shareWith !== undefined) body.shareWith = args.shareWith;
      if (args.permissions !== undefined) body.permissions = String(args.permissions);
      if (args.password !== undefined) body.password = args.password;
      if (args.expireDate !== undefined) body.expireDate = args.expireDate;
      if (args.label !== undefined) body.label = args.label;

      const result = await fetchOCS<ShareEntry>('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
        method: 'POST',
        body,
      });

      const share = result.ocs.data;
      const typeLabel = SHARE_TYPE_LABELS[share.share_type] ?? `Type ${share.share_type}`;
      const parts = [`Share created (ID: ${share.id}, type: ${typeLabel})`];
      if (share.token) parts.push(`Token: ${share.token}`);
      if (share.url) parts.push(`URL: ${share.url}`);

      return {
        content: [{ type: 'text' as const, text: parts.join('\n') }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating share: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Update an existing share
 */
export const updateShareTool = {
  name: 'update_share',
  description: 'Update an existing share in Nextcloud',
  inputSchema: z.object({
    shareId: z.number().describe('The share ID to update'),
    permissions: z
      .number()
      .optional()
      .describe('Permission bitmask: 1=read, 2=update, 4=create, 8=delete, 16=share'),
    password: z.string().optional().describe('Password-protect the share'),
    expireDate: z.string().optional().describe('Expiration date (YYYY-MM-DD)'),
    label: z.string().optional().describe('Label for public link shares'),
  }),
  handler: async (args: {
    shareId: number;
    permissions?: number;
    password?: string;
    expireDate?: string;
    label?: string;
  }) => {
    try {
      const body: Record<string, string> = {};
      if (args.permissions !== undefined) body.permissions = String(args.permissions);
      if (args.password !== undefined) body.password = args.password;
      if (args.expireDate !== undefined) body.expireDate = args.expireDate;
      if (args.label !== undefined) body.label = args.label;

      const result = await fetchOCS<ShareEntry>(
        `/ocs/v2.php/apps/files_sharing/api/v1/shares/${args.shareId}`,
        { method: 'PUT', body }
      );

      const share = result.ocs.data;
      const typeLabel = SHARE_TYPE_LABELS[share.share_type] ?? `Type ${share.share_type}`;
      return {
        content: [
          {
            type: 'text' as const,
            text: `Share ${share.id} updated (type: ${typeLabel}, path: ${share.path})`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating share: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a share
 */
export const deleteShareTool = {
  name: 'delete_share',
  description: 'Delete a share in Nextcloud',
  inputSchema: z.object({
    shareId: z.number().describe('The share ID to delete'),
  }),
  handler: async (args: { shareId: number }) => {
    try {
      await fetchOCS(`/ocs/v2.php/apps/files_sharing/api/v1/shares/${args.shareId}`, {
        method: 'DELETE',
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Share ${args.shareId} deleted successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting share: ${error instanceof Error ? error.message : String(error)}`,
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
export const sharesTools = [listSharesTool, createShareTool, updateShareTool, deleteShareTool];
