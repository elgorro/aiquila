import { z } from "zod";
import { fetchOCS } from "../../client/ocs.js";

/**
 * Nextcloud Group Management Tools
 * Provides group management via OCS Provisioning API
 */

/**
 * List all groups in the Nextcloud instance
 */
export const listGroupsTool = {
  name: 'list_groups',
  description: 'List all groups in the Nextcloud instance',
  inputSchema: z.object({
    search: z.string().optional().describe('Search/filter string for group names'),
    limit: z.number().optional().describe('Maximum number of groups to return'),
    offset: z.number().optional().describe('Offset for pagination'),
  }),
  handler: async (args: { search?: string; limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.search) queryParams.search = args.search;
      if (args.limit !== undefined) queryParams.limit = String(args.limit);
      if (args.offset !== undefined) queryParams.offset = String(args.offset);

      const result = await fetchOCS<{ groups: string[] }>(
        "/ocs/v2.php/cloud/groups",
        { queryParams }
      );

      const groups = result.ocs.data.groups;

      return {
        content: [{
          type: 'text' as const,
          text: `Groups (${groups.length}):\n${groups.join('\n')}`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error listing groups: ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Get detailed information about a specific group
 */
export const getGroupInfoTool = {
  name: 'get_group_info',
  description: 'Get detailed information about a specific Nextcloud group',
  inputSchema: z.object({
    groupId: z.string().describe('The group ID (name) to get information about'),
  }),
  handler: async (args: { groupId: string }) => {
    try {
      const result = await fetchOCS<{ users: string[] }>(
        `/ocs/v2.php/cloud/groups/${encodeURIComponent(args.groupId)}`
      );

      const users = result.ocs.data.users;

      return {
        content: [{
          type: 'text' as const,
          text: `Group "${args.groupId}" members (${users.length}):\n${users.join('\n')}`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error getting group info for "${args.groupId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Add a user to a group
 */
export const addUserToGroupTool = {
  name: 'add_user_to_group',
  description: 'Add a Nextcloud user to a group',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to add to the group'),
    groupId: z.string().describe('The group ID (name) to add the user to'),
  }),
  handler: async (args: { userId: string; groupId: string }) => {
    try {
      await fetchOCS(
        `/ocs/v2.php/cloud/users/${encodeURIComponent(args.userId)}/groups`,
        {
          method: 'POST',
          body: { groupid: args.groupId },
        }
      );

      return {
        content: [{
          type: 'text' as const,
          text: `User "${args.userId}" has been added to group "${args.groupId}".`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error adding user "${args.userId}" to group "${args.groupId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Remove a user from a group
 */
export const removeUserFromGroupTool = {
  name: 'remove_user_from_group',
  description: 'Remove a Nextcloud user from a group',
  inputSchema: z.object({
    userId: z.string().describe('The user ID (login name) to remove from the group'),
    groupId: z.string().describe('The group ID (name) to remove the user from'),
  }),
  handler: async (args: { userId: string; groupId: string }) => {
    try {
      await fetchOCS(
        `/ocs/v2.php/cloud/users/${encodeURIComponent(args.userId)}/groups`,
        {
          method: 'DELETE',
          body: { groupid: args.groupId },
        }
      );

      return {
        content: [{
          type: 'text' as const,
          text: `User "${args.userId}" has been removed from group "${args.groupId}".`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error removing user "${args.userId}" from group "${args.groupId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Export all Group Management tools
 */
export const groupsTools = [
  listGroupsTool,
  getGroupInfoTool,
  addUserToGroupTool,
  removeUserFromGroupTool,
];
