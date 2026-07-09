// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Announcement Center Tools
 * Read and manage org-wide announcements via the Announcement Center OCS API.
 */

interface Announcement {
  id: number;
  author_id: string;
  author: string;
  time: number;
  subject: string;
  message: string;
  groups: Array<{ id: string; name: string }> | null;
  comments: number | false;
  schedule_time: number | null;
  delete_time: number | null;
}

function formatTime(ts: number | null): string {
  if (!ts) return '';
  return new Date(ts * 1000).toISOString();
}

function formatAnnouncements(items: Announcement[]): string {
  return items
    .map((a) => {
      const time = a.time ? ` (${formatTime(a.time)})` : '';
      const schedule = a.schedule_time ? `\n  scheduled: ${formatTime(a.schedule_time)}` : '';
      const del = a.delete_time ? `\n  deletes: ${formatTime(a.delete_time)}` : '';
      return `- #${a.id} [${a.author}] ${a.subject}${time}${schedule}${del}`;
    })
    .join('\n');
}

// ---------------------------------------------------------------------------
// list_announcements
// ---------------------------------------------------------------------------

export const listAnnouncementsTool = {
  name: 'list_announcements',
  title: 'List Announcements',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List announcements from the Nextcloud Announcement Center (org-wide notices such as ' +
    'maintenance windows, events, or news). Returns newest first.',
  inputSchema: z.object({
    offset: z
      .number()
      .optional()
      .describe('Return announcements before this offset (for pagination, default 0)'),
  }),
  handler: async (args: { offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {
        offset: String(args.offset ?? 0),
      };

      const result = await fetchOCS<Announcement[]>(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        { queryParams }
      );

      const items = result.ocs.data;
      if (items.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No announcements.' }],
        };
      }

      const lastId = items[items.length - 1].id;
      return {
        content: [
          {
            type: 'text' as const,
            text:
              `Announcements (${items.length}):\n${formatAnnouncements(items)}\n\n` +
              `Last id: ${lastId} (pass as 'offset' to page further).`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing announcements: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// create_announcement
// ---------------------------------------------------------------------------

export const createAnnouncementTool = {
  name: 'create_announcement',
  title: 'Create Announcement',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new announcement in the Announcement Center. This is visible to all or ' +
    'selected groups and can trigger notifications/emails — use deliberately. Requires the ' +
    'configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    subject: z.string().describe('The announcement title (kept short)'),
    message: z.string().describe('The announcement body (Markdown supported)'),
    plainMessage: z
      .string()
      .optional()
      .describe('Plain-text version of the message (defaults to message)'),
    groups: z
      .array(z.string())
      .optional()
      .describe("Group IDs to target, or ['everyone'] for all users (default)"),
    activities: z.boolean().optional().describe('Publish to the activity feed (default true)'),
    notifications: z.boolean().optional().describe('Send notifications (default true)'),
    emails: z.boolean().optional().describe('Send emails (default false)'),
    comments: z.boolean().optional().describe('Allow comments (default true)'),
    scheduleTime: z
      .number()
      .optional()
      .describe('Unix timestamp (seconds) to publish the announcement later'),
    deleteTime: z
      .number()
      .optional()
      .describe('Unix timestamp (seconds) to automatically delete the announcement'),
  }),
  handler: async (args: {
    subject: string;
    message: string;
    plainMessage?: string;
    groups?: string[];
    activities?: boolean;
    notifications?: boolean;
    emails?: boolean;
    comments?: boolean;
    scheduleTime?: number;
    deleteTime?: number;
  }) => {
    try {
      const jsonBody: Record<string, unknown> = {
        subject: args.subject,
        message: args.message,
        plainMessage: args.plainMessage ?? args.message,
        groups: args.groups ?? ['everyone'],
        activities: args.activities ?? true,
        notifications: args.notifications ?? true,
        emails: args.emails ?? false,
        comments: args.comments ?? true,
      };
      if (args.scheduleTime !== undefined) jsonBody.scheduleTime = args.scheduleTime;
      if (args.deleteTime !== undefined) jsonBody.deleteTime = args.deleteTime;

      const result = await fetchOCS<Announcement>(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        { method: 'POST', jsonBody }
      );

      const created = result.ocs.data;
      return {
        content: [
          {
            type: 'text' as const,
            text: `Created announcement #${created.id}: "${created.subject}"`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating announcement: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// delete_announcement
// ---------------------------------------------------------------------------

export const deleteAnnouncementTool = {
  name: 'delete_announcement',
  title: 'Delete Announcement',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete an announcement by its ID. Requires the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    id: z.number().describe('The announcement ID to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchOCS(`/ocs/v2.php/apps/announcementcenter/api/v1/announcements/${args.id}`, {
        method: 'DELETE',
      });

      return {
        content: [{ type: 'text' as const, text: `Deleted announcement #${args.id}.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting announcement: ${error instanceof Error ? error.message : String(error)}`,
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

export const announcementTools = [
  listAnnouncementsTool,
  createAnnouncementTool,
  deleteAnnouncementTool,
];
