// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('Group Management Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_groups', () => {
    it('should return list of groups', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { groups: ['admin', 'users', 'marketing'] },
        },
      });

      const { listGroupsTool } = await import('../tools/apps/groups.js');
      const result = await listGroupsTool.handler({});

      expect(result.content[0].text).toContain('admin');
      expect(result.content[0].text).toContain('marketing');
      expect(result.content[0].text).toContain('3');
    });
  });

  describe('get_group_info', () => {
    it('should return group members', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['alice', 'bob'] },
        },
      });

      const { getGroupInfoTool } = await import('../tools/apps/groups.js');
      const result = await getGroupInfoTool.handler({ groupId: 'admin' });

      expect(result.content[0].text).toContain('alice');
      expect(result.content[0].text).toContain('bob');
      expect(result.content[0].text).toContain('admin');
    });
  });

  describe('add_user_to_group', () => {
    it('should add user to group successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { addUserToGroupTool } = await import('../tools/apps/groups.js');
      const result = await addUserToGroupTool.handler({ userId: 'alice', groupId: 'marketing' });

      expect(result.content[0].text).toContain('added');
      expect(result.content[0].text).toContain('marketing');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/groups', {
        method: 'POST',
        body: { groupid: 'marketing' },
      });
    });
  });

  describe('remove_user_from_group', () => {
    it('should remove user from group successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { removeUserFromGroupTool } = await import('../tools/apps/groups.js');
      const result = await removeUserFromGroupTool.handler({
        userId: 'alice',
        groupId: 'marketing',
      });

      expect(result.content[0].text).toContain('removed');
      expect(result.content[0].text).toContain('marketing');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/groups', {
        method: 'DELETE',
        body: { groupid: 'marketing' },
      });
    });
  });
});
