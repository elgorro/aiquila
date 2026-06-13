// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Activity Tools
 * Read the per-user activity feed via the Activity app OCS API.
 */

interface Activity {
  activity_id: number;
  app: string;
  type: string;
  user: string;
  subject: string;
  message: string;
  object_type: string;
  object_id: number | string;
  object_name: string;
  link: string;
  datetime: string;
}

function formatActivities(activities: Activity[]): string {
  return activities
    .map((a) => {
      const time = a.datetime ? ` (${a.datetime})` : '';
      const msg = a.message ? `\n  ${a.message}` : '';
      return `- [${a.app}] ${a.subject}${time}${msg}`;
    })
    .join('\n');
}

// ---------------------------------------------------------------------------
// list_activity
// ---------------------------------------------------------------------------

export const listActivityTool = {
  name: 'list_activity',
  description:
    'List recent entries from the Nextcloud activity feed (file changes, shares, ' +
    'comments, calendar/contact edits, etc.) for the current user.',
  inputSchema: z.object({
    filter: z
      .enum(['all', 'self', 'by'])
      .optional()
      .describe(
        "Which feed to read: 'all' (default), 'self' (your own actions), or 'by' (others' actions)"
      ),
    limit: z.number().optional().describe('Maximum number of activities to return (default 50)'),
    since: z
      .number()
      .optional()
      .describe('Return activities after this activity_id (for pagination)'),
    sort: z
      .enum(['asc', 'desc'])
      .optional()
      .describe("Sort order by time: 'desc' (newest first, default) or 'asc'"),
  }),
  handler: async (args: {
    filter?: 'all' | 'self' | 'by';
    limit?: number;
    since?: number;
    sort?: 'asc' | 'desc';
  }) => {
    try {
      const filter = args.filter ?? 'all';
      const queryParams: Record<string, string> = {
        limit: String(args.limit ?? 50),
        sort: args.sort ?? 'desc',
      };
      if (args.since !== undefined) queryParams.since = String(args.since);

      const result = await fetchOCS<Activity[]>(
        `/ocs/v2.php/apps/activity/api/v2/activity/${filter}`,
        { queryParams }
      );

      const activities = result.ocs.data;
      if (activities.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No activity.' }],
        };
      }

      const lastId = activities[activities.length - 1].activity_id;
      return {
        content: [
          {
            type: 'text' as const,
            text:
              `Activity (${activities.length}):\n${formatActivities(activities)}\n\n` +
              `Last activity_id: ${lastId} (pass as 'since' to page further).`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing activity: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// get_object_activity
// ---------------------------------------------------------------------------

export const getObjectActivityTool = {
  name: 'get_object_activity',
  description:
    'List the activity history for a single object, e.g. one file. Use object_type ' +
    "'files' with a file ID to see what happened to that file.",
  inputSchema: z.object({
    object_type: z.string().optional().describe("The object type to filter by (default 'files')"),
    object_id: z.string().describe('The object ID (e.g. the Nextcloud file ID)'),
    limit: z.number().optional().describe('Maximum number of activities to return (default 50)'),
  }),
  handler: async (args: { object_type?: string; object_id: string; limit?: number }) => {
    try {
      const result = await fetchOCS<Activity[]>(
        '/ocs/v2.php/apps/activity/api/v2/activity/filter',
        {
          queryParams: {
            object_type: args.object_type ?? 'files',
            object_id: args.object_id,
            limit: String(args.limit ?? 50),
          },
        }
      );

      const activities = result.ocs.data;
      if (activities.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No activity for this object.' }],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Activity (${activities.length}):\n${formatActivities(activities)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting object activity: ${error instanceof Error ? error.message : String(error)}`,
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

export const activityTools = [listActivityTool, getObjectActivityTool];
