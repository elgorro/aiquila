import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Out-of-Office / Absence Tools
 * Manages absence status via OCS DAV API (NC 28+)
 */

// ---------------------------------------------------------------------------
// get_out_of_office
// ---------------------------------------------------------------------------

export const getOutOfOfficeTool = {
  name: 'get_out_of_office',
  description: "Get a user's current out-of-office / absence status (NC 28+)",
  inputSchema: z.object({
    userId: z
      .string()
      .optional()
      .describe('User ID to check (defaults to the configured Nextcloud user)'),
  }),
  handler: async (args: { userId?: string }) => {
    try {
      const userId = args.userId ?? getNextcloudConfig().user;
      const result = await fetchOCS<Record<string, unknown>>(
        `/ocs/v2.php/apps/dav/api/v1/outOfOffice/${encodeURIComponent(userId)}/now`
      );

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result.ocs.data, null, 2) }],
      };
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      // 404 means no absence is set — return a friendly message
      if (msg.includes('404')) {
        const userId = args.userId ?? getNextcloudConfig().user;
        return {
          content: [
            { type: 'text' as const, text: `No out-of-office status set for "${userId}".` },
          ],
        };
      }
      return {
        content: [{ type: 'text' as const, text: `Error getting out-of-office: ${msg}` }],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// set_out_of_office
// ---------------------------------------------------------------------------

export const setOutOfOfficeTool = {
  name: 'set_out_of_office',
  description: 'Set an out-of-office / absence period with status message (NC 28+)',
  inputSchema: z.object({
    firstDay: z.string().describe('First day of absence (YYYY-MM-DD)'),
    lastDay: z.string().describe('Last day of absence (YYYY-MM-DD)'),
    status: z.string().describe('Short status label (e.g. "Vacation", "Sick leave")'),
    message: z.string().describe('Detailed absence message shown to others'),
    replacementUserId: z
      .string()
      .optional()
      .describe('User ID of the replacement/delegate during absence'),
    userId: z
      .string()
      .optional()
      .describe('User ID to set absence for (defaults to the configured Nextcloud user)'),
  }),
  handler: async (args: {
    firstDay: string;
    lastDay: string;
    status: string;
    message: string;
    replacementUserId?: string;
    userId?: string;
  }) => {
    try {
      const userId = args.userId ?? getNextcloudConfig().user;

      const payload: Record<string, string> = {
        firstDay: args.firstDay,
        lastDay: args.lastDay,
        status: args.status,
        message: args.message,
      };
      if (args.replacementUserId !== undefined) {
        payload.replacementUserId = args.replacementUserId;
      }

      await fetchOCS(`/ocs/v2.php/apps/dav/api/v1/outOfOffice/${encodeURIComponent(userId)}`, {
        method: 'POST',
        jsonBody: payload,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Out-of-office set for "${userId}": ${args.status} (${args.firstDay} → ${args.lastDay})`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error setting out-of-office: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// clear_out_of_office
// ---------------------------------------------------------------------------

export const clearOutOfOfficeTool = {
  name: 'clear_out_of_office',
  description: "Clear a user's out-of-office / absence status (NC 28+)",
  inputSchema: z.object({
    userId: z
      .string()
      .optional()
      .describe('User ID to clear absence for (defaults to the configured Nextcloud user)'),
  }),
  handler: async (args: { userId?: string }) => {
    try {
      const userId = args.userId ?? getNextcloudConfig().user;
      await fetchOCS(`/ocs/v2.php/apps/dav/api/v1/outOfOffice/${encodeURIComponent(userId)}`, {
        method: 'DELETE',
      });

      return {
        content: [{ type: 'text' as const, text: `Out-of-office cleared for "${userId}".` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error clearing out-of-office: ${error instanceof Error ? error.message : String(error)}`,
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

export const absenceTools = [getOutOfOfficeTool, setOutOfOfficeTool, clearOutOfOfficeTool];
