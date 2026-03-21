import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: vi.fn(),
}));

describe('Search Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('unified_search', () => {
    it('should search a specific provider', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            name: 'Files',
            isPaginated: false,
            entries: [
              {
                thumbnailUrl: '',
                title: 'report.pdf',
                subline: '/Documents/report.pdf',
                resourceUrl: 'https://cloud.example.com/f/123',
                icon: 'icon-file',
                rounded: false,
                attributes: {},
              },
            ],
            cursor: null,
          },
        },
      });

      const { unifiedSearchTool } = await import('../tools/system/search.js');
      const result = await unifiedSearchTool.handler({
        query: 'report',
        provider: 'files',
        limit: 5,
      });

      expect(result).not.toHaveProperty('isError');
      const parsed = JSON.parse(result.content[0].text);
      expect(parsed.provider).toBe('files');
      expect(parsed.entries).toHaveLength(1);
      expect(parsed.entries[0].title).toBe('report.pdf');
    });

    it('should search across all providers when none specified', async () => {
      // First call: list providers
      mockFetchOCS.mockResolvedValueOnce({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 'files',
              appId: 'files',
              name: 'Files',
              icon: '',
              order: 1,
              triggers: [],
              filters: {},
            },
            {
              id: 'contacts',
              appId: 'contacts',
              name: 'Contacts',
              icon: '',
              order: 2,
              triggers: [],
              filters: {},
            },
          ],
        },
      });
      // Subsequent calls: search each provider
      mockFetchOCS.mockResolvedValueOnce({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            name: 'Files',
            isPaginated: false,
            entries: [
              {
                thumbnailUrl: '',
                title: 'test.txt',
                subline: '/test.txt',
                resourceUrl: 'https://cloud.example.com/f/1',
                icon: '',
                rounded: false,
                attributes: {},
              },
            ],
            cursor: null,
          },
        },
      });
      mockFetchOCS.mockResolvedValueOnce({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            name: 'Contacts',
            isPaginated: false,
            entries: [],
            cursor: null,
          },
        },
      });

      const { unifiedSearchTool } = await import('../tools/system/search.js');
      const result = await unifiedSearchTool.handler({
        query: 'test',
        limit: 5,
      });

      expect(result).not.toHaveProperty('isError');
      const parsed = JSON.parse(result.content[0].text);
      // Only providers with entries are returned
      expect(parsed).toHaveLength(1);
      expect(parsed[0].provider).toBe('files');
    });

    it('should return message when no results found', async () => {
      mockFetchOCS.mockResolvedValueOnce({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 'files',
              appId: 'files',
              name: 'Files',
              icon: '',
              order: 1,
              triggers: [],
              filters: {},
            },
          ],
        },
      });
      mockFetchOCS.mockResolvedValueOnce({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { name: 'Files', isPaginated: false, entries: [], cursor: null },
        },
      });

      const { unifiedSearchTool } = await import('../tools/system/search.js');
      const result = await unifiedSearchTool.handler({ query: 'nonexistent', limit: 5 });

      expect(result).not.toHaveProperty('isError');
      expect(result.content[0].text).toContain('No results found');
    });

    it('should handle errors gracefully', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Connection refused'));

      const { unifiedSearchTool } = await import('../tools/system/search.js');
      const result = await unifiedSearchTool.handler({
        query: 'test',
        provider: 'files',
        limit: 5,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('list_search_providers', () => {
    it('should return list of providers', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 'files',
              appId: 'files',
              name: 'Files',
              icon: 'icon-folder',
              order: 1,
              triggers: [],
              filters: {},
            },
            {
              id: 'calendar',
              appId: 'calendar',
              name: 'Calendar',
              icon: 'icon-calendar',
              order: 5,
              triggers: [],
              filters: {},
            },
          ],
        },
      });

      const { listSearchProvidersTool } = await import('../tools/system/search.js');
      const result = await listSearchProvidersTool.handler();

      expect(result).not.toHaveProperty('isError');
      const parsed = JSON.parse(result.content[0].text);
      expect(parsed).toHaveLength(2);
      expect(parsed[0].id).toBe('files');
      expect(parsed[1].id).toBe('calendar');
    });

    it('should handle errors gracefully', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Unauthorized'));

      const { listSearchProvidersTool } = await import('../tools/system/search.js');
      const result = await listSearchProvidersTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Unauthorized');
    });
  });
});
