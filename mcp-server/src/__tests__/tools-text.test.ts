// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchTextAPI = vi.fn();
vi.mock('../client/text.js', async () => {
  const actual = await vi.importActual<typeof import('../client/text.js')>('../client/text.js');
  return {
    ...actual,
    fetchTextAPI: (...args: unknown[]) => mockFetchTextAPI(...args),
  };
});

const mockGetFileContents = vi.fn();
const mockPutFileContents = vi.fn();
const mockDeleteFile = vi.fn();
vi.mock('../client/webdav.js', () => ({
  getWebDAVClient: () => ({
    getFileContents: (...args: unknown[]) => mockGetFileContents(...args),
    putFileContents: (...args: unknown[]) => mockPutFileContents(...args),
    deleteFile: (...args: unknown[]) => mockDeleteFile(...args),
  }),
}));

const sampleFile = {
  id: 42,
  mimetype: 'text/markdown',
  name: 'Readme.md',
  path: '/Projects/Acme/Readme.md',
};

describe('Text Workspace Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('get_text_workspace', () => {
    it('returns workspace metadata when found', async () => {
      mockFetchTextAPI.mockResolvedValue({ file: sampleFile, folder: { permissions: 31 } });
      const { getTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await getTextWorkspaceTool.handler({ path: '/Projects/Acme' });
      expect(mockFetchTextAPI).toHaveBeenCalledWith('/workspace', {
        queryParams: { path: '/Projects/Acme' },
      });
      expect(result.content[0].text).toContain('Readme.md');
      expect(result.content[0].text).toContain('/Projects/Acme/Readme.md');
    });

    it('reports no workspace on 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { getTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await getTextWorkspaceTool.handler({ path: '/Empty' });
      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('No workspace');
    });

    it('maps 403 to access denied', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(403, 'Forbidden', ''));
      const { getTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await getTextWorkspaceTool.handler({ path: '/Secret' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Access denied');
    });
  });

  describe('read_text_workspace', () => {
    it('returns file content via WebDAV', async () => {
      mockFetchTextAPI.mockResolvedValue({ file: sampleFile });
      mockGetFileContents.mockResolvedValue('# Acme\n\nWelcome.');
      const { readTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await readTextWorkspaceTool.handler({ path: '/Projects/Acme' });
      expect(mockGetFileContents).toHaveBeenCalledWith('/Projects/Acme/Readme.md', {
        format: 'text',
      });
      expect(result.content[0].text).toBe('# Acme\n\nWelcome.');
    });

    it('handles missing workspace', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { readTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await readTextWorkspaceTool.handler({ path: '/Empty' });
      expect(mockGetFileContents).not.toHaveBeenCalled();
      expect(result.content[0].text).toContain('No workspace');
    });
  });

  describe('write_text_workspace', () => {
    it('creates Readme.md when no workspace exists', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      mockPutFileContents.mockResolvedValue(undefined);
      const { writeTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await writeTextWorkspaceTool.handler({
        path: '/Projects/Acme',
        content: '# Hello',
      });
      expect(mockPutFileContents).toHaveBeenCalledWith('/Projects/Acme/Readme.md', '# Hello', {
        overwrite: true,
      });
      expect(result.content[0].text).toContain('created');
    });

    it('reuses existing workspace filename', async () => {
      mockFetchTextAPI.mockResolvedValue({
        file: { ...sampleFile, name: 'Liesmich.md', path: '/Projects/Acme/Liesmich.md' },
      });
      mockPutFileContents.mockResolvedValue(undefined);
      const { writeTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await writeTextWorkspaceTool.handler({
        path: '/Projects/Acme',
        content: '# Hi',
      });
      expect(mockPutFileContents).toHaveBeenCalledWith('/Projects/Acme/Liesmich.md', '# Hi', {
        overwrite: true,
      });
      expect(result.content[0].text).toContain('updated');
    });

    it('handles root folder path', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      mockPutFileContents.mockResolvedValue(undefined);
      const { writeTextWorkspaceTool } = await import('../tools/apps/text.js');
      await writeTextWorkspaceTool.handler({ path: '/', content: 'top' });
      expect(mockPutFileContents).toHaveBeenCalledWith('/Readme.md', 'top', { overwrite: true });
    });
  });

  describe('delete_text_workspace', () => {
    it('deletes the resolved workspace file', async () => {
      mockFetchTextAPI.mockResolvedValue({ file: sampleFile });
      mockDeleteFile.mockResolvedValue(undefined);
      const { deleteTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await deleteTextWorkspaceTool.handler({ path: '/Projects/Acme' });
      expect(mockDeleteFile).toHaveBeenCalledWith('/Projects/Acme/Readme.md');
      expect(result.content[0].text).toContain('deleted');
    });

    it('is a no-op when no workspace exists', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { deleteTextWorkspaceTool } = await import('../tools/apps/text.js');
      const result = await deleteTextWorkspaceTool.handler({ path: '/Empty' });
      expect(mockDeleteFile).not.toHaveBeenCalled();
      expect(result.content[0].text).toContain('No workspace');
    });
  });

  describe('get_text_workspace_edit_url', () => {
    it('returns the direct-edit URL', async () => {
      mockFetchTextAPI.mockResolvedValue({
        url: 'https://cloud.example.com/index.php/apps/files/?token=abc',
      });
      const { getTextWorkspaceEditUrlTool } = await import('../tools/apps/text.js');
      const result = await getTextWorkspaceEditUrlTool.handler({ path: '/Projects/Acme' });
      expect(mockFetchTextAPI).toHaveBeenCalledWith('/workspace/direct', {
        method: 'POST',
        body: { path: '/Projects/Acme' },
      });
      expect(result.content[0].text).toContain('token=abc');
    });

    it('maps 403 to access denied', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchTextAPI.mockRejectedValue(new ApiError(403, 'Forbidden', ''));
      const { getTextWorkspaceEditUrlTool } = await import('../tools/apps/text.js');
      const result = await getTextWorkspaceEditUrlTool.handler({ path: '/Secret' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Access denied');
    });
  });
});
