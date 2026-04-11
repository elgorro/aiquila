import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock webdav (needed because files.ts imports getWebDAVClient)
vi.mock('webdav', () => ({
  createClient: vi.fn(() => ({})),
}));

const mockFetchAiquilaAPI = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    fetchAiquilaAPI: (...args: unknown[]) => mockFetchAiquilaAPI(...args),
  };
});

describe('File Tools (AIquila API)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // ─── get_file_info ──────────────────────────────────────────────────

  describe('get_file_info', () => {
    it('should return file metadata as JSON', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'report.pdf',
        mimeType: 'application/pdf',
        size: 102400,
        modified: '2026-04-01T12:00:00Z',
      });

      const { getFileInfoTool } = await import('../tools/system/files.js');
      const result = await getFileInfoTool.handler({ path: '/Documents/report.pdf' });

      expect(result.content[0].text).toContain('report.pdf');
      expect(result.content[0].text).toContain('application/pdf');
      expect(result).not.toHaveProperty('isError');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/info', {
        queryParams: { path: '/Documents/report.pdf' },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('File not found'));

      const { getFileInfoTool } = await import('../tools/system/files.js');
      const result = await getFileInfoTool.handler({ path: '/nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('File not found');
    });
  });

  // ─── search_files ─────────────────────────────────────────────────

  describe('search_files', () => {
    it('should search with query and return results', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([{ name: 'notes.txt', path: '/Documents/notes.txt' }]);

      const { searchFilesTool } = await import('../tools/system/files.js');
      const result = await searchFilesTool.handler({ query: 'notes' });

      expect(result.content[0].text).toContain('notes.txt');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/search', {
        queryParams: expect.objectContaining({ query: 'notes' }),
      });
    });

    it('should pass optional mime filter', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([]);

      const { searchFilesTool } = await import('../tools/system/files.js');
      await searchFilesTool.handler({ query: 'photo', mime: 'image/' });

      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/search', {
        queryParams: expect.objectContaining({ query: 'photo', mime: 'image/' }),
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Search error'));

      const { searchFilesTool } = await import('../tools/system/files.js');
      const result = await searchFilesTool.handler({ query: 'test' });

      expect(result.isError).toBe(true);
    });
  });

  // ─── get_file_content ─────────────────────────────────────────────

  describe('get_file_content', () => {
    it('should return text content for text files', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'readme.md',
        mimeType: 'text/markdown',
        size: 256,
        encoding: 'text',
        content: '# Hello World',
      });

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/readme.md' });

      expect(result.content).toHaveLength(1);
      expect(result.content[0].type).toBe('text');
      expect(result.content[0].text).toContain('# Hello World');
      expect(result.content[0].text).toContain('text/markdown');
    });

    it('should return image content block for images', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'photo.jpg',
        mimeType: 'image/jpeg',
        size: 50000,
        encoding: 'base64',
        content: '/9j/4AAQSkZJRg==',
      });

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/Photos/photo.jpg' });

      expect(result.content).toHaveLength(2);
      expect(result.content[0].type).toBe('text');
      expect(result.content[1].type).toBe('image');
      expect(result.content[1]).toHaveProperty('data', '/9j/4AAQSkZJRg==');
      expect(result.content[1]).toHaveProperty('mimeType', 'image/jpeg');
    });

    it('should return base64 text for other binary files', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'archive.zip',
        mimeType: 'application/zip',
        size: 1024,
        encoding: 'base64',
        content: 'UEsDBBQAAAA=',
      });

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/archive.zip' });

      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain('base64');
      expect(result.content[0].text).toContain('UEsDBBQAAAA=');
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Permission denied'));

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/secret.dat' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
    });
  });

  // ─── analyze_image ────────────────────────────────────────────────

  describe('analyze_image', () => {
    it('should analyze a single image', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'receipt.jpg',
        mimeType: 'image/jpeg',
        size: 30000,
        encoding: 'base64',
        content: '/9j/base64data',
      });

      const { analyzeImageTool } = await import('../tools/system/files.js');
      const result = await analyzeImageTool.handler({
        path: '/Photos/receipt.jpg',
        prompt: 'What text is in this image?',
      });

      expect(result.content[0].type).toBe('text');
      expect(result.content[0].text).toContain('receipt.jpg');
      expect(result.content[0].text).toContain('What text is in this image?');
      expect(result.content[1].type).toBe('image');
    });

    it('should analyze multiple images', async () => {
      mockFetchAiquilaAPI
        .mockResolvedValueOnce({
          name: 'before.jpg',
          mimeType: 'image/jpeg',
          size: 20000,
          encoding: 'base64',
          content: 'img1data',
        })
        .mockResolvedValueOnce({
          name: 'after.jpg',
          mimeType: 'image/jpeg',
          size: 25000,
          encoding: 'base64',
          content: 'img2data',
        });

      const { analyzeImageTool } = await import('../tools/system/files.js');
      const result = await analyzeImageTool.handler({
        paths: ['/Photos/before.jpg', '/Photos/after.jpg'],
        prompt: 'Compare these images',
      });

      expect(result.content[0].text).toContain('Analyzing 2 images');
      expect(result.content).toHaveLength(3); // 1 text + 2 images
    });

    it('should error when no path is provided', async () => {
      const { analyzeImageTool } = await import('../tools/system/files.js');
      const result = await analyzeImageTool.handler({ prompt: 'Describe this' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No image path provided');
    });

    it('should reject non-image files', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'document.pdf',
        mimeType: 'application/pdf',
        size: 50000,
        encoding: 'base64',
        content: 'pdfdata',
      });

      const { analyzeImageTool } = await import('../tools/system/files.js');
      const result = await analyzeImageTool.handler({
        path: '/document.pdf',
        prompt: 'Read this',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Non-image file');
      expect(result.content[0].text).toContain('application/pdf');
    });

    it('should handle fetch errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('File not found'));

      const { analyzeImageTool } = await import('../tools/system/files.js');
      const result = await analyzeImageTool.handler({
        path: '/nonexistent.jpg',
        prompt: 'Describe',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('File not found');
    });
  });
});
