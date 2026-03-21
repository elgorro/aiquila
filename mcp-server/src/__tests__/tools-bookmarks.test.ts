import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the Bookmarks API client module
const mockFetchBookmarksAPI = vi.fn();

vi.mock('../client/bookmarks.js', () => ({
  fetchBookmarksAPI: (...args: unknown[]) => mockFetchBookmarksAPI(...args),
}));

describe('Bookmarks Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // ── Bookmark CRUD ───────────────────────────────────────────────────────

  describe('list_bookmarks', () => {
    it('should return formatted bookmark list', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        data: [
          {
            id: 1,
            url: 'https://example.com',
            target: '',
            title: 'Example',
            description: 'An example site',
            added: 1700000000,
            userId: 'testuser',
            tags: ['tech', 'reference'],
            folders: [5],
            clickcount: 3,
            available: true,
            archivedFile: null,
          },
          {
            id: 2,
            url: 'https://news.ycombinator.com',
            target: '',
            title: 'Hacker News',
            description: '',
            added: 1700100000,
            userId: 'testuser',
            tags: ['news'],
            folders: [5, 10],
            clickcount: 15,
            available: true,
            archivedFile: null,
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmarks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Example');
      expect(result.content[0].text).toContain('https://example.com');
      expect(result.content[0].text).toContain('tech, reference');
      expect(result.content[0].text).toContain('Hacker News');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should pass search and filter params', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmarks')!;
      await tool.handler({ search: 'test', tags: ['tech'], folder: 5, limit: 10, sortby: 'title' });

      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/bookmark', {
        queryParams: {
          'search[]': ['test'],
          tags: ['tech'],
          folder: '5',
          limit: '10',
          sortby: 'title',
        },
      });
    });

    it('should handle empty results', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmarks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No bookmarks found');
    });

    it('should handle API errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(
        new Error('Bookmarks API 500: Internal Server Error')
      );

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmarks')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('get_bookmark', () => {
    it('should return bookmark details', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: {
          id: 1,
          url: 'https://example.com',
          target: '',
          title: 'Example',
          description: 'An example site',
          added: 1700000000,
          userId: 'testuser',
          tags: ['tech'],
          folders: [5],
          clickcount: 3,
          available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'get_bookmark')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Example');
      expect(result.content[0].text).toContain('https://example.com');
      expect(result.content[0].text).toContain('An example site');
      expect(result.content[0].text).toContain('ID: 1');
    });

    it('should handle nonexistent bookmark', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Not Found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'get_bookmark')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('create_bookmark', () => {
    it('should create a bookmark successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: {
          id: 42,
          url: 'https://new-site.com',
          target: '',
          title: 'New Site',
          description: 'A new bookmark',
          added: 1700200000,
          userId: 'testuser',
          tags: ['new'],
          folders: [5],
          clickcount: 0,
          available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'create_bookmark')!;
      const result = await tool.handler({
        url: 'https://new-site.com',
        title: 'New Site',
        description: 'A new bookmark',
        tags: ['new'],
        folders: [5],
      });

      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('ID: 42');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/bookmark', {
        method: 'POST',
        body: {
          url: 'https://new-site.com',
          title: 'New Site',
          description: 'A new bookmark',
          tags: ['new'],
          folders: [5],
        },
      });
    });

    it('should handle creation errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 400: Invalid URL'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'create_bookmark')!;
      const result = await tool.handler({ url: 'not-a-url' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating bookmark');
    });
  });

  describe('update_bookmark', () => {
    it('should update a bookmark successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: {
          id: 1,
          url: 'https://example.com',
          target: '',
          title: 'Updated Title',
          description: '',
          added: 1700000000,
          userId: 'testuser',
          tags: ['updated'],
          folders: [5],
          clickcount: 3,
          available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'update_bookmark')!;
      const result = await tool.handler({ id: 1, title: 'Updated Title', tags: ['updated'] });

      expect(result.content[0].text).toContain('updated successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/bookmark/1', {
        method: 'PUT',
        body: { title: 'Updated Title', tags: ['updated'] },
      });
    });
  });

  describe('delete_bookmark', () => {
    it('should delete a bookmark successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success' });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'delete_bookmark')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/bookmark/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Not Found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'delete_bookmark')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting bookmark');
    });
  });

  // ── Folder Tools ────────────────────────────────────────────────────────

  describe('list_bookmark_folders', () => {
    it('should return folder hierarchy', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        data: [
          { id: 1, title: 'Tech', parent_folder: -1, userId: 'testuser', children: [] },
          {
            id: 2,
            title: 'News',
            parent_folder: -1,
            userId: 'testuser',
            children: [
              { id: 3, title: 'Daily', parent_folder: 2, userId: 'testuser', children: [] },
            ],
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmark_folders')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Tech');
      expect(result.content[0].text).toContain('ID: 1');
      expect(result.content[0].text).toContain('News');
      expect(result.content[0].text).toContain('Daily');
    });

    it('should handle empty folder list', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmark_folders')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No bookmark folders found');
    });
  });

  describe('get_bookmark_folder_contents', () => {
    it('should return folder children', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        data: [
          { id: 3, title: 'Subfolder', parent_folder: 1, userId: 'testuser' },
          {
            id: 10,
            url: 'https://example.com',
            target: '',
            title: 'Example',
            description: '',
            added: 1700000000,
            userId: 'testuser',
            tags: [],
            folders: [1],
            clickcount: 0,
            available: true,
            archivedFile: null,
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'get_bookmark_folder_contents')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('2 items');
      expect(result.content[0].text).toContain('Subfolder');
      expect(result.content[0].text).toContain('Example');
    });

    it('should handle empty folder', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'get_bookmark_folder_contents')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Folder is empty');
    });
  });

  describe('create_bookmark_folder', () => {
    it('should create a folder successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: { id: 10, title: 'New Folder', parent_folder: -1, userId: 'testuser' },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'create_bookmark_folder')!;
      const result = await tool.handler({ title: 'New Folder' });

      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('ID: 10');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/folder', {
        method: 'POST',
        body: { title: 'New Folder' },
      });
    });

    it('should pass parent_folder when provided', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: { id: 11, title: 'Subfolder', parent_folder: 5, userId: 'testuser' },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'create_bookmark_folder')!;
      await tool.handler({ title: 'Subfolder', parent_folder: 5 });

      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/folder', {
        method: 'POST',
        body: { title: 'Subfolder', parent_folder: 5 },
      });
    });
  });

  describe('update_bookmark_folder', () => {
    it('should update a folder successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        item: { id: 5, title: 'Renamed', parent_folder: -1, userId: 'testuser' },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'update_bookmark_folder')!;
      const result = await tool.handler({ id: 5, title: 'Renamed' });

      expect(result.content[0].text).toContain('updated successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/folder/5', {
        method: 'PUT',
        body: { title: 'Renamed' },
      });
    });
  });

  describe('delete_bookmark_folder', () => {
    it('should delete a folder successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success' });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'delete_bookmark_folder')!;
      const result = await tool.handler({ id: 5 });

      expect(result.content[0].text).toContain('deleted successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/folder/5', { method: 'DELETE' });
    });
  });

  // ── Tag Tools ───────────────────────────────────────────────────────────

  describe('list_bookmark_tags', () => {
    it('should return sorted tag list', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({
        status: 'success',
        data: ['tech', 'news', 'art', 'music'],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmark_tags')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('4');
      expect(result.content[0].text).toContain('- art');
      expect(result.content[0].text).toContain('- music');
      expect(result.content[0].text).toContain('- news');
      expect(result.content[0].text).toContain('- tech');
    });

    it('should handle empty tag list', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'list_bookmark_tags')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No bookmark tags found');
    });
  });

  describe('rename_bookmark_tag', () => {
    it('should rename a tag successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success' });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'rename_bookmark_tag')!;
      const result = await tool.handler({ old_name: 'oldtag', new_name: 'newtag' });

      expect(result.content[0].text).toContain('renamed');
      expect(result.content[0].text).toContain('oldtag');
      expect(result.content[0].text).toContain('newtag');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/tag/oldtag', {
        method: 'PUT',
        body: { name: 'newtag' },
      });
    });
  });

  describe('delete_bookmark_tag', () => {
    it('should delete a tag successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success' });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'delete_bookmark_tag')!;
      const result = await tool.handler({ name: 'oldtag' });

      expect(result.content[0].text).toContain('deleted successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/tag/oldtag', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Tag not found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find((t) => t.name === 'delete_bookmark_tag')!;
      const result = await tool.handler({ name: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting tag');
    });
  });
});
