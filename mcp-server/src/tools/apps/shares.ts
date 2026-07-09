// SPDX-License-Identifier: MIT

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
  title: 'List Shares',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Create Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
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
    note: z.string().optional().describe('Note to the share recipient'),
    hideDownload: z.boolean().optional().describe('Hide download button for public link shares'),
  }),
  handler: async (args: {
    path: string;
    shareType: number;
    shareWith?: string;
    permissions?: number;
    password?: string;
    expireDate?: string;
    label?: string;
    note?: string;
    hideDownload?: boolean;
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
      if (args.note !== undefined) body.note = args.note;
      if (args.hideDownload !== undefined) body.hideDownload = args.hideDownload ? 'true' : 'false';

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
  title: 'Update Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
    note: z.string().optional().describe('Note to the share recipient'),
    hideDownload: z.boolean().optional().describe('Hide download button for public link shares'),
  }),
  handler: async (args: {
    shareId: number;
    permissions?: number;
    password?: string;
    expireDate?: string;
    label?: string;
    note?: string;
    hideDownload?: boolean;
  }) => {
    try {
      const body: Record<string, string> = {};
      if (args.permissions !== undefined) body.permissions = String(args.permissions);
      if (args.password !== undefined) body.password = args.password;
      if (args.expireDate !== undefined) body.expireDate = args.expireDate;
      if (args.label !== undefined) body.label = args.label;
      if (args.note !== undefined) body.note = args.note;
      if (args.hideDownload !== undefined) body.hideDownload = args.hideDownload ? 'true' : 'false';

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
  title: 'Delete Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
 * Get details of a specific share by ID
 */
export const getShareTool = {
  name: 'get_share',
  title: 'Get Share',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get detailed information about a specific share by its ID',
  inputSchema: z.object({
    shareId: z.number().describe('The share ID to retrieve'),
  }),
  handler: async (args: { shareId: number }) => {
    try {
      const result = await fetchOCS<ShareEntry>(
        `/ocs/v2.php/apps/files_sharing/api/v1/shares/${args.shareId}`
      );

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result.ocs.data, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting share ${args.shareId}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List shares shared with the current user
 */
export const listSharesWithMeTool = {
  name: 'list_shares_with_me',
  title: 'List Shares With Me',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all files and folders shared with the current user',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchOCS<ShareEntry[]>('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
        queryParams: { shared_with_me: 'true' },
      });

      const shares = result.ocs.data;
      if (shares.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No shares found shared with you.' }],
        };
      }

      const formatted = shares.map((share) => {
        const typeLabel = SHARE_TYPE_LABELS[share.share_type] ?? `Type ${share.share_type}`;
        return `- [${typeLabel}] ${share.path} (from: ${share.displayname_owner})`;
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Shares with you (${shares.length}):\n${formatted.join('\n')}`,
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
 * Search for valid share recipients
 */
export const searchShareesTool = {
  name: 'search_sharees',
  title: 'Search Share Recipients',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Search for valid share recipients (users, groups, emails, federated users, circles, rooms)',
  inputSchema: z.object({
    search: z.string().describe('Search query for recipient name/email'),
    itemType: z.enum(['file', 'folder']).optional().describe('Item type (default: file)'),
    shareTypes: z
      .array(z.number())
      .optional()
      .describe(
        'Share types to include: 0=user, 1=group, 3=public, 4=email, 6=federated, 7=circle, 10=room (default: [0,1,3,4,6])'
      ),
  }),
  handler: async (args: { search: string; itemType?: string; shareTypes?: number[] }) => {
    try {
      const shareTypes = args.shareTypes ?? [0, 1, 3, 4, 6];
      const queryParams: Record<string, string | string[]> = {
        search: args.search,
        itemType: args.itemType ?? 'file',
        'shareType[]': shareTypes.map(String),
      };

      const result = await fetchOCS<Record<string, unknown>>(
        '/ocs/v1.php/apps/files_sharing/api/v1/sharees',
        { queryParams }
      );

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result.ocs.data, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error searching sharees: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List pending federated (remote) shares
 */
export const listPendingSharesTool = {
  name: 'list_pending_shares',
  title: 'List Pending Shares',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List pending federated/remote shares waiting to be accepted or declined',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchOCS<ShareEntry[]>(
        '/ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending'
      );

      const shares = result.ocs.data;
      if (shares.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No pending remote shares.' }],
        };
      }

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(shares, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing pending shares: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Accept a pending federated share
 */
export const acceptPendingShareTool = {
  name: 'accept_pending_share',
  title: 'Accept Pending Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Accept a pending federated/remote share',
  inputSchema: z.object({
    shareId: z.number().describe('The pending share ID to accept'),
  }),
  handler: async (args: { shareId: number }) => {
    try {
      await fetchOCS(
        `/ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/${args.shareId}`,
        { method: 'POST' }
      );

      return {
        content: [{ type: 'text' as const, text: `Pending share ${args.shareId} accepted.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error accepting share: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Decline a pending federated share
 */
export const declinePendingShareTool = {
  name: 'decline_pending_share',
  title: 'Decline Pending Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Decline a pending federated/remote share',
  inputSchema: z.object({
    shareId: z.number().describe('The pending share ID to decline'),
  }),
  handler: async (args: { shareId: number }) => {
    try {
      await fetchOCS(
        `/ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/${args.shareId}`,
        { method: 'DELETE' }
      );

      return {
        content: [{ type: 'text' as const, text: `Pending share ${args.shareId} declined.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error declining share: ${error instanceof Error ? error.message : String(error)}`,
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
export const sharesTools = [
  listSharesTool,
  createShareTool,
  updateShareTool,
  deleteShareTool,
  getShareTool,
  listSharesWithMeTool,
  searchShareesTool,
  listPendingSharesTool,
  acceptPendingShareTool,
  declinePendingShareTool,
];
