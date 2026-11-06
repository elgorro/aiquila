// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn(), trace: vi.fn() },
}));

import { _resetCapabilitiesCache } from '../client/capabilities.js';

const ocsWrap = <T>(data: T) => ({
  ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data },
});

describe('Circles (Teams) Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
    _resetCapabilitiesCache();
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

    it('should fall back to the circle list when the details route answers 403', async () => {
      // circles ships GET /circles/{circleId} without #[NoAdminRequired]
      mockFetchOCS
        .mockRejectedValueOnce(new Error('Permission denied. This operation requires admin'))
        .mockResolvedValueOnce(
          ocsWrap([
            { id: 'other', name: 'Other', config: 0 },
            { id: 'abc123', name: 'Mine', displayName: 'Mine', config: 0 },
          ])
        );

      const { circlesGetTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetTool.handler({ circleId: 'abc123' });

      expect(mockFetchOCS).toHaveBeenLastCalledWith('/ocs/v2.php/apps/circles/circles');
      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('# Mine');
    });

    it('should keep the 403 when the circle is not in the list either', async () => {
      mockFetchOCS
        .mockRejectedValueOnce(new Error('Permission denied. This operation requires admin'))
        .mockResolvedValueOnce(ocsWrap([]));

      const { circlesGetTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetTool.handler({ circleId: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
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

  // -------------------------------------------------------------------------
  // workspaces
  // -------------------------------------------------------------------------

  describe('workspaces', () => {
    const NC35 = ocsWrap({ version: { major: 35 }, capabilities: {} });
    const NC34 = ocsWrap({ version: { major: 34 }, capabilities: {} });

    function serve(version: unknown, answer: unknown = {}) {
      mockFetchOCS.mockImplementation(async (path: string) =>
        path.includes('/cloud/capabilities') ? version : ocsWrap(answer)
      );
    }

    const writes = () =>
      mockFetchOCS.mock.calls.filter(([path]) => !String(path).includes('/cloud/capabilities'));

    it('shows the folder and resources, but not the folder as its own resource', async () => {
      mockFetchOCS.mockImplementation(async (path: string) =>
        path.endsWith('/resources')
          ? ocsWrap({
              resources: [
                {
                  id: '1',
                  label: 'Space',
                  url: 'u',
                  provider: { id: 'groupfolders', name: 'Team spaces' },
                },
                { id: '5', label: 'Board', url: 'u5', provider: { id: 'deck', name: 'Deck' } },
              ],
            })
          : ocsWrap({ id: 1, mountPoint: 'Space', quota: null })
      );

      const { circlesGetWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetWorkspaceTool.handler({ circleId: 'c1' });

      expect(result.content[0].text).toContain('Workspace: /Space (folder 1)');
      expect(result.content[0].text).toContain('[Deck] Board (id: 5)');
      expect(result.content[0].text).not.toContain('Team spaces');
    });

    it('still lists resources without a workspace', async () => {
      mockFetchOCS.mockImplementation(async (path: string) => {
        if (path.endsWith('/folder')) throw new Error('OCS API error: 404 Not Found');
        return ocsWrap({ resources: [] });
      });

      const { circlesGetWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetWorkspaceTool.handler({ circleId: 'c1' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('Workspace: none');
    });

    it('reports other failures reading the workspace', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 500 boom'));

      const { circlesGetWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesGetWorkspaceTool.handler({ circleId: 'c1' });

      expect(result.isError).toBe(true);
    });

    it('creates a workspace', async () => {
      serve(NC35, { folder: { id: 2, mountPoint: 'Space' } });

      const { circlesCreateWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesCreateWorkspaceTool.handler({ circleId: 'c1', name: 'Space' });

      expect(writes()[0]).toEqual([
        '/ocs/v2.php/apps/circles/teams/c1/folder',
        { method: 'POST', jsonBody: { name: 'Space' } },
      ]);
      expect(result.content[0].text).toContain('/Space');
    });

    it('sets the quota', async () => {
      serve(NC35);

      const { circlesSetWorkspaceQuotaTool } = await import('../tools/apps/circles.js');
      await circlesSetWorkspaceQuotaTool.handler({ circleId: 'c1', quota: 1024 });

      expect(writes()[0]).toEqual([
        '/ocs/v2.php/apps/circles/teams/c1/folder/quota',
        { method: 'PUT', jsonBody: { quota: 1024 } },
      ]);
    });

    it('keeps the folder unless told to delete it', async () => {
      serve(NC35);

      const { circlesRemoveWorkspaceTool } = await import('../tools/apps/circles.js');
      const kept = await circlesRemoveWorkspaceTool.handler({ circleId: 'c1' });
      const gone = await circlesRemoveWorkspaceTool.handler({ circleId: 'c1', deleteFolder: true });

      expect(writes()[0][1]).toEqual({ method: 'DELETE', jsonBody: { deleteFolder: false } });
      expect(writes()[1][1]).toEqual({ method: 'DELETE', jsonBody: { deleteFolder: true } });
      expect(kept.content[0].text).toContain('kept');
      expect(gone.content[0].text).toContain('deleted');
    });

    it('links an existing folder instead of creating one', async () => {
      serve(NC35, { folder: { id: 4, mountPoint: 'Old' } });

      const { circlesCreateWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesCreateWorkspaceTool.handler({ circleId: 'c1', folderId: 4 });

      expect(writes()[0]).toEqual([
        '/ocs/v2.php/apps/circles/teams/c1/folder/link',
        { method: 'POST', jsonBody: { folderId: 4 } },
      ]);
      expect(result.content[0].text).toContain('/Old (folder 4)');
    });

    it('refuses a name for a folder that already exists', async () => {
      serve(NC35);

      const { circlesCreateWorkspaceTool } = await import('../tools/apps/circles.js');
      const result = await circlesCreateWorkspaceTool.handler({
        circleId: 'c1',
        name: 'New',
        folderId: 4,
      });

      expect(result.isError).toBe(true);
    });

    it('lists folders a team can link', async () => {
      serve(NC35, [{ id: 4, mountPoint: 'Old' }]);

      const { circlesListLinkableFoldersTool } = await import('../tools/apps/circles.js');
      const result = await circlesListLinkableFoldersTool.handler({ circleId: 'c1' });

      expect(writes()[0]).toEqual(['/ocs/v2.php/apps/circles/teams/c1/folder/linkable']);
      expect(result.content[0].text).toContain('/Old (folder 4)');
    });

    it('refuses to change workspaces before Nextcloud 35', async () => {
      serve(NC34);

      const tools = await import('../tools/apps/circles.js');
      const results = [
        await tools.circlesCreateWorkspaceTool.handler({ circleId: 'c1' }),
        await tools.circlesSetWorkspaceQuotaTool.handler({ circleId: 'c1', quota: 1 }),
        await tools.circlesRemoveWorkspaceTool.handler({ circleId: 'c1' }),
      ];

      for (const result of results) {
        expect(result.isError).toBe(true);
        expect(result.content[0].text).toContain('Nextcloud 35');
      }
      expect(writes()).toEqual([]);
    });
  });
});
