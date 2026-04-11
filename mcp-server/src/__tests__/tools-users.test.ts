// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('User Management Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_users', () => {
    it('should return list of users', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['admin', 'alice', 'bob'] },
        },
      });

      const { listUsersTool } = await import('../tools/apps/users.js');
      const result = await listUsersTool.handler({});

      expect(result.content[0].text).toContain('admin');
      expect(result.content[0].text).toContain('alice');
      expect(result.content[0].text).toContain('3');
    });

    it('should pass search and pagination params', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['alice'] },
        },
      });

      const { listUsersTool } = await import('../tools/apps/users.js');
      await listUsersTool.handler({ search: 'ali', limit: 10, offset: 0 });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users', {
        queryParams: { search: 'ali', limit: '10', offset: '0' },
      });
    });
  });

  describe('get_user_info', () => {
    it('should return user details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 'alice',
            displayname: 'Alice Smith',
            email: 'alice@example.com',
            enabled: true,
            groups: ['admin', 'users'],
          },
        },
      });

      const { getUserInfoTool } = await import('../tools/apps/users.js');
      const result = await getUserInfoTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('Alice Smith');
      expect(result.content[0].text).toContain('alice@example.com');
    });

    it('should handle nonexistent user', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { getUserInfoTool } = await import('../tools/apps/users.js');
      const result = await getUserInfoTool.handler({ userId: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('enable_user', () => {
    it('should enable a user successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { enableUserTool } = await import('../tools/apps/users.js');
      const result = await enableUserTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('enabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/enable', {
        method: 'PUT',
      });
    });
  });

  describe('disable_user', () => {
    it('should disable a user successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { disableUserTool } = await import('../tools/apps/users.js');
      const result = await disableUserTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('disabled');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/disable', {
        method: 'PUT',
      });
    });
  });
});
