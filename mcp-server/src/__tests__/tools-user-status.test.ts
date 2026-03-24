import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('User Status & Absence Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('get_user_status', () => {
    it('should return current user status', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            userId: 'admin',
            status: 'online',
            icon: '☕',
            message: 'Coffee break',
            clearAt: null,
          },
        },
      });

      const { getUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await getUserStatusTool.handler({});

      expect(result.content[0].text).toContain('online');
      expect(result.content[0].text).toContain('Coffee break');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('API error'));

      const { getUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await getUserStatusTool.handler({});

      expect(result.isError).toBe(true);
    });
  });

  describe('set_user_status', () => {
    it('should set status type', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await setUserStatusTool.handler({ statusType: 'dnd' });

      expect(result.content[0].text).toContain('dnd');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/user_status/api/v1/user_status/status',
        expect.objectContaining({ method: 'PUT', body: { statusType: 'dnd' } })
      );
    });
  });

  describe('set_user_status_message', () => {
    it('should set custom message with icon', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setUserStatusMessageTool } = await import('../tools/apps/user-status.js');
      const result = await setUserStatusMessageTool.handler({
        message: 'In a meeting',
        statusIcon: '📅',
      });

      expect(result.content[0].text).toContain('📅');
      expect(result.content[0].text).toContain('In a meeting');
    });
  });

  describe('clear_user_status_message', () => {
    it('should clear status message', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { clearUserStatusMessageTool } = await import('../tools/apps/user-status.js');
      const result = await clearUserStatusMessageTool.handler();

      expect(result.content[0].text).toContain('cleared');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/user_status/api/v1/user_status/message',
        expect.objectContaining({ method: 'DELETE' })
      );
    });
  });

  describe('list_user_statuses', () => {
    it('should list all user statuses', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            { userId: 'alice', status: 'online', icon: null, message: null },
            { userId: 'bob', status: 'away', icon: '🏠', message: 'Working from home' },
          ],
        },
      });

      const { listUserStatusesTool } = await import('../tools/apps/user-status.js');
      const result = await listUserStatusesTool.handler({});

      expect(result.content[0].text).toContain('alice: online');
      expect(result.content[0].text).toContain('bob: away');
      expect(result.content[0].text).toContain('Working from home');
    });

    it('should handle empty statuses', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listUserStatusesTool } = await import('../tools/apps/user-status.js');
      const result = await listUserStatusesTool.handler({});

      expect(result.content[0].text).toContain('No user statuses found');
    });
  });

  // ─── Absence / Out-of-Office ──────────────────────────────────────────

  describe('get_out_of_office', () => {
    it('should return absence status', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: '1',
            firstDay: '2026-04-01',
            lastDay: '2026-04-10',
            status: 'Vacation',
            message: 'On holiday',
          },
        },
      });

      const { getOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await getOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('Vacation');
      expect(result.content[0].text).toContain('2026-04-01');
    });

    it('should handle 404 gracefully', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { getOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await getOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('No out-of-office status set');
      expect(result).not.toHaveProperty('isError');
    });
  });

  describe('set_out_of_office', () => {
    it('should set absence period', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await setOutOfOfficeTool.handler({
        firstDay: '2026-04-01',
        lastDay: '2026-04-10',
        status: 'Vacation',
        message: 'On holiday',
      });

      expect(result.content[0].text).toContain('Out-of-office set');
      expect(result.content[0].text).toContain('2026-04-01');
      expect(result.content[0].text).toContain('2026-04-10');
    });
  });

  describe('clear_out_of_office', () => {
    it('should clear absence', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { clearOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await clearOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('cleared');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        expect.stringContaining('/outOfOffice/'),
        expect.objectContaining({ method: 'DELETE' })
      );
    });
  });
});
