import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud User Management Tools
 * Provides user account management via OCS Provisioning API
 */

/**
 * List all users in the Nextcloud instance
 */
export const listUsersTool = {
  name: 'list_users',
  description: 'List all users in the Nextcloud instance',
  inputSchema: z.object({
    search: z.string().optional().describe('Search/filter string for user IDs'),
    limit: z.number().optional().describe('Maximum number of users to return'),
    offset: z.number().optional().describe('Offset for pagination'),
  }),
  handler: async (args: { search?: string; limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.search) queryParams.search = args.search;
      if (args.limit !== undefined) queryParams.limit = String(args.limit);
      if (args.offset !== undefined) queryParams.offset = String(args.offset);

      const result = await fetchOCS<{ users: string[] }>('/ocs/v2.php/cloud/users', {
        queryParams,
      });

      const users = result.ocs.data.users;

      return {
        content: [
          {
            type: 'text' as const,
            text: `Users (${users.length}):\n${users.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing users: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get detailed information about a specific user
 */
export const getUserInfoTool = {
  name: 'get_user_info',
  description: 'Get detailed information about a specific Nextcloud user',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to get information about'),
  }),
  handler: async (args: { userId: string }) => {
    try {
      const result = await fetchOCS<Record<string, unknown>>(
        `/ocs/v2.php/cloud/users/${encodeURIComponent(args.userId)}`
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: JSON.stringify(result.ocs.data, null, 2),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting user info for "${args.userId}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Enable a disabled user account
 */
export const enableUserTool = {
  name: 'enable_user',
  description: 'Enable a disabled Nextcloud user account',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to enable'),
  }),
  handler: async (args: { userId: string }) => {
    try {
      await fetchOCS(`/ocs/v2.php/cloud/users/${encodeURIComponent(args.userId)}/enable`, {
        method: 'PUT',
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `User "${args.userId}" has been enabled successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error enabling user "${args.userId}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Disable a user account
 */
export const disableUserTool = {
  name: 'disable_user',
  description: 'Disable a Nextcloud user account (prevents login but preserves data)',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to disable'),
  }),
  handler: async (args: { userId: string }) => {
    try {
      await fetchOCS(`/ocs/v2.php/cloud/users/${encodeURIComponent(args.userId)}/disable`, {
        method: 'PUT',
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `User "${args.userId}" has been disabled. The account data is preserved and can be re-enabled.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error disabling user "${args.userId}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all User Management tools
 */
export const usersTools = [listUsersTool, getUserInfoTool, enableUserTool, disableUserTool];
