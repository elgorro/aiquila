import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud User Status Tools
 * Manages user presence status via OCS User Status API
 */

interface UserStatus {
  userId: string;
  status: string;
  icon: string | null;
  message: string | null;
  clearAt: number | null;
  messageId: string | null;
  messageIsPredefined: boolean;
  statusIsUserDefined: boolean;
}

const STATUS_TYPES = ['online', 'away', 'dnd', 'invisible', 'offline'] as const;

// ---------------------------------------------------------------------------
// get_user_status
// ---------------------------------------------------------------------------

export const getUserStatusTool = {
  name: 'get_user_status',
  description:
    "Get the current user's presence status (online, away, DND, invisible, custom message)",
  inputSchema: z.object({
    userId: z
      .string()
      .optional()
      .describe('User ID to check (defaults to the configured Nextcloud user)'),
  }),
  handler: async (args: { userId?: string }) => {
    try {
      const path = args.userId
        ? `/ocs/v2.php/apps/user_status/api/v1/statuses/${encodeURIComponent(args.userId)}`
        : '/ocs/v2.php/apps/user_status/api/v1/user_status';

      const result = await fetchOCS<UserStatus>(path);
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result.ocs.data, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting user status: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// set_user_status
// ---------------------------------------------------------------------------

export const setUserStatusTool = {
  name: 'set_user_status',
  description: "Set the current user's status type (online, away, dnd, invisible, offline)",
  inputSchema: z.object({
    statusType: z.enum(STATUS_TYPES).describe('Status type to set'),
  }),
  handler: async (args: { statusType: string }) => {
    try {
      await fetchOCS('/ocs/v2.php/apps/user_status/api/v1/user_status/status', {
        method: 'PUT',
        body: { statusType: args.statusType },
      });

      return {
        content: [{ type: 'text' as const, text: `Status set to "${args.statusType}".` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error setting status: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// set_user_status_message
// ---------------------------------------------------------------------------

export const setUserStatusMessageTool = {
  name: 'set_user_status_message',
  description: 'Set a custom status message with optional emoji icon and auto-clear time',
  inputSchema: z.object({
    message: z.string().describe('Custom status message text'),
    statusIcon: z.string().optional().describe('Status emoji icon (e.g. "☕", "🏠", "🤒")'),
    clearAt: z
      .number()
      .optional()
      .describe('Unix timestamp when the message should auto-clear (null = never)'),
  }),
  handler: async (args: { message: string; statusIcon?: string; clearAt?: number }) => {
    try {
      const body: Record<string, string> = { message: args.message };
      if (args.statusIcon !== undefined) body.statusIcon = args.statusIcon;
      if (args.clearAt !== undefined) body.clearAt = String(args.clearAt);

      await fetchOCS('/ocs/v2.php/apps/user_status/api/v1/user_status/message/custom', {
        method: 'PUT',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Status message set: ${args.statusIcon ?? ''} ${args.message}`.trim(),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error setting status message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// clear_user_status_message
// ---------------------------------------------------------------------------

export const clearUserStatusMessageTool = {
  name: 'clear_user_status_message',
  description: "Clear the current user's custom status message",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      await fetchOCS('/ocs/v2.php/apps/user_status/api/v1/user_status/message', {
        method: 'DELETE',
      });

      return {
        content: [{ type: 'text' as const, text: 'Status message cleared.' }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error clearing status message: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// list_user_statuses
// ---------------------------------------------------------------------------

export const listUserStatusesTool = {
  name: 'list_user_statuses',
  description: "List all users' statuses for team presence visibility",
  inputSchema: z.object({
    limit: z.number().optional().describe('Maximum number of statuses to return'),
    offset: z.number().optional().describe('Offset for pagination'),
  }),
  handler: async (args: { limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.limit !== undefined) queryParams.limit = String(args.limit);
      if (args.offset !== undefined) queryParams.offset = String(args.offset);

      const result = await fetchOCS<UserStatus[]>('/ocs/v2.php/apps/user_status/api/v1/statuses', {
        queryParams,
      });

      const statuses = result.ocs.data;
      if (statuses.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No user statuses found.' }],
        };
      }

      const formatted = statuses.map((s) => {
        const icon = s.icon ?? '';
        const msg = s.message ?? '';
        const custom = icon || msg ? ` — ${icon} ${msg}`.trim() : '';
        return `- ${s.userId}: ${s.status}${custom}`;
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `User statuses (${statuses.length}):\n${formatted.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing user statuses: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const userStatusTools = [
  getUserStatusTool,
  setUserStatusTool,
  setUserStatusMessageTool,
  clearUserStatusMessageTool,
  listUserStatusesTool,
];
