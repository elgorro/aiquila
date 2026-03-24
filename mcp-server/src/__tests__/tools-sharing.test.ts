import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('Share Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_shares', () => {
    it('should return formatted shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 1,
              share_type: 0,
              uid_owner: 'alice',
              displayname_owner: 'Alice',
              permissions: 19,
              stime: 1707312000,
              path: '/Documents/project.pdf',
              share_with: 'bob',
              share_with_displayname: 'Bob Jones',
            },
            {
              id: 2,
              share_type: 3,
              uid_owner: 'alice',
              displayname_owner: 'Alice',
              permissions: 1,
              stime: 1707312100,
              path: '/Photos/vacation.jpg',
              token: 'AbCd123',
              expiration: '2024-03-01',
            },
          ],
        },
      });

      const { listSharesTool } = await import('../tools/apps/shares.js');
      const result = await listSharesTool.handler({});

      expect(result.content[0].text).toContain('User');
      expect(result.content[0].text).toContain('Bob Jones');
      expect(result.content[0].text).toContain('Public link');
      expect(result.content[0].text).toContain('AbCd123');
      expect(result.content[0].text).toContain('expires: 2024-03-01');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle no shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [],
        },
      });

      const { listSharesTool } = await import('../tools/apps/shares.js');
      const result = await listSharesTool.handler({});

      expect(result.content[0].text).toContain('No shares found');
    });
  });

  describe('create_share', () => {
    it('should create a user share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 42,
            share_type: 0,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 19,
            stime: 1707312000,
            path: '/Documents/report.pdf',
            share_with: 'bob',
            share_with_displayname: 'Bob',
          },
        },
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/Documents/report.pdf',
        shareType: 0,
        shareWith: 'bob',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
        method: 'POST',
        body: { path: '/Documents/report.pdf', shareType: '0', shareWith: 'bob' },
      });
      expect(result.content[0].text).toContain('Share created');
      expect(result.content[0].text).toContain('42');
      expect(result.content[0].text).toContain('User');
    });

    it('should create a public link share and return token/url', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 55,
            share_type: 3,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 1,
            stime: 1707312000,
            path: '/Photos/cat.jpg',
            token: 'xYz789',
            url: 'https://cloud.example.com/s/xYz789',
          },
        },
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/Photos/cat.jpg',
        shareType: 3,
      });

      expect(result.content[0].text).toContain('xYz789');
      expect(result.content[0].text).toContain('https://cloud.example.com/s/xYz789');
    });

    it('should handle API errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Share not found'));

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/nonexistent',
        shareType: 0,
        shareWith: 'bob',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating share');
    });
  });

  describe('update_share', () => {
    it('should update share permissions', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 42,
            share_type: 0,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 1,
            stime: 1707312000,
            path: '/Documents/report.pdf',
            share_with: 'bob',
          },
        },
      });

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 42, permissions: 1 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/files_sharing/api/v1/shares/42',
        {
          method: 'PUT',
          body: { permissions: '1' },
        }
      );
      expect(result.content[0].text).toContain('Share 42 updated');
    });

    it('should handle nonexistent share', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Wrong share ID'));

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 9999, permissions: 1 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error updating share');
    });
  });

  describe('delete_share', () => {
    it('should delete a share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [],
        },
      });

      const { deleteShareTool } = await import('../tools/apps/shares.js');
      const result = await deleteShareTool.handler({ shareId: 42 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/files_sharing/api/v1/shares/42',
        {
          method: 'DELETE',
        }
      );
      expect(result.content[0].text).toContain('Share 42 deleted successfully');
    });

    it('should handle nonexistent share', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Wrong share ID'));

      const { deleteShareTool } = await import('../tools/apps/shares.js');
      const result = await deleteShareTool.handler({ shareId: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting share');
    });
  });

  describe('get_share', () => {
    it('should return share details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 42, share_type: 0, path: '/doc.pdf', share_with: 'bob' },
        },
      });

      const { getShareTool } = await import('../tools/apps/shares.js');
      const result = await getShareTool.handler({ shareId: 42 });

      expect(result.content[0].text).toContain('42');
      expect(result.content[0].text).toContain('doc.pdf');
    });
  });

  describe('list_shares_with_me', () => {
    it('should list shares shared with current user', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 1,
              share_type: 0,
              path: '/shared-doc.pdf',
              uid_owner: 'alice',
              displayname_owner: 'Alice',
            },
          ],
        },
      });

      const { listSharesWithMeTool } = await import('../tools/apps/shares.js');
      const result = await listSharesWithMeTool.handler();

      expect(result.content[0].text).toContain('shared-doc.pdf');
      expect(result.content[0].text).toContain('Alice');
    });

    it('should handle no shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listSharesWithMeTool } = await import('../tools/apps/shares.js');
      const result = await listSharesWithMeTool.handler();

      expect(result.content[0].text).toContain('No shares found');
    });
  });

  describe('search_sharees', () => {
    it('should search for share recipients', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 100, message: 'OK' },
          data: {
            exact: { users: [], groups: [] },
            users: [{ label: 'Bob', value: { shareType: 0, shareWith: 'bob' } }],
          },
        },
      });

      const { searchShareesTool } = await import('../tools/apps/shares.js');
      const result = await searchShareesTool.handler({ search: 'bob' });

      expect(result.content[0].text).toContain('Bob');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v1.php/apps/files_sharing/api/v1/sharees',
        expect.objectContaining({
          queryParams: expect.objectContaining({
            search: 'bob',
            'shareType[]': expect.any(Array),
          }),
        })
      );
    });
  });

  describe('list_pending_shares', () => {
    it('should list pending remote shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 100, message: 'OK' },
          data: [{ id: 5, name: 'shared-folder', remote: 'https://other.cloud' }],
        },
      });

      const { listPendingSharesTool } = await import('../tools/apps/shares.js');
      const result = await listPendingSharesTool.handler();

      expect(result.content[0].text).toContain('shared-folder');
    });

    it('should handle no pending shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: [] },
      });

      const { listPendingSharesTool } = await import('../tools/apps/shares.js');
      const result = await listPendingSharesTool.handler();

      expect(result.content[0].text).toContain('No pending');
    });
  });

  describe('accept_pending_share', () => {
    it('should accept pending share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: {} },
      });

      const { acceptPendingShareTool } = await import('../tools/apps/shares.js');
      const result = await acceptPendingShareTool.handler({ shareId: 5 });

      expect(result.content[0].text).toContain('accepted');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/5',
        expect.objectContaining({ method: 'POST' })
      );
    });
  });

  describe('decline_pending_share', () => {
    it('should decline pending share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: {} },
      });

      const { declinePendingShareTool } = await import('../tools/apps/shares.js');
      const result = await declinePendingShareTool.handler({ shareId: 5 });

      expect(result.content[0].text).toContain('declined');
    });
  });
});
