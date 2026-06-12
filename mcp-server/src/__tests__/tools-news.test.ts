// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the News API client module
const mockFetchNewsAPI = vi.fn();

vi.mock('../client/news.js', () => ({
  fetchNewsAPI: (...args: unknown[]) => mockFetchNewsAPI(...args),
}));

describe('News Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_feeds', () => {
    it('should return formatted feed list', async () => {
      mockFetchNewsAPI.mockResolvedValue({
        feeds: [
          {
            id: 4,
            url: 'https://blog.example.com/feed',
            title: 'Example Blog',
            faviconLink: null,
            added: 1700000000,
            folderId: 2,
            unreadCount: 7,
            ordering: 0,
            link: 'https://blog.example.com',
            pinned: false,
            updateErrorCount: 0,
            lastUpdateError: null,
          },
        ],
      });

      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'list_feeds')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Example Blog');
      expect(result.content[0].text).toContain('https://blog.example.com/feed');
      expect(result.content[0].text).toContain('Unread: 7');
      expect(result.content[0].text).toContain('Feeds (1)');
    });

    it('should handle empty feed list', async () => {
      mockFetchNewsAPI.mockResolvedValue({ feeds: [] });
      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'list_feeds')!;
      const result = await tool.handler({});
      expect(result.content[0].text).toContain('No feeds');
    });
  });

  describe('add_feed', () => {
    it('should POST url and folderId and report created feed', async () => {
      mockFetchNewsAPI.mockResolvedValue({ feeds: [{ id: 9, title: 'New Feed' }] });
      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'add_feed')!;
      const result = await tool.handler({ url: 'https://x.example/feed', folderId: 3 });

      expect(mockFetchNewsAPI).toHaveBeenCalledWith('/feeds', {
        method: 'POST',
        body: { url: 'https://x.example/feed', folderId: 3 },
      });
      expect(result.content[0].text).toContain('New Feed');
      expect(result.content[0].text).toContain('9');
    });
  });

  describe('list_news_items', () => {
    it('should map type/id and format items', async () => {
      mockFetchNewsAPI.mockResolvedValue({
        items: [
          {
            id: 100,
            guid: 'g',
            guidHash: 'gh',
            url: 'https://x.example/article',
            title: 'Big News',
            author: 'Jane',
            pubDate: 1700000000,
            body: '<p>Hello <b>world</b></p>',
            enclosureMime: null,
            enclosureLink: null,
            feedId: 4,
            unread: true,
            starred: false,
            lastModified: 1700000001,
            fingerprint: 'fp',
          },
        ],
      });

      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'list_news_items')!;
      const result = await tool.handler({ type: 'feed', id: 4, getRead: true });

      expect(mockFetchNewsAPI).toHaveBeenCalledWith('/items', {
        queryParams: { type: 0, id: 4, batchSize: 20, getRead: true, oldestFirst: false },
      });
      expect(result.content[0].text).toContain('Big News');
      expect(result.content[0].text).toContain('Hello world');
      expect(result.content[0].text).not.toContain('<b>');
    });
  });

  describe('mark_item_read', () => {
    it('should POST to the read endpoint', async () => {
      mockFetchNewsAPI.mockResolvedValue(undefined);
      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'mark_item_read')!;
      const result = await tool.handler({ itemId: 100 });

      expect(mockFetchNewsAPI).toHaveBeenCalledWith('/items/100/read', { method: 'POST' });
      expect(result.content[0].text).toContain('100');
    });

    it('should return an error result when the API throws', async () => {
      mockFetchNewsAPI.mockRejectedValue(new Error('boom'));
      const { newsTools } = await import('../tools/apps/news.js');
      const tool = newsTools.find((t) => t.name === 'mark_item_read')!;
      const result = await tool.handler({ itemId: 100 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });
});
