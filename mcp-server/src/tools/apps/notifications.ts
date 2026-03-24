import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Notification Tools
 * List, read, and manage notifications via OCS Notifications API
 */

interface Notification {
  notification_id: number;
  app: string;
  user: string;
  datetime: string;
  object_type: string;
  object_id: string;
  subject: string;
  message: string;
  link: string;
  actions: unknown[];
}

// ---------------------------------------------------------------------------
// list_notifications
// ---------------------------------------------------------------------------

export const listNotificationsTool = {
  name: 'list_notifications',
  description: 'List all notifications for the current user',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchOCS<Notification[]>(
        '/ocs/v2.php/apps/notifications/api/v2/notifications'
      );

      const notifications = result.ocs.data;
      if (notifications.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No notifications.' }],
        };
      }

      const formatted = notifications.map((n) => {
        const time = n.datetime ? ` (${n.datetime})` : '';
        const msg = n.message ? `\n  ${n.message}` : '';
        return `- [${n.app}] ${n.subject}${time}${msg}`;
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Notifications (${notifications.length}):\n${formatted.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing notifications: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// get_notification
// ---------------------------------------------------------------------------

export const getNotificationTool = {
  name: 'get_notification',
  description: 'Get details of a specific notification by ID',
  inputSchema: z.object({
    id: z.number().describe('The notification ID'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const result = await fetchOCS<Notification>(
        `/ocs/v2.php/apps/notifications/api/v2/notifications/${args.id}`
      );

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result.ocs.data, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting notification: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// mark_notification_read
// ---------------------------------------------------------------------------

export const markNotificationReadTool = {
  name: 'mark_notification_read',
  description: 'Mark a notification as read (deletes it)',
  inputSchema: z.object({
    id: z.number().describe('The notification ID to mark as read'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchOCS(`/ocs/v2.php/apps/notifications/api/v2/notifications/${args.id}`, {
        method: 'DELETE',
      });

      return {
        content: [{ type: 'text' as const, text: `Notification ${args.id} marked as read.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error marking notification: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// delete_all_notifications
// ---------------------------------------------------------------------------

export const deleteAllNotificationsTool = {
  name: 'delete_all_notifications',
  description: 'Delete all notifications for the current user',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      await fetchOCS('/ocs/v2.php/apps/notifications/api/v2/notifications', {
        method: 'DELETE',
      });

      return {
        content: [{ type: 'text' as const, text: 'All notifications deleted.' }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting notifications: ${error instanceof Error ? error.message : String(error)}`,
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

export const notificationsTools = [
  listNotificationsTool,
  getNotificationTool,
  markNotificationReadTool,
  deleteAllNotificationsTool,
];
