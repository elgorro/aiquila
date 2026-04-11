// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const ocsWrap = <T>(data: T) => ({
  ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data },
});

describe('Circles (Teams) Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // -------------------------------------------------------------------------
  // circles_list
  // -------------------------------------------------------------------------

  describe('circles_list', () => {
    it('should return formatted list of circles', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap([
          {
            id: 'abc123',
            name: 'Engineering',
            displayName: 'Engineering',
            description: '',
            config: 8,
            owner: { userId: 'admin', displayName: 'Admin' },
          },
          {
            id: 'def456',
            name: 'Marketing',
            displayName: 'Marketing',
            description: '',
            config: 24,
            owner: { userId: 'alice', displayName: 'Alice' },
          },
        ])
      );

      const { circlesListTool } = await import('../tools/apps/circles.js');
      const result = await circlesListTool.handler({});

      expect(result.content[0].text).toContain('Circles (2)');
      expect(result.content[0].text).toContain('[abc123] Engineering');
      expect(result.content[0].text).toContain('[def456] Marketing');
      expect(result.content[0].text).toContain('Admin');
    });

    it('should pass limit and offset as query params', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap([]));

      const { circlesListTool } = await import('../tools/apps/circles.js');
      await circlesListTool.handler({ limit: 5, offset: 10 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/circles/circles',
        expect.objectContaining({
          queryParams: { limit: '5', offset: '10' },
        })
      );
    });

    it('should handle empty list', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap([]));

      const { circlesListTool } = await import('../tools/apps/circles.js');
      const result = await circlesListTool.handler({});

      expect(result.content[0].text).toBe('No circles found.');
    });

    it('should handle API error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Network error'));

      const { circlesListTool } = await import('../tools/apps/circles.js');
      const result = await circlesListTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing circles');
    });
  });

  // -------------------------------------------------------------------------
  // circles_get
  // -------------------------------------------------------------------------

  describe('circles_get', () => {
    it('should return circle details with config flags', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap({
          id: 'abc123',
          name: 'Engineering',
          displayName: 'Engineering',
          description: 'The engineering team',
          config: 24, // visible + open
          owner: { userId: 'admin', displayName: 'Admin' },
          members: [
            {
              id: 'mem1',
              circleId: 'abc123',
              userId: 'admin',
              displayName: 'Admin',
              userType: 1,
              level: 9,
              status: 'Member',
            },
          ],
        })
      );

      const { circlesGetTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetTool.handler({ circleId: 'abc123' });

      expect(result.content[0].text).toContain('# Engineering');
      expect(result.content[0].text).toContain('ID: abc123');
      expect(result.content[0].text).toContain('Owner: Admin');
      expect(result.content[0].text).toContain('visible, open');
      expect(result.content[0].text).toContain('The engineering team');
      expect(result.content[0].text).toContain('Members (1)');
      expect(result.content[0].text).toContain('[mem1] Admin');
    });

    it('should encode circleId in URL', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap({
          id: 'a/b',
          name: 'Test',
          displayName: 'Test',
          description: '',
          config: 0,
          owner: { userId: 'admin', displayName: 'Admin' },
        })
      );

      const { circlesGetTool } = await import('../tools/apps/circles.js');
      await circlesGetTool.handler({ circleId: 'a/b' });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles/a%2Fb');
    });

    it('should handle not-found error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { circlesGetTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetTool.handler({ circleId: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error getting circle');
    });
  });

  // -------------------------------------------------------------------------
  // circles_create
  // -------------------------------------------------------------------------

  describe('circles_create', () => {
    it('should create a circle with name only', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap({
          id: 'new123',
          name: 'DevOps',
          displayName: 'DevOps',
          description: '',
          config: 0,
          owner: { userId: 'admin', displayName: 'Admin' },
        })
      );

      const { circlesCreateTool } = await import('../tools/apps/circles.js');
      const result = await circlesCreateTool.handler({ name: 'DevOps' });

      expect(result.content[0].text).toContain('Circle created');
      expect(result.content[0].text).toContain('[new123] DevOps');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles', {
        method: 'POST',
        jsonBody: { name: 'DevOps' },
      });
    });

    it('should pass personal and local flags', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap({
          id: 'p123',
          name: 'My Circle',
          displayName: 'My Circle',
          description: '',
          config: 2,
          owner: { userId: 'admin', displayName: 'Admin' },
        })
      );

      const { circlesCreateTool } = await import('../tools/apps/circles.js');
      await circlesCreateTool.handler({ name: 'My Circle', personal: true, local: true });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles', {
        method: 'POST',
        jsonBody: { name: 'My Circle', personal: 1, local: 1 },
      });
    });

    it('should handle creation error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Circle name already exists'));

      const { circlesCreateTool } = await import('../tools/apps/circles.js');
      const result = await circlesCreateTool.handler({ name: 'Existing' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating circle');
    });
  });

  // -------------------------------------------------------------------------
  // circles_delete
  // -------------------------------------------------------------------------

  describe('circles_delete', () => {
    it('should delete a circle', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap({}));

      const { circlesDeleteTool } = await import('../tools/apps/circles.js');
      const result = await circlesDeleteTool.handler({ circleId: 'abc123' });

      expect(result.content[0].text).toContain('deleted');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles/abc123', {
        method: 'DELETE',
      });
    });

    it('should handle delete error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Permission denied'));

      const { circlesDeleteTool } = await import('../tools/apps/circles.js');
      const result = await circlesDeleteTool.handler({ circleId: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting circle');
    });
  });

  // -------------------------------------------------------------------------
  // circles_list_members
  // -------------------------------------------------------------------------

  describe('circles_list_members', () => {
    it('should return formatted member list', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap([
          {
            id: 'mem1',
            circleId: 'abc123',
            userId: 'admin',
            displayName: 'Admin',
            userType: 1,
            level: 9,
            status: 'Member',
          },
          {
            id: 'mem2',
            circleId: 'abc123',
            userId: 'alice',
            displayName: 'Alice',
            userType: 1,
            level: 1,
            status: 'Member',
          },
        ])
      );

      const { circlesListMembersTool } = await import('../tools/apps/circles.js');
      const result = await circlesListMembersTool.handler({ circleId: 'abc123' });

      expect(result.content[0].text).toContain('Members (2)');
      expect(result.content[0].text).toContain('[mem1] Admin');
      expect(result.content[0].text).toContain('owner');
      expect(result.content[0].text).toContain('[mem2] Alice');
      expect(result.content[0].text).toContain('member');
    });

    it('should handle empty member list', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap([]));

      const { circlesListMembersTool } = await import('../tools/apps/circles.js');
      const result = await circlesListMembersTool.handler({ circleId: 'abc123' });

      expect(result.content[0].text).toBe('No members found in this circle.');
    });
  });

  // -------------------------------------------------------------------------
  // circles_add_member
  // -------------------------------------------------------------------------

  describe('circles_add_member', () => {
    it('should add a user member with default type', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap({}));

      const { circlesAddMemberTool } = await import('../tools/apps/circles.js');
      const result = await circlesAddMemberTool.handler({
        circleId: 'abc123',
        userId: 'alice',
      });

      expect(result.content[0].text).toContain('Added user "alice"');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles/abc123/members', {
        method: 'POST',
        jsonBody: { userId: 'alice', type: 1 },
      });
    });

    it('should add a group member with explicit type', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap({}));

      const { circlesAddMemberTool } = await import('../tools/apps/circles.js');
      const result = await circlesAddMemberTool.handler({
        circleId: 'abc123',
        userId: 'developers',
        type: 2,
      });

      expect(result.content[0].text).toContain('Added group "developers"');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/circles/abc123/members', {
        method: 'POST',
        jsonBody: { userId: 'developers', type: 2 },
      });
    });

    it('should handle add member error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('User already a member'));

      const { circlesAddMemberTool } = await import('../tools/apps/circles.js');
      const result = await circlesAddMemberTool.handler({
        circleId: 'abc123',
        userId: 'alice',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error adding member');
    });
  });

  // -------------------------------------------------------------------------
  // circles_remove_member
  // -------------------------------------------------------------------------

  describe('circles_remove_member', () => {
    it('should remove a member', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap({}));

      const { circlesRemoveMemberTool } = await import('../tools/apps/circles.js');
      const result = await circlesRemoveMemberTool.handler({
        circleId: 'abc123',
        memberId: 'mem2',
      });

      expect(result.content[0].text).toContain('removed');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/circles/circles/abc123/members/mem2',
        { method: 'DELETE' }
      );
    });

    it('should handle remove member error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Insufficient permissions'));

      const { circlesRemoveMemberTool } = await import('../tools/apps/circles.js');
      const result = await circlesRemoveMemberTool.handler({
        circleId: 'abc123',
        memberId: 'mem1',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing member');
    });
  });

  // -------------------------------------------------------------------------
  // circles_search
  // -------------------------------------------------------------------------

  describe('circles_search', () => {
    it('should return matching circles', async () => {
      mockFetchOCS.mockResolvedValue(
        ocsWrap([
          {
            id: 'abc123',
            name: 'Engineering',
            displayName: 'Engineering',
            description: '',
            config: 8,
            owner: { userId: 'admin', displayName: 'Admin' },
          },
        ])
      );

      const { circlesSearchTool } = await import('../tools/apps/circles.js');
      const result = await circlesSearchTool.handler({ term: 'Eng' });

      expect(result.content[0].text).toContain('Search results (1)');
      expect(result.content[0].text).toContain('[abc123] Engineering');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/circles/search', {
        queryParams: { term: 'Eng' },
      });
    });

    it('should handle no results', async () => {
      mockFetchOCS.mockResolvedValue(ocsWrap([]));

      const { circlesSearchTool } = await import('../tools/apps/circles.js');
      const result = await circlesSearchTool.handler({ term: 'nonexistent' });

      expect(result.content[0].text).toContain('No circles found matching');
    });

    it('should handle search error', async () => {
      mockFetchOCS.mockRejectedValue(new Error('API error'));

      const { circlesSearchTool } = await import('../tools/apps/circles.js');
      const result = await circlesSearchTool.handler({ term: 'test' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error searching circles');
    });
  });
});
