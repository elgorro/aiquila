import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('Notification Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_notifications', () => {
    it('should list notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              notification_id: 1,
              app: 'files_sharing',
              subject: 'Alice shared doc.pdf with you',
              message: '',
              datetime: '2026-03-24T12:00:00+00:00',
            },
          ],
        },
      });

      const { listNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await listNotificationsTool.handler();

      expect(result.content[0].text).toContain('Alice shared doc.pdf');
    });

    it('should handle empty notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await listNotificationsTool.handler();

      expect(result.content[0].text).toContain('No notifications');
    });
  });

  describe('get_notification', () => {
    it('should get notification details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { notification_id: 1, app: 'files', subject: 'Test' },
        },
      });

      const { getNotificationTool } = await import('../tools/apps/notifications.js');
      const result = await getNotificationTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Test');
    });
  });

  describe('mark_notification_read', () => {
    it('should mark notification as read', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { markNotificationReadTool } = await import('../tools/apps/notifications.js');
      const result = await markNotificationReadTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('marked as read');
    });
  });

  describe('delete_all_notifications', () => {
    it('should delete all notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { deleteAllNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await deleteAllNotificationsTool.handler();

      expect(result.content[0].text).toContain('All notifications deleted');
    });
  });
});
