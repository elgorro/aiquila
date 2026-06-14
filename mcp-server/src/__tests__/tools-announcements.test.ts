// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const sampleAnnouncement = {
  id: 7,
  author_id: 'admin',
  author: 'Administrator',
  time: 1_700_000_000,
  subject: 'Maintenance window',
  message: 'We will be down on Sunday.',
  groups: [{ id: 'everyone', name: 'everyone' }],
  comments: 0,
  schedule_time: null,
  delete_time: null,
};

function ok(data: unknown) {
  return { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
}

describe('Announcement Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_announcements', () => {
    it('should list announcements and report the last id', async () => {
      mockFetchOCS.mockResolvedValue(ok([sampleAnnouncement]));

      const { listAnnouncementsTool } = await import('../tools/apps/announcements.js');
      const result = await listAnnouncementsTool.handler({});

      expect(result.content[0].text).toContain('Maintenance window');
      expect(result.content[0].text).toContain('Last id: 7');
    });

    it('should default offset to 0', async () => {
      mockFetchOCS.mockResolvedValue(ok([sampleAnnouncement]));

      const { listAnnouncementsTool } = await import('../tools/apps/announcements.js');
      await listAnnouncementsTool.handler({});

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        { queryParams: { offset: '0' } }
      );
    });

    it('should pass offset through', async () => {
      mockFetchOCS.mockResolvedValue(ok([sampleAnnouncement]));

      const { listAnnouncementsTool } = await import('../tools/apps/announcements.js');
      await listAnnouncementsTool.handler({ offset: 5 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        { queryParams: { offset: '5' } }
      );
    });

    it('should handle empty results', async () => {
      mockFetchOCS.mockResolvedValue(ok([]));

      const { listAnnouncementsTool } = await import('../tools/apps/announcements.js');
      const result = await listAnnouncementsTool.handler({});

      expect(result.content[0].text).toBe('No announcements.');
    });

    it('should report errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { listAnnouncementsTool } = await import('../tools/apps/announcements.js');
      const result = await listAnnouncementsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });

  describe('create_announcement', () => {
    it('should POST with sensible defaults (everyone, plainMessage fallback)', async () => {
      mockFetchOCS.mockResolvedValue(ok(sampleAnnouncement));

      const { createAnnouncementTool } = await import('../tools/apps/announcements.js');
      const result = await createAnnouncementTool.handler({
        subject: 'Maintenance window',
        message: 'We will be down on Sunday.',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        {
          method: 'POST',
          jsonBody: {
            subject: 'Maintenance window',
            message: 'We will be down on Sunday.',
            plainMessage: 'We will be down on Sunday.',
            groups: ['everyone'],
            activities: true,
            notifications: true,
            emails: false,
            comments: true,
          },
        }
      );
      expect(result.content[0].text).toContain('Created announcement #7');
    });

    it('should pass explicit fields including schedule/delete times', async () => {
      mockFetchOCS.mockResolvedValue(ok(sampleAnnouncement));

      const { createAnnouncementTool } = await import('../tools/apps/announcements.js');
      await createAnnouncementTool.handler({
        subject: 'Hi',
        message: 'msg',
        plainMessage: 'plain',
        groups: ['staff'],
        activities: false,
        notifications: false,
        emails: true,
        comments: false,
        scheduleTime: 100,
        deleteTime: 200,
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements',
        {
          method: 'POST',
          jsonBody: {
            subject: 'Hi',
            message: 'msg',
            plainMessage: 'plain',
            groups: ['staff'],
            activities: false,
            notifications: false,
            emails: true,
            comments: false,
            scheduleTime: 100,
            deleteTime: 200,
          },
        }
      );
    });

    it('should report errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('forbidden'));

      const { createAnnouncementTool } = await import('../tools/apps/announcements.js');
      const result = await createAnnouncementTool.handler({ subject: 's', message: 'm' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('forbidden');
    });
  });

  describe('delete_announcement', () => {
    it('should DELETE the announcement by id', async () => {
      mockFetchOCS.mockResolvedValue(ok(null));

      const { deleteAnnouncementTool } = await import('../tools/apps/announcements.js');
      const result = await deleteAnnouncementTool.handler({ id: 7 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/announcementcenter/api/v1/announcements/7',
        { method: 'DELETE' }
      );
      expect(result.content[0].text).toContain('Deleted announcement #7');
    });

    it('should report errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('nope'));

      const { deleteAnnouncementTool } = await import('../tools/apps/announcements.js');
      const result = await deleteAnnouncementTool.handler({ id: 7 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('nope');
    });
  });
});
