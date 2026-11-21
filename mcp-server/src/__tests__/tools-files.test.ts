// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { expectStructured } from './helpers/structured.js';

// Mock webdav (needed because files.ts imports getWebDAVClient)
const mockFetchAiquilaAPI = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    fetchAiquilaAPI: (...args: unknown[]) => mockFetchAiquilaAPI(...args),
  };
});

const mockWebDAV = { stat: vi.fn(), getFileContents: vi.fn() };
vi.mock('../client/webdav.js', () => ({ getWebDAVClient: () => mockWebDAV }));

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
      expectStructured(result, getFileInfoTool);

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

    it('should return other binary files whole, as an embedded resource', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'archive.zip',
        mimeType: 'application/zip',
        size: 8,
        encoding: 'base64',
        content: 'UEsDBBQAAAA=',
      });

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/archive.zip' });

      expect(result.content).toHaveLength(2);
      expect(result.content[0].text).toContain('archive.zip (application/zip, 8 bytes)');
      expect(result.content[1]).toEqual({
        type: 'resource',
        resource: {
          uri: 'nextcloud:///archive.zip',
          mimeType: 'application/zip',
          blob: 'UEsDBBQAAAA=',
        },
      });
    });

    it('should describe a binary file over MCP_MAX_EMBEDDED_FILE_SIZE instead', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '4';
      mockFetchAiquilaAPI.mockResolvedValue({
        name: 'archive.zip',
        mimeType: 'application/zip',
        size: 8,
        encoding: 'base64',
        content: 'UEsDBBQAAAA=',
      });

      const { getFileContentTool } = await import('../tools/system/files.js');
      const result = await getFileContentTool.handler({ path: '/archive.zip' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain('MCP_MAX_EMBEDDED_FILE_SIZE');
    });

    describe('without the AIquila app (404)', () => {
      const docx = Buffer.from([0x50, 0x4b, 0x03, 0x04, 0x14, 0x00, 0x00, 0x00]);
      const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

      beforeEach(async () => {
        const { ApiError } = await import('../client/aiquila.js');
        mockFetchAiquilaAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      });

      it('should read a binary file over WebDAV', async () => {
        mockWebDAV.stat.mockResolvedValue({
          basename: 'Lettre.docx',
          type: 'file',
          size: 8,
          mime: DOCX,
        });
        mockWebDAV.getFileContents.mockResolvedValue(new Uint8Array(docx));

        const { getFileContentTool } = await import('../tools/system/files.js');
        const result = await getFileContentTool.handler({ path: '/Courrier/Lettre.docx' });

        expect(mockWebDAV.getFileContents).toHaveBeenCalledWith('/Courrier/Lettre.docx', {
          format: 'binary',
        });
        expect(result).not.toHaveProperty('isError');
        expect(result.content[1]).toEqual({
          type: 'resource',
          resource: {
            uri: 'nextcloud:///Courrier/Lettre.docx',
            mimeType: DOCX,
            blob: docx.toString('base64'),
          },
        });
      });

      it('should read a text file over WebDAV', async () => {
        mockWebDAV.stat.mockResolvedValue({
          basename: 'notes.md',
          type: 'file',
          size: 7,
          mime: 'text/markdown',
        });
        mockWebDAV.getFileContents.mockResolvedValue(new TextEncoder().encode('# Notes'));

        const { getFileContentTool } = await import('../tools/system/files.js');
        const result = await getFileContentTool.handler({ path: '/notes.md' });

        expect(result.content).toEqual([
          { type: 'text', text: 'File: notes.md (text/markdown, 7 bytes)\n\n# Notes' },
        ]);
      });

      it('should report a file WebDAV does not find either', async () => {
        mockWebDAV.stat.mockRejectedValue(new Error('Invalid response: 404 Not Found'));

        const { getFileContentTool } = await import('../tools/system/files.js');
        const result = await getFileContentTool.handler({ path: '/absent.pdf' });

        expect(result.isError).toBe(true);
        expect(result.content[0].text).toContain('404 Not Found');
      });

      it('should refuse a folder', async () => {
        mockWebDAV.stat.mockResolvedValue({ basename: 'Photos', type: 'directory', size: 0 });

        const { getFileContentTool } = await import('../tools/system/files.js');
        const result = await getFileContentTool.handler({ path: '/Photos' });

        expect(result.isError).toBe(true);
        expect(result.content[0].text).toContain('is a folder');
        expect(mockWebDAV.getFileContents).not.toHaveBeenCalled();
      });

      it('should let analyze_image read the image over WebDAV', async () => {
        const jpeg = Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10]);
        mockWebDAV.stat.mockResolvedValue({
          basename: 'ticket.jpg',
          type: 'file',
          size: 6,
          mime: 'image/jpeg',
        });
        mockWebDAV.getFileContents.mockResolvedValue(new Uint8Array(jpeg));

        const { analyzeImageTool } = await import('../tools/system/files.js');
        const result = await analyzeImageTool.handler({ path: '/ticket.jpg', prompt: 'Total?' });

        expect(result.content[1]).toEqual({
          type: 'image',
          data: jpeg.toString('base64'),
          mimeType: 'image/jpeg',
        });
      });
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

  describe('create_archive', () => {
    it('should POST sources and destination to /files/zip', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        archive: '/admin/files/backup.zip',
        entries: 3,
        size: 2048,
      });

      const { createArchiveTool } = await import('../tools/system/files.js');
      const result = await createArchiveTool.handler({
        sources: ['/Documents/a.txt', '/Photos'],
        destination: '/backup.zip',
        overwrite: false,
      });

      expect(result).not.toHaveProperty('isError');
      expect(result.content[0].text).toContain('backup.zip');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/zip', {
        method: 'POST',
        body: {
          sources: ['/Documents/a.txt', '/Photos'],
          destination: '/backup.zip',
          overwrite: false,
        },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Destination already exists'));

      const { createArchiveTool } = await import('../tools/system/files.js');
      const result = await createArchiveTool.handler({
        sources: ['/a.txt'],
        destination: '/exists.zip',
        overwrite: false,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Destination already exists');
    });
  });

  describe('extract_archive', () => {
    it('should POST archive and destination to /files/unzip', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        destination: '/admin/files/restored',
        extracted: 5,
      });

      const { extractArchiveTool } = await import('../tools/system/files.js');
      const result = await extractArchiveTool.handler({
        archive: '/backup.zip',
        destination: '/restored',
        overwrite: true,
      });

      expect(result).not.toHaveProperty('isError');
      expect(result.content[0].text).toContain('extracted');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/unzip', {
        method: 'POST',
        body: { archive: '/backup.zip', destination: '/restored', overwrite: true },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('Unsafe path traversal in archive'));

      const { extractArchiveTool } = await import('../tools/system/files.js');
      const result = await extractArchiveTool.handler({
        archive: '/evil.zip',
        destination: '/out',
        overwrite: false,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Unsafe path traversal');
    });
  });

  describe('list_archive', () => {
    it('should GET archive entries via query params', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        archive: '/admin/files/backup.zip',
        count: 2,
        entries: [
          { name: 'a.txt', size: 10, compressedSize: 8, isDirectory: false },
          { name: 'sub/', size: 0, compressedSize: 0, isDirectory: true },
        ],
      });

      const { listArchiveTool } = await import('../tools/system/files.js');
      const result = await listArchiveTool.handler({ archive: '/backup.zip' });

      expect(result).not.toHaveProperty('isError');
      expect(result.content[0].text).toContain('a.txt');
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/files/zip/list', {
        queryParams: { archive: '/backup.zip' },
      });
    });

    it('should handle errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('not a valid zip file'));

      const { listArchiveTool } = await import('../tools/system/files.js');
      const result = await listArchiveTool.handler({ archive: '/bad.zip' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not a valid zip file');
    });
  });
});
