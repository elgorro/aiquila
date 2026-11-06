// SPDX-License-Identifier: MIT

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

    function mockShare(data: Record<string, unknown>) {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 7, share_type: 3, path: '/a.txt', ...data },
        },
      });
    }

    it('sends attributes as the JSON string the API expects, and reports them', async () => {
      const attributes = [{ scope: 'permissions', key: 'download', value: false }];
      const json = JSON.stringify(attributes);
      mockShare({ share_type: 0, attributes: json });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/a.txt',
        shareType: 0,
        shareWith: 'bob',
        attributes,
      });

      expect(mockFetchOCS.mock.calls[0][1].body.attributes).toBe(json);
      expect(result.content[0].text).toContain(`Attributes: ${json}`);
    });

    it('sends sendPasswordByTalk and reports it with the password expiry', async () => {
      mockShare({
        share_type: 4,
        send_password_by_talk: true,
        password_expiration_time: '2026-11-06T01:00:00+00:00',
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/a.txt',
        shareType: 4,
        shareWith: 'a@example.com',
        password: 'secret',
        sendPasswordByTalk: true,
      });

      expect(mockFetchOCS.mock.calls[0][1].body.sendPasswordByTalk).toBe('true');
      expect(result.content[0].text).toContain('Password by Talk: on');
      expect(result.content[0].text).toContain('Password expires: 2026-11-06T01:00:00+00:00');
    });

    it('leaves password handling alone without the new parameters', async () => {
      mockShare({});

      const { createShareTool } = await import('../tools/apps/shares.js');
      await createShareTool.handler({ path: '/a.txt', shareType: 3, password: 'secret' });

      expect(mockFetchOCS.mock.calls[0][1].body).toEqual({
        path: '/a.txt',
        shareType: '3',
        password: 'secret',
      });
    });

    it('refuses sendPasswordByTalk on a share type that ignores it', async () => {
      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/a.txt',
        shareType: 0,
        shareWith: 'bob',
        password: 'secret',
        sendPasswordByTalk: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('link and email shares only');
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });

    it('refuses sendPasswordByTalk without a password', async () => {
      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/a.txt',
        shareType: 3,
        sendPasswordByTalk: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('needs a password');
      expect(mockFetchOCS).not.toHaveBeenCalled();
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

    it('should apply hideDownload with a follow-up update (POST /shares ignores it)', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 55, share_type: 3, path: '/Photos/cat.jpg', token: 'xYz789' },
        },
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      await createShareTool.handler({ path: '/Photos/cat.jpg', shareType: 3, hideDownload: true });

      expect(mockFetchOCS).toHaveBeenNthCalledWith(
        1,
        '/ocs/v2.php/apps/files_sharing/api/v1/shares',
        { method: 'POST', body: { path: '/Photos/cat.jpg', shareType: '3' } }
      );
      expect(mockFetchOCS).toHaveBeenNthCalledWith(
        2,
        '/ocs/v2.php/apps/files_sharing/api/v1/shares/55',
        { method: 'PUT', body: { hideDownload: 'true' } }
      );
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

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares/42', {
        method: 'PUT',
        body: { permissions: '1' },
      });
      expect(result.content[0].text).toContain('Share 42 updated');
    });

    it('sends attributes and sendPasswordByTalk', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 42, share_type: 3, path: '/a.txt', send_password_by_talk: true },
        },
      });

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({
        shareId: 42,
        sendPasswordByTalk: true,
        attributes: [{ scope: 'permissions', key: 'download', value: true }],
      });

      expect(mockFetchOCS.mock.calls[0][1].body).toEqual({
        sendPasswordByTalk: 'true',
        attributes: '[{"scope":"permissions","key":"download","value":true}]',
      });
      expect(result.content[0].text).not.toContain('Not applied');
    });

    it('turns sendPasswordByTalk off', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 42, share_type: 3, path: '/a.txt', send_password_by_talk: false },
        },
      });

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 42, sendPasswordByTalk: false });

      expect(mockFetchOCS.mock.calls[0][1].body).toEqual({ sendPasswordByTalk: 'false' });
      expect(result.content[0].text).not.toContain('Not applied');
    });

    it('says when the server ignored sendPasswordByTalk', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 42, share_type: 0, path: '/a.txt' },
        },
      });

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 42, sendPasswordByTalk: true });

      expect(result.content[0].text).toContain('Not applied: sendPasswordByTalk');
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

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares/42', {
        method: 'DELETE',
      });
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
