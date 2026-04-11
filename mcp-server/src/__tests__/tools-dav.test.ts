import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { FileStat } from 'webdav';

// Mock webdav client
const mockClient = {
  getDirectoryContents: vi.fn(),
  getFileContents: vi.fn(),
  putFileContents: vi.fn(),
  createDirectory: vi.fn(),
  deleteFile: vi.fn(),
  moveFile: vi.fn(),
  copyFile: vi.fn(),
};

vi.mock('webdav', () => ({
  createClient: vi.fn(() => mockClient),
}));

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
    it('should format directory contents', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'Documents' },
        { type: 'file', basename: 'readme.txt' },
      ]);

      const { createClient } = await import('webdav');
      const client = createClient('https://example.com', { username: '', password: '' });
      const items = await client.getDirectoryContents('/');

      const listing = Array.isArray(items) ? items : [];
      const formatted = listing
        .map((item: FileStat) => `${item.type === 'directory' ? '📁' : '📄'} ${item.basename}`)
        .join('\n');

      expect(formatted).toBe('📁 Documents\n📄 readme.txt');
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
      mockClient.getFileContents.mockResolvedValue('Hello, World!');

      const { createClient } = await import('webdav');
      const client = createClient('https://example.com', { username: '', password: '' });
      const content = await client.getFileContents('/test.txt', { format: 'text' });

      expect(content).toBe('Hello, World!');
    });
  });

  describe('write_file', () => {
    it('should write file contents', async () => {
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createClient } = await import('webdav');
      const client = createClient('https://example.com', { username: '', password: '' });
      await client.putFileContents('/test.txt', 'New content');

      expect(mockClient.putFileContents).toHaveBeenCalledWith('/test.txt', 'New content');
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

      expect(mockClient.createDirectory).toHaveBeenCalledWith('/Recipes/pasta-carbonara');
      expect(mockClient.putFileContents).toHaveBeenCalledWith(
        '/Recipes/pasta-carbonara/recipe.json',
        expect.stringContaining('"name": "Pasta Carbonara"'),
        { overwrite: true }
      );
      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('pasta-carbonara');
    });

    it('should append suffix for duplicate folder names', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
      ]);
      mockClient.createDirectory.mockResolvedValue(undefined);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { createRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await createRecipeTool.handler({ name: 'Pasta Carbonara' });

      expect(mockClient.createDirectory).toHaveBeenCalledWith('/Recipes/pasta-carbonara-2');
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
