// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { expectStructured } from './helpers/structured.js';
import type { FileStat } from '../client/webdav.js';

// Mock the WebDAV client
const mockClient = {
  getDirectoryContents: vi.fn(),
  getFileContents: vi.fn(),
  getFileStart: vi.fn(),
  putFileContents: vi.fn(),
  createDirectory: vi.fn(),
  deleteFile: vi.fn(),
  moveFile: vi.fn(),
  copyFile: vi.fn(),
  stat: vi.fn(),
};

vi.mock('../client/webdav.js', () => ({ getWebDAVClient: () => mockClient }));

// Mock fetch for CalDAV and OCS
global.fetch = vi.fn();

describe('MCP Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_files', () => {
    it('returns the listing as JSON', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'Documents' },
        { type: 'file', basename: 'readme.txt' },
      ] satisfies Partial<FileStat>[]);

      const { listFilesTool } = await import('../tools/system/files.js');
      const result = await listFilesTool.handler({ path: '/' });

      expect(JSON.parse(result.content[0].text)).toEqual([
        { type: 'directory', basename: 'Documents' },
        { type: 'file', basename: 'readme.txt' },
      ]);
    });

    it('should call handler and return JSON', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'Photos' },
      ]);

      const { listFilesTool } = await import('../tools/system/files.js');
      const result = await listFilesTool.handler({ path: '/' });

      expect(result.content[0].text).toContain('Photos');
      expect(mockClient.getDirectoryContents).toHaveBeenCalledWith('/');
    });
  });

  describe('read_file', () => {
    it('should return file contents', async () => {
      mockClient.stat.mockResolvedValue({ basename: 'test.txt', mime: 'text/plain', size: 13 });
      mockClient.getFileContents.mockResolvedValue(new TextEncoder().encode('Hello, World!'));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/test.txt' });

      expect(result.content).toEqual([{ type: 'text', text: 'Hello, World!' }]);
      expect(mockClient.getFileContents).toHaveBeenCalledWith('/test.txt', { format: 'binary' });
    });

    it('should refuse a binary file over MCP_MAX_EMBEDDED_FILE_SIZE without downloading it', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
      mockClient.stat.mockResolvedValue({ basename: 'big.pdf', mime: 'application/pdf', size: 11 });

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/big.pdf' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain('File: big.pdf (application/pdf, 11 bytes)');
      expect(result.content[0].text).toContain('MCP_MAX_EMBEDDED_FILE_SIZE');
      expect(mockClient.getFileContents).not.toHaveBeenCalled();
    });

    it('should refuse an image over MCP_MAX_EMBEDDED_FILE_SIZE without downloading it', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
      mockClient.stat.mockResolvedValue({ basename: 'big.jpg', mime: 'image/jpeg', size: 11 });

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/big.jpg' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain('File: big.jpg (image/jpeg, 11 bytes)');
      expect(result.content[0].text).toContain('MCP_MAX_EMBEDDED_FILE_SIZE');
      expect(mockClient.getFileStart).not.toHaveBeenCalled();
      expect(mockClient.getFileContents).not.toHaveBeenCalled();
    });

    it('should refuse an oversized image of unknown type by its signature', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
      mockClient.stat.mockResolvedValue({
        basename: 'photo',
        mime: 'application/octet-stream',
        size: 500_000_000,
      });
      mockClient.getFileStart.mockResolvedValue(
        new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])
      );

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/photo' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain('File: photo (image/png, 500000000 bytes)');
      expect(mockClient.getFileContents).not.toHaveBeenCalled();
    });

    it('should tell an oversized file of unknown type by its first bytes alone', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
      mockClient.stat.mockResolvedValue({
        basename: 'disk.img',
        mime: 'application/octet-stream',
        size: 5_000_000_000,
      });
      mockClient.getFileStart.mockResolvedValue(new Uint8Array([0xeb, 0x3c, 0x90, 0, 0, 0]));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/disk.img' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(mockClient.getFileStart).toHaveBeenCalledWith('/disk.img', 8192);
      expect(result.content).toHaveLength(1);
      expect(result.content[0].text).toContain(
        'File: disk.img (application/octet-stream, 5000000000 bytes)'
      );
      expect(mockClient.getFileContents).not.toHaveBeenCalled();
    });

    it('should name an oversized file by the signature in its first bytes', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
      mockClient.stat.mockResolvedValue({ basename: 'scan', mime: undefined, size: 4096 });
      mockClient.getFileStart.mockResolvedValue(new TextEncoder().encode('%PDF-1.4\n1 0 obj\n'));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/scan' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content[0].text).toContain('File: scan (application/pdf, 4096 bytes)');
      expect(mockClient.getFileContents).not.toHaveBeenCalled();
    });

    it('should download an oversized file of unknown type whose first bytes are text', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '4';
      const log = new TextEncoder().encode('line 1\nline 2\n');
      mockClient.stat.mockResolvedValue({
        basename: 'server.log',
        mime: 'application/octet-stream',
        size: log.length,
      });
      mockClient.getFileStart.mockResolvedValue(log);
      mockClient.getFileContents.mockResolvedValue(log);

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/server.log' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toEqual([{ type: 'text', text: 'line 1\nline 2\n' }]);
    });

    it('should still return a text file over MCP_MAX_EMBEDDED_FILE_SIZE', async () => {
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '4';
      mockClient.stat.mockResolvedValue({ basename: 'a.txt', mime: 'text/plain', size: 13 });
      mockClient.getFileStart.mockResolvedValue(new TextEncoder().encode('Hello, World!'));
      mockClient.getFileContents.mockResolvedValue(new TextEncoder().encode('Hello, World!'));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/a.txt' });
      delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;

      expect(result.content).toEqual([{ type: 'text', text: 'Hello, World!' }]);
    });

    it('should return a PDF with no NUL in its first bytes as a file, not as text', async () => {
      const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n');
      mockClient.stat.mockResolvedValue({
        basename: 'plain.pdf',
        mime: 'application/pdf',
        size: pdf.length,
      });
      mockClient.getFileContents.mockResolvedValue(new Uint8Array(pdf));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/plain.pdf' });

      expect(result.content[1]).toEqual({
        type: 'resource',
        resource: {
          uri: 'nextcloud:///plain.pdf',
          mimeType: 'application/pdf',
          blob: pdf.toString('base64'),
        },
      });
    });

    it('should return a binary file whole, as an embedded resource', async () => {
      const pdf = Buffer.concat([Buffer.from('%PDF-1.7\n'), Buffer.from([0, 1, 2, 3, 255])]);
      mockClient.getFileContents.mockResolvedValue(new Uint8Array(pdf));
      mockClient.stat.mockResolvedValue({
        basename: 'Report 1.pdf',
        mime: 'application/pdf',
        size: pdf.length,
      });

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/Documents/Report 1.pdf' });

      expect(result.content).toHaveLength(2);
      expect(result.content[0].text).toContain('Report 1.pdf (application/pdf, 14 bytes)');
      expect(result.content[1]).toEqual({
        type: 'resource',
        resource: {
          uri: 'nextcloud:///Documents/Report%201.pdf',
          mimeType: 'application/pdf',
          blob: pdf.toString('base64'),
        },
      });
    });

    it('should return a binary image as image content', async () => {
      const png = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0]);
      mockClient.getFileContents.mockResolvedValue(new Uint8Array(png));
      mockClient.stat.mockResolvedValue({ basename: 'a.png', mime: 'image/png' });

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/a.png' });

      expect(result.content[1]).toEqual({
        type: 'image',
        data: png.toString('base64'),
        mimeType: 'image/png',
      });
    });

    it('should name the type from the extension when Nextcloud cannot be asked', async () => {
      mockClient.getFileContents.mockResolvedValue(new Uint8Array([0x50, 0x4b, 3, 4, 0, 0]));
      mockClient.stat.mockRejectedValue(new Error('Invalid response: 500'));

      const { readFileTool } = await import('../tools/system/files.js');
      const result = await readFileTool.handler({ path: '/Notes/minutes.docx' });

      expect(result.content[1].resource.mimeType).toBe(
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
      );
    });
  });

  describe('write_file', () => {
    it('should write file contents', async () => {
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { writeFileTool } = await import('../tools/system/files.js');
      const result = await writeFileTool.handler({ path: '/test.txt', content: 'New content' });

      expect(result.content[0].text).toContain('/test.txt');
      expect(mockClient.putFileContents).toHaveBeenCalledWith('/test.txt', 'New content', {
        overwrite: true,
      });
    });

    it('should write the decoded bytes of base64 content', async () => {
      mockClient.putFileContents.mockResolvedValue(undefined);
      const bytes = new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d, 0x00, 0xff, 0xfe]);

      const { writeFileTool } = await import('../tools/system/files.js');
      const result = await writeFileTool.handler({
        path: '/a.pdf',
        content: Buffer.from(bytes).toString('base64'),
        encoding: 'base64',
      });

      expect(result).not.toHaveProperty('isError');
      expect(result.content[0].text).toContain('/a.pdf (8 bytes, decoded from base64)');
      expect(mockClient.putFileContents).toHaveBeenCalledWith('/a.pdf', bytes, {
        overwrite: true,
      });
    });

    it('should store content as text when encoding is "text" or absent', async () => {
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { writeFileTool } = await import('../tools/system/files.js');
      await writeFileTool.handler({ path: '/a.txt', content: 'JVBERi0A//4=', encoding: 'text' });

      expect(mockClient.putFileContents).toHaveBeenCalledWith('/a.txt', 'JVBERi0A//4=', {
        overwrite: true,
      });
    });

    it.each([
      ['a character outside the alphabet', 'JVBE Ri0='],
      ['a line break', 'JVBERi0A\n//4='],
      ['the URL-safe alphabet', 'JVBERi0A__4='],
      ['missing padding', 'JVBERi0A//4'],
      ['pad bits that are not zero', 'JVBERi0A//5='],
    ])('should refuse base64 with %s and write nothing (RFC 4648 §3)', async (_, content) => {
      const { writeFileTool } = await import('../tools/system/files.js');
      const result = await writeFileTool.handler({ path: '/a.pdf', content, encoding: 'base64' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not valid base64');
      expect(mockClient.putFileContents).not.toHaveBeenCalled();
    });

    it('should accept an empty base64 content as an empty file', async () => {
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { writeFileTool } = await import('../tools/system/files.js');
      const result = await writeFileTool.handler({
        path: '/empty',
        content: '',
        encoding: 'base64',
      });

      expect(result.content[0].text).toContain('(0 bytes, decoded from base64)');
    });

    it('should reject content exceeding MCP_MAX_FILE_SIZE', async () => {
      // Set a small limit for testing
      process.env.MCP_MAX_FILE_SIZE = '10';
      // Re-import to pick up the new env var
      vi.resetModules();
      const { FileContentSchema } = await import('../tools/types.js');

      const result = FileContentSchema.safeParse({ path: '/test.txt', content: 'a'.repeat(11) });
      expect(result.success).toBe(false);

      // Clean up
      delete process.env.MCP_MAX_FILE_SIZE;
      vi.resetModules();
    });

    it('should accept content within MCP_MAX_FILE_SIZE', async () => {
      process.env.MCP_MAX_FILE_SIZE = '10';
      vi.resetModules();
      const { FileContentSchema } = await import('../tools/types.js');

      const result = FileContentSchema.safeParse({ path: '/test.txt', content: 'a'.repeat(10) });
      expect(result.success).toBe(true);

      delete process.env.MCP_MAX_FILE_SIZE;
      vi.resetModules();
    });
  });

  describe('move_file', () => {
    it('should move a file successfully', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);

      const { moveFileTool } = await import('../tools/system/files.js');
      const result = await moveFileTool.handler({
        source: '/docs/old.txt',
        destination: '/docs/new.txt',
        overwrite: false,
      });

      expect(mockClient.moveFile).toHaveBeenCalledWith('/docs/old.txt', '/docs/new.txt', {
        overwrite: false,
      });
      expect(result.content[0].text).toContain('Moved successfully');
    });

    it('should forward overwrite parameter', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);

      const { moveFileTool } = await import('../tools/system/files.js');
      await moveFileTool.handler({
        source: '/a.txt',
        destination: '/b.txt',
        overwrite: true,
      });

      expect(mockClient.moveFile).toHaveBeenCalledWith('/a.txt', '/b.txt', { overwrite: true });
    });

    it('should handle errors', async () => {
      mockClient.moveFile.mockRejectedValue(new Error('404 Not Found'));

      const { moveFileTool } = await import('../tools/system/files.js');
      const result = await moveFileTool.handler({
        source: '/nonexistent.txt',
        destination: '/dest.txt',
        overwrite: false,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404 Not Found');
    });
  });

  describe('copy_file', () => {
    it('should copy a file successfully', async () => {
      mockClient.copyFile.mockResolvedValue(undefined);

      const { copyFileTool } = await import('../tools/system/files.js');
      const result = await copyFileTool.handler({
        source: '/docs/original.txt',
        destination: '/docs/copy.txt',
        overwrite: false,
      });

      expect(mockClient.copyFile).toHaveBeenCalledWith('/docs/original.txt', '/docs/copy.txt', {
        overwrite: false,
      });
      expect(result.content[0].text).toContain('Copied successfully');
    });

    it('should forward overwrite parameter', async () => {
      mockClient.copyFile.mockResolvedValue(undefined);

      const { copyFileTool } = await import('../tools/system/files.js');
      await copyFileTool.handler({
        source: '/a.txt',
        destination: '/b.txt',
        overwrite: true,
      });

      expect(mockClient.copyFile).toHaveBeenCalledWith('/a.txt', '/b.txt', { overwrite: true });
    });

    it('should handle errors', async () => {
      mockClient.copyFile.mockRejectedValue(new Error('404 Not Found'));

      const { copyFileTool } = await import('../tools/system/files.js');
      const result = await copyFileTool.handler({
        source: '/nonexistent.txt',
        destination: '/dest.txt',
        overwrite: false,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404 Not Found');
    });
  });

  describe('create_folder', () => {
    it('should call createDirectory and return success', async () => {
      mockClient.createDirectory.mockResolvedValue(undefined);

      const { createFolderTool } = await import('../tools/system/files.js');
      const result = await createFolderTool.handler({ path: '/NewFolder' });
      expectStructured(result, createFolderTool);

      expect(mockClient.createDirectory).toHaveBeenCalledWith('/NewFolder');
      expect(result.content[0].text).toContain('Folder created successfully');
      expect(result.content[0].text).toContain('/NewFolder');
    });
  });

  describe('delete', () => {
    it('should call deleteFile and return success', async () => {
      mockClient.deleteFile.mockResolvedValue(undefined);

      const { deleteTool } = await import('../tools/system/files.js');
      const result = await deleteTool.handler({ path: '/old-file.txt' });

      expect(mockClient.deleteFile).toHaveBeenCalledWith('/old-file.txt');
      expect(result.content[0].text).toContain('Deleted successfully');
      expect(result.content[0].text).toContain('/old-file.txt');
    });
  });

  describe('create_task', () => {
    it('should generate valid VTODO', () => {
      const uid = 'test-123';
      const title = 'Buy groceries';
      const description = 'Milk, eggs, bread';
      const due = '20241225';

      let vtodo = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//AIquila//MCP//EN
BEGIN:VTODO
UID:${uid}
DTSTAMP:20241201T120000Z
SUMMARY:${title}`;
      if (description) vtodo += `\nDESCRIPTION:${description}`;
      if (due) vtodo += `\nDUE;VALUE=DATE:${due}`;
      vtodo += `\nEND:VTODO\nEND:VCALENDAR`;

      expect(vtodo).toContain('BEGIN:VCALENDAR');
      expect(vtodo).toContain('SUMMARY:Buy groceries');
      expect(vtodo).toContain('DESCRIPTION:Milk, eggs, bread');
      expect(vtodo).toContain('DUE;VALUE=DATE:20241225');
      expect(vtodo).toContain('END:VCALENDAR');
    });
  });

  describe('create_recipe', () => {
    it('should create recipe folder and recipe.json', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);
      mockClient.createDirectory.mockResolvedValue(undefined);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await createRecipeTool.handler({
        name: 'Pasta Carbonara',
        recipeIngredient: ['400g spaghetti', '200g pancetta'],
        recipeInstructions: ['Cook pasta', 'Fry pancetta'],
        recipeYield: 4,
      });

      expect(mockClient.createDirectory).toHaveBeenCalledWith('/Recipes/pasta-carbonara', {
        recursive: true,
      });
      expect(mockClient.putFileContents).toHaveBeenCalledWith(
        '/Recipes/pasta-carbonara/recipe.json',
        expect.stringContaining('"name": "Pasta Carbonara"'),
        { overwrite: true }
      );
      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('pasta-carbonara');
    });

    it("should write to the user's Cookbook folder and create it when missing", async () => {
      // GET /apps/cookbook/api/v1/config answers the per-user folder
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ folder: '/Kochbuch/' }),
      });
      mockClient.getDirectoryContents.mockRejectedValue(
        Object.assign(new Error('Not Found'), { status: 404 })
      );
      mockClient.createDirectory.mockResolvedValue(undefined);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await createRecipeTool.handler({ name: 'Soup' });

      expect(result.isError).toBeUndefined();
      expect(mockClient.createDirectory).toHaveBeenCalledWith('/Kochbuch/soup', {
        recursive: true,
      });
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/index.php/apps/cookbook/api/v1/reindex'),
        expect.objectContaining({ method: 'POST' })
      );
    });

    it('should append suffix for duplicate folder names', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
      ]);
      mockClient.createDirectory.mockResolvedValue(undefined);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await createRecipeTool.handler({ name: 'Pasta Carbonara' });

      expect(mockClient.createDirectory).toHaveBeenCalledWith('/Recipes/pasta-carbonara-2', {
        recursive: true,
      });
      expect(result.content[0].text).toContain('pasta-carbonara-2');
    });

    it('should build valid schema.org recipe JSON', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);
      mockClient.createDirectory.mockResolvedValue(undefined);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createRecipeTool } = await import('../tools/apps/cookbook.js');
      await createRecipeTool.handler({
        name: 'Test Recipe',
        recipeCategory: 'Easy',
        keywords: 'test,simple',
      });

      const writtenJson = JSON.parse(mockClient.putFileContents.mock.calls[0][1]);
      expect(writtenJson['@context']).toBe('http://schema.org');
      expect(writtenJson['@type']).toBe('Recipe');
      expect(writtenJson.name).toBe('Test Recipe');
      expect(writtenJson.recipeCategory).toBe('Easy');
      expect(writtenJson.keywords).toBe('test,simple');
      expect(writtenJson.id).toBeDefined();
      expect(writtenJson.dateCreated).toBeDefined();
    });
  });

  describe('create_note', () => {
    it('should generate markdown note', () => {
      const title = 'Meeting Notes';
      const noteContent = 'Discussed project timeline';

      const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      const content = `# ${title}\n\n${noteContent}`;

      expect(slug).toBe('meeting-notes');
      expect(content).toBe('# Meeting Notes\n\nDiscussed project timeline');
    });
  });
});
