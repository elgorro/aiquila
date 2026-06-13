// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const sampleActivity = {
  activity_id: 42,
  app: 'files',
  type: 'file_created',
  user: 'admin',
  subject: 'You created document.pdf',
  message: '',
  object_type: 'files',
  object_id: 123,
  object_name: 'document.pdf',
  link: 'https://cloud.example.com/f/123',
  datetime: '2026-06-13T12:00:00+00:00',
};

describe('Activity Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_activity', () => {
    it('should list activities and report the last activity_id', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [sampleActivity] },
      });

      const { listActivityTool } = await import('../tools/apps/activity.js');
      const result = await listActivityTool.handler({});

      expect(result.content[0].text).toContain('You created document.pdf');
      expect(result.content[0].text).toContain('Last activity_id: 42');
    });

    it('should default to the "all" feed with limit 50 / sort desc', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [sampleActivity] },
      });

      const { listActivityTool } = await import('../tools/apps/activity.js');
      await listActivityTool.handler({});

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/activity/api/v2/activity/all', {
        queryParams: { limit: '50', sort: 'desc' },
      });
    });

    it('should pass filter, limit, sort and since through', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [sampleActivity] },
      });

      const { listActivityTool } = await import('../tools/apps/activity.js');
      await listActivityTool.handler({ filter: 'self', limit: 10, since: 100, sort: 'asc' });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/activity/api/v2/activity/self', {
        queryParams: { limit: '10', sort: 'asc', since: '100' },
      });
    });

    it('should handle an empty feed', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listActivityTool } = await import('../tools/apps/activity.js');
      const result = await listActivityTool.handler({});

      expect(result.content[0].text).toContain('No activity');
    });

    it('should set isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('500 Internal Server Error'));

      const { listActivityTool } = await import('../tools/apps/activity.js');
      const result = await listActivityTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('get_object_activity', () => {
    it('should query the filter path with object params', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [sampleActivity] },
      });

      const { getObjectActivityTool } = await import('../tools/apps/activity.js');
      const result = await getObjectActivityTool.handler({ object_id: '123' });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/activity/api/v2/activity/filter',
        { queryParams: { object_type: 'files', object_id: '123', limit: '50' } }
      );
      expect(result.content[0].text).toContain('document.pdf');
    });

    it('should report no activity for an object with none', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { getObjectActivityTool } = await import('../tools/apps/activity.js');
      const result = await getObjectActivityTool.handler({ object_id: '999' });

      expect(result.content[0].text).toContain('No activity for this object');
    });
  });
});
