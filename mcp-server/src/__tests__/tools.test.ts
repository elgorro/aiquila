import { describe, it, expect, vi, beforeEach } from 'vitest';
import type { FileStat } from 'webdav';

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

// Mock fetch for CalDAV and OCS
global.fetch = vi.fn();

// Mock the OCS client module
const mockFetchOCS = vi.fn();
const mockFetchStatus = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: (...args: unknown[]) => mockFetchStatus(...args),
}));

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

describe('OCS-based Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('system_status', () => {
    it('should return combined status and capabilities', async () => {
      mockFetchStatus.mockResolvedValue({
        installed: true,
        maintenance: false,
        version: '28.0.1.1',
        versionstring: '28.0.1',
        productname: 'Nextcloud',
      });
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { version: { major: 28, minor: 0, micro: 1 } },
        },
      });

      const { systemStatusTool } = await import('../tools/system/status.js');
      const result = await systemStatusTool.handler();

      expect(result.content[0].text).toContain('28.0.1');
      expect(result.content[0].text).toContain('Nextcloud');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle errors gracefully', async () => {
      mockFetchStatus.mockRejectedValue(new Error('Connection refused'));

      const { systemStatusTool } = await import('../tools/system/status.js');
      const result = await systemStatusTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('list_apps', () => {
    it('should return list of apps', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { apps: ['files', 'activity', 'photos', 'tasks'] },
        },
      });

      const { listAppsTool } = await import('../tools/system/apps.js');
      const result = await listAppsTool.handler({});

      expect(result.content[0].text).toContain('files');
      expect(result.content[0].text).toContain('tasks');
      expect(result).not.toHaveProperty('isError');
    });

    it('should pass filter parameter', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { apps: ['survey_client'] },
        },
      });

      const { listAppsTool } = await import('../tools/system/apps.js');
      await listAppsTool.handler({ filter: 'disabled' });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/apps',
        { queryParams: { filter: 'disabled' } }
      );
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 403 Forbidden'));

      const { listAppsTool } = await import('../tools/system/apps.js');
      const result = await listAppsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('403');
    });
  });

  describe('get_app_info', () => {
    it('should return app details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 'tasks', name: 'Tasks', version: '0.15.0' },
        },
      });

      const { getAppInfoTool } = await import('../tools/system/apps.js');
      const result = await getAppInfoTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks');
      expect(result.content[0].text).toContain('0.15.0');
    });
  });

  describe('enable_app', () => {
    it('should enable an app successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { enableAppTool } = await import('../tools/system/apps.js');
      const result = await enableAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('enabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/apps/tasks',
        { method: 'POST' }
      );
    });
  });

  describe('disable_app', () => {
    it('should disable an app successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { disableAppTool } = await import('../tools/system/apps.js');
      const result = await disableAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('disabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/apps/tasks',
        { method: 'DELETE' }
      );
    });
  });

  describe('list_users', () => {
    it('should return list of users', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['admin', 'alice', 'bob'] },
        },
      });

      const { listUsersTool } = await import('../tools/apps/users.js');
      const result = await listUsersTool.handler({});

      expect(result.content[0].text).toContain('admin');
      expect(result.content[0].text).toContain('alice');
      expect(result.content[0].text).toContain('3');
    });

    it('should pass search and pagination params', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['alice'] },
        },
      });

      const { listUsersTool } = await import('../tools/apps/users.js');
      await listUsersTool.handler({ search: 'ali', limit: 10, offset: 0 });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/users',
        { queryParams: { search: 'ali', limit: '10', offset: '0' } }
      );
    });
  });

  describe('get_user_info', () => {
    it('should return user details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 'alice',
            displayname: 'Alice Smith',
            email: 'alice@example.com',
            enabled: true,
            groups: ['admin', 'users'],
          },
        },
      });

      const { getUserInfoTool } = await import('../tools/apps/users.js');
      const result = await getUserInfoTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('Alice Smith');
      expect(result.content[0].text).toContain('alice@example.com');
    });

    it('should handle nonexistent user', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { getUserInfoTool } = await import('../tools/apps/users.js');
      const result = await getUserInfoTool.handler({ userId: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('enable_user', () => {
    it('should enable a user successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { enableUserTool } = await import('../tools/apps/users.js');
      const result = await enableUserTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('enabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/users/alice/enable',
        { method: 'PUT' }
      );
    });
  });

  describe('disable_user', () => {
    it('should disable a user successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { disableUserTool } = await import('../tools/apps/users.js');
      const result = await disableUserTool.handler({ userId: 'alice' });

      expect(result.content[0].text).toContain('disabled');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/users/alice/disable',
        { method: 'PUT' }
      );
    });
  });

  describe('list_groups', () => {
    it('should return list of groups', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { groups: ['admin', 'users', 'marketing'] },
        },
      });

      const { listGroupsTool } = await import('../tools/apps/groups.js');
      const result = await listGroupsTool.handler({});

      expect(result.content[0].text).toContain('admin');
      expect(result.content[0].text).toContain('marketing');
      expect(result.content[0].text).toContain('3');
    });
  });

  describe('get_group_info', () => {
    it('should return group members', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { users: ['alice', 'bob'] },
        },
      });

      const { getGroupInfoTool } = await import('../tools/apps/groups.js');
      const result = await getGroupInfoTool.handler({ groupId: 'admin' });

      expect(result.content[0].text).toContain('alice');
      expect(result.content[0].text).toContain('bob');
      expect(result.content[0].text).toContain('admin');
    });
  });

  describe('add_user_to_group', () => {
    it('should add user to group successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { addUserToGroupTool } = await import('../tools/apps/groups.js');
      const result = await addUserToGroupTool.handler({ userId: 'alice', groupId: 'marketing' });

      expect(result.content[0].text).toContain('added');
      expect(result.content[0].text).toContain('marketing');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/users/alice/groups',
        { method: 'POST', body: { groupid: 'marketing' } }
      );
    });
  });

  describe('remove_user_from_group', () => {
    it('should remove user from group successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { removeUserFromGroupTool } = await import('../tools/apps/groups.js');
      const result = await removeUserFromGroupTool.handler({ userId: 'alice', groupId: 'marketing' });

      expect(result.content[0].text).toContain('removed');
      expect(result.content[0].text).toContain('marketing');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/cloud/users/alice/groups',
        { method: 'DELETE', body: { groupid: 'marketing' } }
      );
    });
  });

  describe('list_tasks', () => {
    it('should return formatted task list', async () => {
      const vtodoResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:propstat>
      <d:prop>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-1
SUMMARY:Buy groceries
STATUS:NEEDS-ACTION
PRIORITY:1
DESCRIPTION:Milk and eggs
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:propstat>
      <d:prop>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-2
SUMMARY:Clean house
STATUS:COMPLETED
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(vtodoResponse),
      });

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks' });

      expect(result.content[0].text).toContain('Buy groceries');
      expect(result.content[0].text).toContain('Clean house');
      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).toContain('Priority: 1');
      expect(result.content[0].text).toContain('Milk and eggs');
    });

    it('should filter tasks by status', async () => {
      const vtodoResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-1
SUMMARY:Open task
STATUS:NEEDS-ACTION
END:VTODO
END:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>
  <d:response><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-2
SUMMARY:Done task
STATUS:COMPLETED
END:VTODO
END:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(vtodoResponse),
      });

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks', status: 'COMPLETED' });

      expect(result.content[0].text).toContain('Done task');
      expect(result.content[0].text).not.toContain('Open task');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should handle empty task list', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks' });

      expect(result.content[0].text).toContain('No tasks found');
    });

    it('should handle errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('list_notes', () => {
    it('should return formatted note list', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'file', basename: 'Meeting Notes.md', size: 2150, lastmod: '2024-12-15' },
        { type: 'file', basename: 'Shopping List.md', size: 300, lastmod: '2025-01-10' },
        { type: 'directory', basename: 'subfolder', size: 0, lastmod: '2025-01-01' },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Shopping List');
      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).not.toContain('subfolder');
    });

    it('should filter notes by search term', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'file', basename: 'Meeting Notes.md', size: 2150, lastmod: '2024-12-15' },
        { type: 'file', basename: 'Shopping List.md', size: 300, lastmod: '2025-01-10' },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({ search: 'meeting' });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).not.toContain('Shopping List');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should handle empty notes folder', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('No notes found');
    });

    it('should handle errors', async () => {
      mockClient.getDirectoryContents.mockRejectedValue(new Error('WebDAV error'));

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('WebDAV error');
    });
  });

  describe('get_note', () => {
    it('should return note content', async () => {
      mockClient.getFileContents.mockResolvedValue('# Meeting Notes\n\nDiscussed project timeline');

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ title: 'Meeting Notes' });

      expect(result.content[0].text).toBe('# Meeting Notes\n\nDiscussed project timeline');
    });

    it('should handle nonexistent note', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ title: 'Nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('list_recipes', () => {
    it('should return formatted recipe list', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'file', basename: 'Pasta Carbonara.md', size: 1500, lastmod: '2024-11-20' },
        { type: 'file', basename: 'Chicken Curry.md', size: 2000, lastmod: '2024-12-05' },
      ]);

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should filter recipes by search term', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'file', basename: 'Pasta Carbonara.md', size: 1500, lastmod: '2024-11-20' },
        { type: 'file', basename: 'Chicken Curry.md', size: 2000, lastmod: '2024-12-05' },
      ]);

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ search: 'pasta' });

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).not.toContain('Chicken Curry');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should handle empty recipes folder', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('No recipes found');
    });

    it('should handle errors', async () => {
      mockClient.getDirectoryContents.mockRejectedValue(new Error('WebDAV error'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('WebDAV error');
    });
  });

  describe('get_recipe', () => {
    it('should return recipe content', async () => {
      const recipeContent = '# Pasta Carbonara\n\n## Ingredients\n\n- 400g spaghetti';
      mockClient.getFileContents.mockResolvedValue(recipeContent);

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ name: 'Pasta Carbonara' });

      expect(result.content[0].text).toBe(recipeContent);
    });

    it('should handle nonexistent recipe', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ name: 'Nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
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
});
