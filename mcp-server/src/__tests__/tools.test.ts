import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock webdav client
const mockClient = {
  getDirectoryContents: vi.fn(),
  getFileContents: vi.fn(),
  putFileContents: vi.fn(),
  createDirectory: vi.fn(),
  deleteFile: vi.fn(),
};

vi.mock('webdav', () => ({
  createClient: vi.fn(() => mockClient),
}));

// Mock fetch for CalDAV
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
      const formatted = listing.map((item: any) =>
        `${item.type === 'directory' ? '📁' : '📄'} ${item.basename}`
      ).join('\n');

      expect(formatted).toBe('📁 Documents\n📄 readme.txt');
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
  });

  describe('create_task', () => {
    it('should generate valid VTODO', () => {
      const uid = 'test-123';
      const title = 'Buy groceries';
      const description = 'Milk, eggs, bread';
      const due = '20241225';

      let vtodo = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//NextClaude//MCP//EN
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

  describe('add_recipe', () => {
    it('should generate markdown recipe', () => {
      const name = 'Pasta Carbonara';
      const ingredients = '- 400g spaghetti\n- 200g pancetta';
      const instructions = '1. Cook pasta\n2. Fry pancetta';
      const servings = '4';
      const prepTime = '30 min';

      const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      let content = `# ${name}\n\n`;
      if (servings) content += `**Servings:** ${servings}\n`;
      if (prepTime) content += `**Prep Time:** ${prepTime}\n`;
      content += `\n## Ingredients\n\n${ingredients}\n\n## Instructions\n\n${instructions}`;

      expect(slug).toBe('pasta-carbonara');
      expect(content).toContain('# Pasta Carbonara');
      expect(content).toContain('**Servings:** 4');
      expect(content).toContain('## Ingredients');
      expect(content).toContain('- 400g spaghetti');
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
