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

// Mock the Mail API client module
const mockFetchMailAPI = vi.fn();

vi.mock('../client/mail.js', () => ({
  fetchMailAPI: (...args: unknown[]) => mockFetchMailAPI(...args),
}));

// Mock the Bookmarks API client module
const mockFetchBookmarksAPI = vi.fn();

vi.mock('../client/bookmarks.js', () => ({
  fetchBookmarksAPI: (...args: unknown[]) => mockFetchBookmarksAPI(...args),
}));

// Mock the Maps API client module
const mockFetchMapsExternalAPI = vi.fn();
const mockFetchMapsAPI = vi.fn();

vi.mock('../client/maps.js', () => ({
  fetchMapsExternalAPI: (...args: unknown[]) => mockFetchMapsExternalAPI(...args),
  fetchMapsAPI: (...args: unknown[]) => mockFetchMapsAPI(...args),
}));

// Mock the AIquila API client module
const mockExecuteOCC = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual = await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

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
        { overwrite: true },
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
    it('should return formatted task list with extended fields', async () => {
      const vtodoResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/testuser/tasks/task-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-1"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-1
SUMMARY:Buy groceries
STATUS:NEEDS-ACTION
PRIORITY:1
DESCRIPTION:Milk and eggs
DUE;VALUE=DATE:20240315
LOCATION:Supermarket
CATEGORIES:shopping,errands
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/testuser/tasks/task-2.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-2"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-2
SUMMARY:Clean house
STATUS:COMPLETED
COMPLETED:20240310T150000Z
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
      expect(result.content[0].text).toContain('Due: 2024-03-15');
      expect(result.content[0].text).toContain('Location: Supermarket');
      expect(result.content[0].text).toContain('Tags: shopping, errands');
      expect(result.content[0].text).toContain('UID: task-1');
      expect(result.content[0].text).toContain('UID: task-2');
      expect(result.content[0].text).toContain('Completed: 2024-03-10 15:00');
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

    it('should parse tasks when server uses cal: namespace prefix', async () => {
      const vtodoResponse = `<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:response><d:propstat><d:prop><d:getetag>"etag-cal"</d:getetag><cal:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-cal
SUMMARY:Namespace test
STATUS:NEEDS-ACTION
END:VTODO
END:VCALENDAR</cal:calendar-data></d:prop></d:propstat></d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(vtodoResponse),
      });

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks' });

      expect(result.content[0].text).toContain('Namespace test');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should parse tasks with subtask relationships and categories', async () => {
      const vtodoResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:child-task
SUMMARY:Subtask
STATUS:IN-PROCESS
PERCENT-COMPLETE:50
RELATED-TO;RELTYPE=PARENT:parent-uid-123
CATEGORIES:work
CATEGORIES:project-x
CLASS:PRIVATE
END:VTODO
END:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(vtodoResponse),
      });

      const { listTasksTool } = await import('../tools/apps/tasks.js');
      const result = await listTasksTool.handler({ calendarName: 'tasks' });

      expect(result.content[0].text).toContain('Subtask');
      expect(result.content[0].text).toContain('[50%]');
      expect(result.content[0].text).toContain('Parent: parent-uid-123');
      expect(result.content[0].text).toContain('Tags: work, project-x');
      expect(result.content[0].text).toContain('Class: PRIVATE');
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

  describe('update_task', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/testuser/tasks/task-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-abc"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VTODO
UID:task-1
SUMMARY:Old title
STATUS:NEEDS-ACTION
PRIORITY:5
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

    it('should update task fields', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateTaskTool } = await import('../tools/apps/tasks.js');
      const result = await updateTaskTool.handler({
        uid: 'task-1',
        calendarName: 'tasks',
        summary: 'New title',
        priority: 1,
      });

      expect(result.content[0].text).toContain('updated successfully');

      // Verify the PUT call had correct headers
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].method).toBe('PUT');
      expect(putCall[1].headers['If-Match']).toBe('"etag-abc"');
      expect(putCall[1].body).toContain('SUMMARY:New title');
      expect(putCall[1].body).toContain('PRIORITY:1');
    });

    it('should handle ETag conflict (412)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: false, status: 412, text: () => Promise.resolve('Precondition Failed') });

      const { updateTaskTool } = await import('../tools/apps/tasks.js');
      const result = await updateTaskTool.handler({
        uid: 'task-1',
        calendarName: 'tasks',
        summary: 'New title',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });

    it('should handle task not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { updateTaskTool } = await import('../tools/apps/tasks.js');
      const result = await updateTaskTool.handler({
        uid: 'nonexistent',
        calendarName: 'tasks',
        summary: 'test',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('delete_task', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/testuser/tasks/task-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-del"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-1
SUMMARY:Task to delete
STATUS:NEEDS-ACTION
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

    it('should delete a task', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { deleteTaskTool } = await import('../tools/apps/tasks.js');
      const result = await deleteTaskTool.handler({ uid: 'task-1', calendarName: 'tasks' });

      expect(result.content[0].text).toContain('deleted successfully');

      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-del"');
    });

    it('should handle task not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { deleteTaskTool } = await import('../tools/apps/tasks.js');
      const result = await deleteTaskTool.handler({ uid: 'nonexistent', calendarName: 'tasks' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('complete_task', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/testuser/tasks/task-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-comp"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VTODO
UID:task-1
SUMMARY:Task to complete
STATUS:NEEDS-ACTION
PERCENT-COMPLETE:50
END:VTODO
END:VCALENDAR</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

    it('should mark a task as completed', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { completeTaskTool } = await import('../tools/apps/tasks.js');
      const result = await completeTaskTool.handler({
        uid: 'task-1',
        calendarName: 'tasks',
        completed: true,
      });

      expect(result.content[0].text).toContain('completed successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('STATUS:COMPLETED');
      expect(putCall[1].body).toContain('PERCENT-COMPLETE:100');
      expect(putCall[1].body).toContain('COMPLETED:');
      expect(putCall[1].headers['If-Match']).toBe('"etag-comp"');
    });

    it('should reopen a completed task', async () => {
      const completedResponse = resolveResponse
        .replace('STATUS:NEEDS-ACTION', 'STATUS:COMPLETED')
        .replace('PERCENT-COMPLETE:50', 'PERCENT-COMPLETE:100\nCOMPLETED:20240310T150000Z');

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(completedResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { completeTaskTool } = await import('../tools/apps/tasks.js');
      const result = await completeTaskTool.handler({
        uid: 'task-1',
        calendarName: 'tasks',
        completed: false,
      });

      expect(result.content[0].text).toContain('reopened successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('STATUS:NEEDS-ACTION');
      expect(putCall[1].body).toContain('PERCENT-COMPLETE:0');
      expect(putCall[1].body).not.toMatch(/^COMPLETED:/m);
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
    const sampleRecipeJson = (name: string, category: string, keywords: string) =>
      JSON.stringify({
        id: '123', name, description: '', url: '', image: '',
        prepTime: 'PT30M', cookTime: 'PT1H', totalTime: 'PT1H30M',
        recipeCategory: category, keywords, recipeYield: 4,
        tool: [], recipeIngredient: [], recipeInstructions: [],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org', '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000', dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null, printImage: true, imageUrl: '',
      });

    it('should list recipe folders and parse recipe.json', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should filter recipes by search term', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ search: 'pasta' });

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).not.toContain('Chicken Curry');
      expect(result.content[0].text).toContain('1 found');
    });

    it('should filter recipes by category', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ category: 'indian' });

      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).not.toContain('Pasta Carbonara');
    });

    it('should filter recipes by keyword', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta-carbonara' },
        { type: 'directory', basename: 'chicken-curry' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Pasta Carbonara', 'Italian', 'pasta'))
        .mockResolvedValueOnce(sampleRecipeJson('Chicken Curry', 'Indian', 'curry,spicy'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({ keyword: 'spicy' });

      expect(result.content[0].text).toContain('Chicken Curry');
      expect(result.content[0].text).not.toContain('Pasta Carbonara');
    });

    it('should handle empty recipes folder', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([]);

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('No recipes found');
    });

    it('should skip folders without valid recipe.json', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'valid-recipe' },
        { type: 'directory', basename: 'broken-recipe' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(sampleRecipeJson('Valid Recipe', 'Easy', ''))
        .mockRejectedValueOnce(new Error('404 Not Found'));

      const { listRecipesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipesTool.handler({});

      expect(result.content[0].text).toContain('Valid Recipe');
      expect(result.content[0].text).toContain('1 found');
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
    it('should return formatted recipe details', async () => {
      const recipeJson = JSON.stringify({
        id: '123', name: 'Pasta Carbonara', description: 'Classic Italian',
        url: '', image: '', prepTime: 'PT15M', cookTime: 'PT20M', totalTime: 'PT35M',
        recipeCategory: 'Italian', keywords: 'pasta,quick',
        recipeYield: 4, tool: ['Pot'], recipeIngredient: ['400g spaghetti', '200g pancetta'],
        recipeInstructions: ['Cook pasta', 'Fry pancetta'],
        nutrition: { '@type': 'NutritionInformation', calories: '500 kJ' },
        '@context': 'http://schema.org', '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000', dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null, printImage: true, imageUrl: '',
      });
      mockClient.getFileContents.mockResolvedValue(recipeJson);

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ folderName: 'pasta-carbonara' });

      expect(result.content[0].text).toContain('Pasta Carbonara');
      expect(result.content[0].text).toContain('400g spaghetti');
      expect(result.content[0].text).toContain('Cook pasta');
      expect(result.content[0].text).toContain('Italian');
      expect(mockClient.getFileContents).toHaveBeenCalledWith(
        '/Recipes/pasta-carbonara/recipe.json',
        { format: 'text' },
      );
    });

    it('should handle nonexistent recipe', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { getRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await getRecipeTool.handler({ folderName: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('update_recipe', () => {
    it('should merge provided fields into existing recipe', async () => {
      const existingJson = JSON.stringify({
        id: '123', name: 'Pasta Carbonara', description: 'Classic',
        url: '', image: '', prepTime: 'PT15M', cookTime: 'PT20M', totalTime: 'PT35M',
        recipeCategory: 'Italian', keywords: 'pasta',
        recipeYield: 4, tool: [], recipeIngredient: ['400g spaghetti'],
        recipeInstructions: ['Cook pasta'],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org', '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000', dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null, printImage: true, imageUrl: '',
      });
      mockClient.getFileContents.mockResolvedValue(existingJson);
      mockClient.putFileContents.mockResolvedValue(undefined);

      const { updateRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await updateRecipeTool.handler({
        folderName: 'pasta-carbonara',
        description: 'Updated description',
        recipeYield: 6,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const writtenJson = JSON.parse(mockClient.putFileContents.mock.calls[0][1]);
      expect(writtenJson.description).toBe('Updated description');
      expect(writtenJson.recipeYield).toBe(6);
      expect(writtenJson.name).toBe('Pasta Carbonara'); // preserved
      expect(writtenJson.recipeIngredient).toEqual(['400g spaghetti']); // preserved
    });

    it('should handle nonexistent recipe', async () => {
      mockClient.getFileContents.mockRejectedValue(new Error('404 Not Found'));

      const { updateRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await updateRecipeTool.handler({
        folderName: 'nonexistent',
        name: 'New Name',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('delete_recipe', () => {
    it('should delete recipe folder', async () => {
      mockClient.deleteFile.mockResolvedValue(undefined);

      const { deleteRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await deleteRecipeTool.handler({ folderName: 'pasta-carbonara' });

      expect(mockClient.deleteFile).toHaveBeenCalledWith('/Recipes/pasta-carbonara');
      expect(result.content[0].text).toContain('deleted successfully');
    });

    it('should handle nonexistent recipe folder', async () => {
      mockClient.deleteFile.mockRejectedValue(new Error('404 Not Found'));

      const { deleteRecipeTool } = await import('../tools/apps/cookbook.js');
      const result = await deleteRecipeTool.handler({ folderName: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('list_recipe_categories', () => {
    it('should return unique categories', async () => {
      mockClient.getDirectoryContents.mockResolvedValue([
        { type: 'directory', basename: 'pasta' },
        { type: 'directory', basename: 'curry' },
        { type: 'directory', basename: 'salad' },
      ]);
      mockClient.getFileContents
        .mockResolvedValueOnce(JSON.stringify({ name: 'Pasta', recipeCategory: 'Italian', keywords: '', recipeYield: 0 }))
        .mockResolvedValueOnce(JSON.stringify({ name: 'Curry', recipeCategory: 'Indian', keywords: '', recipeYield: 0 }))
        .mockResolvedValueOnce(JSON.stringify({ name: 'Salad', recipeCategory: 'Italian', keywords: '', recipeYield: 0 }));

      const { listRecipeCategoriesTool } = await import('../tools/apps/cookbook.js');
      const result = await listRecipeCategoriesTool.handler();

      expect(result.content[0].text).toContain('Indian');
      expect(result.content[0].text).toContain('Italian');
      expect(result.content[0].text).toContain('2'); // 2 unique categories
    });
  });

  describe('list_calendars', () => {
    it('should return formatted calendar list', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/</d:href>
    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>
      <d:displayname>Personal</d:displayname>
      <cs:getctag>ctag-123</cs:getctag>
      <x1:calendar-color>#0082c9</x1:calendar-color>
      <x2:calendar-enabled>1</x2:calendar-enabled>
      <c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/work/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>
      <d:displayname>Work</d:displayname>
      <x2:calendar-enabled>0</x2:calendar-enabled>
      <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('#0082c9');
      expect(result.content[0].text).toContain('events');
      expect(result.content[0].text).toContain('tasks');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('(disabled)');
    });

    it('should parse component support with cal: namespace prefix', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
      <d:displayname>Personal</d:displayname>
      <x2:calendar-enabled>1</x2:calendar-enabled>
      <cal:supported-calendar-component-set><cal:comp name="VEVENT"/><cal:comp name="VTODO"/></cal:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('1 found');
      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('events');
      expect(result.content[0].text).toContain('tasks');
    });

    it('should handle empty calendar list', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/</d:href>
    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('No calendars found');
    });

    it('should handle errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('list_events', () => {
    const veventResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Team standup
DTSTART:20240315T090000Z
DTEND:20240315T093000Z
LOCATION:Conference Room A
DESCRIPTION:Daily standup meeting
STATUS:CONFIRMED
ORGANIZER;CN=Alice:mailto:alice@example.com
ATTENDEE;CN=Bob;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:bob@example.com
ATTENDEE;CN=Carol;ROLE=OPT-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:carol@example.com
CATEGORIES:work,meetings
RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-2.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev2"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-2
SUMMARY:Company holiday
DTSTART;VALUE=DATE:20240401
DTEND;VALUE=DATE:20240402
STATUS:CONFIRMED
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should return formatted event list with all details', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(veventResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('2 found');
      // Event 1: timed event with attendees and recurrence
      expect(result.content[0].text).toContain('Team standup');
      expect(result.content[0].text).toContain('2024-03-15 09:00');
      expect(result.content[0].text).toContain('2024-03-15 09:30');
      expect(result.content[0].text).toContain('Conference Room A');
      expect(result.content[0].text).toContain('Daily standup meeting');
      expect(result.content[0].text).toContain('Alice');
      expect(result.content[0].text).toContain('Bob');
      expect(result.content[0].text).toContain('ACCEPTED');
      expect(result.content[0].text).toContain('Carol');
      expect(result.content[0].text).toContain('NEEDS-ACTION');
      expect(result.content[0].text).toContain('Tags: work, meetings');
      expect(result.content[0].text).toContain('Every week');
      expect(result.content[0].text).toContain('UID: event-1');
      // Event 2: all-day event
      expect(result.content[0].text).toContain('Company holiday');
      expect(result.content[0].text).toContain('all day');
      expect(result.content[0].text).toContain('UID: event-2');
    });

    it('should parse events when server uses cal: namespace prefix', async () => {
      const calPrefixResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev1"</d:getetag>
      <cal:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-cal
SUMMARY:Namespace prefix test
DTSTART:20240315T090000Z
DTEND:20240315T093000Z
END:VEVENT
END:VCALENDAR</cal:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(calPrefixResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('1 found');
      expect(result.content[0].text).toContain('Namespace prefix test');
      expect(result.content[0].text).toContain('UID: event-cal');
    });

    it('should handle empty event list', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('No events found');
    });

    it('should handle errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('get_event', () => {
    const eventResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-get1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Project review
DTSTART:20240320T140000Z
DTEND:20240320T150000Z
DESCRIPTION:Quarterly project review with stakeholders
LOCATION:Board room
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should return event details', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(eventResponse),
      });

      const { getEventTool } = await import('../tools/apps/calendar.js');
      const result = await getEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.content[0].text).toContain('Project review');
      expect(result.content[0].text).toContain('2024-03-20 14:00');
      expect(result.content[0].text).toContain('Board room');
      expect(result.content[0].text).toContain('Quarterly project review');
      expect(result.content[0].text).toContain('UID: event-1');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { getEventTool } = await import('../tools/apps/calendar.js');
      const result = await getEventTool.handler({ uid: 'nonexistent', calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_event', () => {
    // Mock REPORT response for post-creation verification
    const verifyResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-verify"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:test-uid
SUMMARY:Test
DTSTART:20240315T120000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should create a timed event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(verifyResponse) });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Lunch meeting',
        calendarName: 'personal',
        dtstart: '20240315T120000Z',
        dtend: '20240315T130000Z',
        location: 'Cafe downtown',
        description: 'Discuss Q2 plans',
      });

      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('Lunch meeting');
      expect(result.content[0].text).toContain('UID:');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(putCall[1].method).toBe('PUT');
      expect(putCall[1].body).toContain('BEGIN:VEVENT');
      expect(putCall[1].body).toContain('SUMMARY:Lunch meeting');
      expect(putCall[1].body).toContain('DTSTART:20240315T120000Z');
      expect(putCall[1].body).toContain('DTEND:20240315T130000Z');
      expect(putCall[1].body).toContain('LOCATION:Cafe downtown');
      expect(putCall[1].body).toContain('DESCRIPTION:Discuss Q2 plans');
    });

    it('should create an all-day event with default end', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(verifyResponse) });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Holiday',
        calendarName: 'personal',
        dtstart: '20240401',
      });

      expect(result.content[0].text).toContain('created successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(putCall[1].body).toContain('DTSTART;VALUE=DATE:20240401');
      expect(putCall[1].body).toContain('DTEND;VALUE=DATE:20240402');
    });

    it('should create event with attendees and alarm', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(verifyResponse) });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Team sync',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
        attendees: [
          { email: 'bob@example.com', cn: 'Bob', role: 'REQ-PARTICIPANT' },
        ],
        alarm: 15,
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(putCall[1].body).toContain('ATTENDEE');
      expect(putCall[1].body).toContain('CN=Bob');
      expect(putCall[1].body).toContain('mailto:bob@example.com');
      expect(putCall[1].body).toContain('ORGANIZER');
      expect(putCall[1].body).toContain('BEGIN:VALARM');
      expect(putCall[1].body).toContain('TRIGGER:-PT15M');
      expect(putCall[1].body).toContain('END:VALARM');
    });

    it('should create event with recurrence rule', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(verifyResponse) });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Weekly standup',
        calendarName: 'personal',
        dtstart: '20240315T090000Z',
        rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(putCall[1].body).toContain('RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR');
    });

    it('should handle create failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 403,
        text: () => Promise.resolve('Forbidden'),
      });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Denied event',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('403');
    });
  });

  describe('update_event', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-upd1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
LOCATION:Room A
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should update event fields', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'New title',
        location: 'Room B',
      });

      expect(result.content[0].text).toContain('updated successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].method).toBe('PUT');
      expect(putCall[1].headers['If-Match']).toBe('"etag-upd1"');
      expect(putCall[1].body).toContain('SUMMARY:New title');
      expect(putCall[1].body).toContain('LOCATION:Room B');
    });

    it('should remove fields when set to null', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        location: null,
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).not.toMatch(/^LOCATION:/m);
    });

    it('should handle ETag conflict (412)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: false, status: 412, text: () => Promise.resolve('Precondition Failed') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'New title',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'nonexistent',
        calendarName: 'personal',
        summary: 'test',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('delete_event', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-del1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Event to delete
DTSTART:20240315T090000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should delete an event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.content[0].text).toContain('deleted successfully');

      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-del1"');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({ uid: 'nonexistent', calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should handle ETag conflict', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: false, status: 412, text: () => Promise.resolve('Precondition Failed') });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
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

  // ---------------------------------------------------------------------------
  // Contacts tools
  // ---------------------------------------------------------------------------

  describe('list_address_books', () => {
    it('should return formatted address book list', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><cr:addressbook/></d:resourcetype>
        <d:displayname>Contacts</d:displayname>
        <cs:getctag>ctag-123</cs:getctag>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/work/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/><cr:addressbook/></d:resourcetype>
        <d:displayname>Work</d:displayname>
        <cs:getctag>ctag-456</cs:getctag>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listAddressBooksTool } = await import('../tools/apps/contacts.js');
      const result = await listAddressBooksTool.handler({});

      expect(result.content[0].text).toContain('Contacts');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('2 found');
    });

    it('should handle no address books', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/</d:href>
    <d:propstat>
      <d:prop>
        <d:resourcetype><d:collection/></d:resourcetype>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listAddressBooksTool } = await import('../tools/apps/contacts.js');
      const result = await listAddressBooksTool.handler({});

      expect(result.content[0].text).toContain('No address books found');
    });
  });

  describe('list_contacts', () => {
    it('should return formatted contact list', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-1"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;;
EMAIL;TYPE=WORK:john@example.com
TEL;TYPE=CELL:+1234567890
ORG:ACME Corp
TITLE:Engineer
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-2.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-2"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-2
FN:Jane Smith
N:Smith;Jane;;;
EMAIL;TYPE=HOME:jane@example.com
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { listContactsTool } = await import('../tools/apps/contacts.js');
      const result = await listContactsTool.handler({ addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('Jane Smith');
      expect(result.content[0].text).toContain('John Doe');
      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).toContain('john@example.com');
      expect(result.content[0].text).toContain('+1234567890');
      expect(result.content[0].text).toContain('ACME Corp');
      expect(result.content[0].text).toContain('UID: contact-1');
    });

    it('should handle empty address book', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { listContactsTool } = await import('../tools/apps/contacts.js');
      const result = await listContactsTool.handler({ addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('No contacts found');
    });
  });

  describe('get_contact', () => {
    it('should return full contact details', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-1"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;Dr.;Jr.
EMAIL;TYPE=WORK:john@example.com
EMAIL;TYPE=HOME:john.doe@home.com
TEL;TYPE=CELL:+1234567890
TEL;TYPE=WORK:+0987654321
ADR;TYPE=WORK:;;123 Main St;Springfield;IL;62701;USA
ORG:ACME Corp
TITLE:Senior Engineer
NOTE:Important client contact
BDAY:1990-06-15
URL:https://johndoe.example.com
CATEGORIES:Friends,Work
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { getContactTool } = await import('../tools/apps/contacts.js');
      const result = await getContactTool.handler({ uid: 'contact-1', addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('Name: John Doe');
      expect(result.content[0].text).toContain('Dr. John Doe Jr.');
      expect(result.content[0].text).toContain('ACME Corp');
      expect(result.content[0].text).toContain('Senior Engineer');
      expect(result.content[0].text).toContain('john@example.com');
      expect(result.content[0].text).toContain('john.doe@home.com');
      expect(result.content[0].text).toContain('+1234567890');
      expect(result.content[0].text).toContain('+0987654321');
      expect(result.content[0].text).toContain('123 Main St');
      expect(result.content[0].text).toContain('Springfield');
      expect(result.content[0].text).toContain('Important client contact');
      expect(result.content[0].text).toContain('1990-06-15');
      expect(result.content[0].text).toContain('https://johndoe.example.com');
      expect(result.content[0].text).toContain('Friends, Work');
    });

    it('should handle contact not found', async () => {
      const reportResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(reportResponse),
      });

      const { getContactTool } = await import('../tools/apps/contacts.js');
      const result = await getContactTool.handler({ uid: 'nonexistent', addressBookName: 'contacts' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_contact', () => {
    it('should create a vCard with all fields', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        text: () => Promise.resolve(''),
      });

      const { createContactTool } = await import('../tools/apps/contacts.js');
      const result = await createContactTool.handler({
        fullName: 'John Doe',
        addressBookName: 'contacts',
        firstName: 'John',
        lastName: 'Doe',
        emails: [{ value: 'john@example.com', type: 'WORK' }],
        phones: [{ value: '+1234567890', type: 'CELL' }],
        org: 'ACME Corp',
        title: 'Engineer',
        note: 'Test contact',
        categories: ['Friends'],
      });

      expect(result.content[0].text).toContain('Contact created successfully');
      expect(result.content[0].text).toContain('John Doe');

      // Verify the PUT request body
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      const body = putCall[1].body;
      expect(body).toContain('BEGIN:VCARD');
      expect(body).toContain('VERSION:3.0');
      expect(body).toContain('FN:John Doe');
      expect(body).toContain('N:Doe;John;;');
      expect(body).toContain('EMAIL;TYPE=WORK:john@example.com');
      expect(body).toContain('TEL;TYPE=CELL:+1234567890');
      expect(body).toContain('ORG:ACME Corp');
      expect(body).toContain('TITLE:Engineer');
      expect(body).toContain('NOTE:Test contact');
      expect(body).toContain('CATEGORIES:Friends');
      expect(body).toContain('END:VCARD');
    });

    it('should create a minimal vCard with only full name', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        text: () => Promise.resolve(''),
      });

      const { createContactTool } = await import('../tools/apps/contacts.js');
      const result = await createContactTool.handler({
        fullName: 'Simple Contact',
        addressBookName: 'contacts',
      });

      expect(result.content[0].text).toContain('Contact created successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      const body = putCall[1].body;
      expect(body).toContain('FN:Simple Contact');
      expect(body).toContain('N:;;;');
      expect(body).not.toContain('EMAIL');
      expect(body).not.toContain('TEL');
    });
  });

  describe('update_contact', () => {
    it('should update contact fields with ETag concurrency', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-old"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
N:Doe;John;;;
EMAIL;TYPE=WORK:john@example.com
ORG:Old Corp
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 204,
          text: () => Promise.resolve(''),
        });
      });

      const { updateContactTool } = await import('../tools/apps/contacts.js');
      const result = await updateContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
        org: 'New Corp',
        title: 'CTO',
      });

      expect(result.content[0].text).toContain('Contact updated successfully');

      // Verify the PUT request
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].headers['If-Match']).toBe('"etag-old"');
      const body = putCall[1].body;
      expect(body).toContain('ORG:New Corp');
      expect(body).toContain('TITLE:CTO');
      expect(body).toContain('FN:John Doe');
    });

    it('should handle ETag conflict', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-old"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });
      });

      const { updateContactTool } = await import('../tools/apps/contacts.js');
      const result = await updateContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
        fullName: 'Updated Name',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });
  });

  describe('delete_contact', () => {
    it('should delete a contact', async () => {
      const resolveResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cr="urn:ietf:params:xml:ns:carddav">
  <d:response>
    <d:href>/remote.php/dav/addressbooks/users/testuser/contacts/contact-1.vcf</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"etag-1"</d:getetag>
        <cr:address-data>BEGIN:VCARD
VERSION:3.0
UID:contact-1
FN:John Doe
END:VCARD</cr:address-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>`;

      let callCount = 0;
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            text: () => Promise.resolve(resolveResponse),
          });
        }
        return Promise.resolve({
          ok: true,
          status: 204,
          text: () => Promise.resolve(''),
        });
      });

      const { deleteContactTool } = await import('../tools/apps/contacts.js');
      const result = await deleteContactTool.handler({ uid: 'contact-1', addressBookName: 'contacts' });

      expect(result.content[0].text).toContain('Contact deleted successfully');

      // Verify DELETE was called with correct ETag
      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-1"');
    });
  });
});

// ---------------------------------------------------------------------------
// Mail tools
// ---------------------------------------------------------------------------

describe('Mail Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_mail_accounts', () => {
    it('should return formatted account list', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([
          { id: 1, name: 'Personal', emailAddress: 'user@example.com' },
          { id: 2, name: 'Work', emailAddress: 'user@work.com' },
        ]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('user@example.com');
      expect(result.content[0].text).toContain('ID: 1');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('user@work.com');
    });

    it('should handle empty account list', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No mail accounts configured');
    });

    it('should handle API errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 500,
        text: () => Promise.resolve('Internal Server Error'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_mail_accounts')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('list_mailboxes', () => {
    it('should return formatted mailbox list', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([
          { id: 10, accountId: 1, name: 'INBOX', unread: 5, total: 120, delimiter: '/' },
          { id: 11, accountId: 1, name: 'Sent', unread: 0, total: 45, delimiter: '/' },
          { id: 12, accountId: 1, name: 'Drafts', unread: 2, total: 3, delimiter: '/' },
        ]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_mailboxes')!;
      const result = await tool.handler({ accountId: 1 });

      expect(result.content[0].text).toContain('INBOX');
      expect(result.content[0].text).toContain('5 unread');
      expect(result.content[0].text).toContain('ID: 10');
      expect(result.content[0].text).toContain('Sent');
      expect(result.content[0].text).toContain('Drafts');
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Account not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_mailboxes')!;
      const result = await tool.handler({ accountId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('list_messages', () => {
    it('should return formatted message summaries', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 100, uid: 1, mailboxId: 10, subject: 'Hello World',
              from: [{ label: 'Alice', email: 'alice@example.com' }],
              to: [{ label: 'Bob', email: 'bob@example.com' }],
              cc: [],
              dateInt: 1700000000,
              flags: { seen: false, flagged: true, answered: false, deleted: false, draft: false, important: false, junk: false },
              hasAttachments: true,
            },
            {
              id: 101, uid: 2, mailboxId: 10, subject: 'Meeting notes',
              from: [{ label: '', email: 'carol@example.com' }],
              to: [{ label: 'Bob', email: 'bob@example.com' }],
              cc: [],
              dateInt: 1700100000,
              flags: { seen: true, flagged: false, answered: true, deleted: false, draft: false, important: false, junk: false },
              hasAttachments: false,
            },
          ],
        },
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

      expect(result.content[0].text).toContain('Hello World');
      expect(result.content[0].text).toContain('UNREAD');
      expect(result.content[0].text).toContain('starred');
      expect(result.content[0].text).toContain('attachment');
      expect(result.content[0].text).toContain('Alice');
      expect(result.content[0].text).toContain('ID: 100');
      expect(result.content[0].text).toContain('Meeting notes');
      expect(result.content[0].text).toContain('replied');
      expect(result.content[0].text).toContain('Messages (2)');
    });

    it('should handle empty results', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [],
        },
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_messages')!;
      const result = await tool.handler({ mailboxId: 10 });

      expect(result.content[0].text).toContain('No messages found');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'list_messages')!;
      const result = await tool.handler({ mailboxId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('read_message', () => {
    it('should return full message content', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 100,
            subject: 'Test Subject',
            from: [{ label: 'Alice', email: 'alice@example.com' }],
            to: [{ label: 'Bob', email: 'bob@example.com' }],
            cc: [{ label: 'Carol', email: 'carol@example.com' }],
            dateInt: 1700000000,
            attachments: [{ fileName: 'report.pdf', size: 12345 }],
          },
        },
      });

      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ body: '<p>Hello <b>World</b></p><br>Nice to meet you.' }),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'read_message')!;
      const result = await tool.handler({ messageId: 100 });

      expect(result.content[0].text).toContain('Subject: Test Subject');
      expect(result.content[0].text).toContain('Alice <alice@example.com>');
      expect(result.content[0].text).toContain('Bob <bob@example.com>');
      expect(result.content[0].text).toContain('Cc: Carol <carol@example.com>');
      expect(result.content[0].text).toContain('Hello World');
      expect(result.content[0].text).toContain('Nice to meet you');
      expect(result.content[0].text).toContain('report.pdf');
      expect(result.content[0].text).toContain('12345 bytes');
      expect(result.content[0].text).toContain('Message ID: 100');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Message not found'));

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'read_message')!;
      const result = await tool.handler({ messageId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Message not found');
    });
  });

  describe('send_message', () => {
    it('should send email successfully', async () => {
      // First call: GET /accounts to find fromEmail
      // Second call: POST /messages/send
      let callCount = 0;
      mockFetchMailAPI.mockImplementation(() => {
        callCount++;
        if (callCount === 1) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve([
              { id: 1, name: 'Personal', emailAddress: 'me@example.com' },
            ]),
          });
        }
        return Promise.resolve({ ok: true, status: 200 });
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'send_message')!;
      const result = await tool.handler({
        accountId: 1,
        to: ['alice@example.com'],
        subject: 'Test',
        body: 'Hello!',
      });

      expect(result.content[0].text).toContain('Email sent successfully');
      expect(result.content[0].text).toContain('alice@example.com');

      // Verify send was called with correct params
      expect(mockFetchMailAPI).toHaveBeenCalledTimes(2);
      const sendCall = mockFetchMailAPI.mock.calls[1];
      expect(sendCall[0]).toBe('/messages/send');
      expect(sendCall[1].method).toBe('POST');
      expect(sendCall[1].body.fromEmail).toBe('me@example.com');
      expect(sendCall[1].body.subject).toBe('Test');
    });

    it('should handle account not found', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve([]),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'send_message')!;
      const result = await tool.handler({
        accountId: 999,
        to: ['alice@example.com'],
        subject: 'Test',
        body: 'Hello!',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Account with ID 999 not found');
    });
  });

  describe('delete_message', () => {
    it('should delete a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'delete_message')!;
      const result = await tool.handler({ messageId: 100 });

      expect(result.content[0].text).toContain('Message 100 deleted');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100', { method: 'DELETE' });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 404,
        text: () => Promise.resolve('Not found'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'delete_message')!;
      const result = await tool.handler({ messageId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });
  });

  describe('move_message', () => {
    it('should move a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'move_message')!;
      const result = await tool.handler({ messageId: 100, destMailboxId: 20 });

      expect(result.content[0].text).toContain('Message 100 moved to mailbox 20');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/move', {
        method: 'POST',
        body: { destFolderId: 20 },
      });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 400,
        text: () => Promise.resolve('Invalid destination'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'move_message')!;
      const result = await tool.handler({ messageId: 100, destMailboxId: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('400');
    });
  });

  describe('set_message_flags', () => {
    it('should set flags on a message', async () => {
      mockFetchMailAPI.mockResolvedValue({ ok: true, status: 200 });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'set_message_flags')!;
      const result = await tool.handler({ messageId: 100, flags: { seen: true, flagged: true } });

      expect(result.content[0].text).toContain('Flags updated on message 100');
      expect(result.content[0].text).toContain('seen=true');
      expect(result.content[0].text).toContain('flagged=true');
      expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/flags', {
        method: 'PUT',
        body: { seen: true, flagged: true },
      });
    });

    it('should handle errors', async () => {
      mockFetchMailAPI.mockResolvedValue({
        ok: false,
        status: 500,
        text: () => Promise.resolve('Server error'),
      });

      const { mailTools } = await import('../tools/apps/mail.js');
      const tool = mailTools.find(t => t.name === 'set_message_flags')!;
      const result = await tool.handler({ messageId: 100, flags: { seen: true } });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });
});

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
            id: 1, url: 'https://example.com', target: '', title: 'Example',
            description: 'An example site', added: 1700000000, userId: 'testuser',
            tags: ['tech', 'reference'], folders: [5], clickcount: 3, available: true,
            archivedFile: null,
          },
          {
            id: 2, url: 'https://news.ycombinator.com', target: '', title: 'Hacker News',
            description: '', added: 1700100000, userId: 'testuser',
            tags: ['news'], folders: [5, 10], clickcount: 15, available: true,
            archivedFile: null,
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'list_bookmarks')!;
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
      const tool = bookmarksTools.find(t => t.name === 'list_bookmarks')!;
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
      const tool = bookmarksTools.find(t => t.name === 'list_bookmarks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No bookmarks found');
    });

    it('should handle API errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 500: Internal Server Error'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'list_bookmarks')!;
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
          id: 1, url: 'https://example.com', target: '', title: 'Example',
          description: 'An example site', added: 1700000000, userId: 'testuser',
          tags: ['tech'], folders: [5], clickcount: 3, available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'get_bookmark')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Example');
      expect(result.content[0].text).toContain('https://example.com');
      expect(result.content[0].text).toContain('An example site');
      expect(result.content[0].text).toContain('ID: 1');
    });

    it('should handle nonexistent bookmark', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Not Found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'get_bookmark')!;
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
          id: 42, url: 'https://new-site.com', target: '', title: 'New Site',
          description: 'A new bookmark', added: 1700200000, userId: 'testuser',
          tags: ['new'], folders: [5], clickcount: 0, available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'create_bookmark')!;
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
      const tool = bookmarksTools.find(t => t.name === 'create_bookmark')!;
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
          id: 1, url: 'https://example.com', target: '', title: 'Updated Title',
          description: '', added: 1700000000, userId: 'testuser',
          tags: ['updated'], folders: [5], clickcount: 3, available: true,
          archivedFile: null,
        },
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'update_bookmark')!;
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
      const tool = bookmarksTools.find(t => t.name === 'delete_bookmark')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/bookmark/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Not Found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'delete_bookmark')!;
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
            id: 2, title: 'News', parent_folder: -1, userId: 'testuser',
            children: [
              { id: 3, title: 'Daily', parent_folder: 2, userId: 'testuser', children: [] },
            ],
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'list_bookmark_folders')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Tech');
      expect(result.content[0].text).toContain('ID: 1');
      expect(result.content[0].text).toContain('News');
      expect(result.content[0].text).toContain('Daily');
    });

    it('should handle empty folder list', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'list_bookmark_folders')!;
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
            id: 10, url: 'https://example.com', target: '', title: 'Example',
            description: '', added: 1700000000, userId: 'testuser',
            tags: [], folders: [1], clickcount: 0, available: true, archivedFile: null,
          },
        ],
      });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'get_bookmark_folder_contents')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('2 items');
      expect(result.content[0].text).toContain('Subfolder');
      expect(result.content[0].text).toContain('Example');
    });

    it('should handle empty folder', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success', data: [] });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'get_bookmark_folder_contents')!;
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
      const tool = bookmarksTools.find(t => t.name === 'create_bookmark_folder')!;
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
      const tool = bookmarksTools.find(t => t.name === 'create_bookmark_folder')!;
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
      const tool = bookmarksTools.find(t => t.name === 'update_bookmark_folder')!;
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
      const tool = bookmarksTools.find(t => t.name === 'delete_bookmark_folder')!;
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
      const tool = bookmarksTools.find(t => t.name === 'list_bookmark_tags')!;
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
      const tool = bookmarksTools.find(t => t.name === 'list_bookmark_tags')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No bookmark tags found');
    });
  });

  describe('rename_bookmark_tag', () => {
    it('should rename a tag successfully', async () => {
      mockFetchBookmarksAPI.mockResolvedValue({ status: 'success' });

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'rename_bookmark_tag')!;
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
      const tool = bookmarksTools.find(t => t.name === 'delete_bookmark_tag')!;
      const result = await tool.handler({ name: 'oldtag' });

      expect(result.content[0].text).toContain('deleted successfully');
      expect(mockFetchBookmarksAPI).toHaveBeenCalledWith('/tag/oldtag', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchBookmarksAPI.mockRejectedValue(new Error('Bookmarks API 404: Tag not found'));

      const { bookmarksTools } = await import('../tools/apps/bookmarks.js');
      const tool = bookmarksTools.find(t => t.name === 'delete_bookmark_tag')!;
      const result = await tool.handler({ name: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting tag');
    });
  });
});

describe('Maps Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  // ── Favorites ──────────────────────────────────────────────────────────

  describe('list_map_favorites', () => {
    it('should return formatted favorites list', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        {
          id: 1, name: 'Home', lat: 52.52, lng: 13.405, category: 'Personal',
          comment: 'My home', extensions: '', date_created: 1700000000, date_modified: 1700000000,
        },
        {
          id: 2, name: 'Office', lat: 48.8566, lng: 2.3522, category: 'Work',
          comment: '', extensions: '', date_created: 1700100000, date_modified: 1700100000,
        },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Home');
      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('Office');
      expect(result.content[0].text).toContain('2');
    });

    it('should pass pruneBefore param', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_favorites')!;
      await tool.handler({ pruneBefore: 1700000000 });

      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites', {
        queryParams: { pruneBefore: '1700000000' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map favorites found');
    });

    it('should handle API errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 500: Internal Server Error'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_favorites')!;
      const result = await tool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('500');
    });
  });

  describe('create_map_favorite', () => {
    it('should create a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({
        id: 10, name: 'Cafe', lat: 52.52, lng: 13.405, category: 'Food',
        comment: 'Great coffee', extensions: '', date_created: 1700200000, date_modified: 1700200000,
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'create_map_favorite')!;
      const result = await tool.handler({
        name: 'Cafe', lat: 52.52, lng: 13.405, category: 'Food', comment: 'Great coffee',
      });

      expect(result.content[0].text).toContain('Favorite created');
      expect(result.content[0].text).toContain('ID: 10');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites', {
        method: 'POST',
        body: { name: 'Cafe', lat: 52.52, lng: 13.405, category: 'Food', comment: 'Great coffee' },
      });
    });

    it('should handle creation errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Bad Request'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'create_map_favorite')!;
      const result = await tool.handler({ lat: NaN, lng: NaN });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating map favorite');
    });
  });

  describe('update_map_favorite', () => {
    it('should update a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({
        id: 1, name: 'Updated Name', lat: 52.52, lng: 13.405, category: 'Personal',
        comment: '', extensions: '', date_created: 1700000000, date_modified: 1700300000,
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'update_map_favorite')!;
      const result = await tool.handler({ id: 1, name: 'Updated Name' });

      expect(result.content[0].text).toContain('Favorite 1 updated');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites/1', {
        method: 'PUT',
        body: { name: 'Updated Name' },
      });
    });
  });

  describe('delete_map_favorite', () => {
    it('should delete a favorite successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Favorite 1 deleted');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/favorites/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map_favorite')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting map favorite');
    });
  });

  // ── Devices ────────────────────────────────────────────────────────────

  describe('list_map_devices', () => {
    it('should return formatted device list', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        { id: 1, user_agent: 'PhoneTrack/1.0', color: '#ff0000' },
        { id: 2, user_agent: 'OwnTracks/2.0', color: '#00ff00' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_devices')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('PhoneTrack/1.0');
      expect(result.content[0].text).toContain('OwnTracks/2.0');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_devices')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No map devices found');
    });
  });

  describe('get_map_device_points', () => {
    it('should return device location points', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([
        { id: 1, lat: 52.52, lng: 13.405, timestamp: 1700000000, altitude: 35, accuracy: 10 },
        { id: 2, lat: 52.53, lng: 13.41, timestamp: 1700003600 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'get_map_device_points')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('alt: 35m');
      expect(result.content[0].text).toContain('2');
    });

    it('should pass pruneBefore param', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'get_map_device_points')!;
      await tool.handler({ id: 1, pruneBefore: 1700000000 });

      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', {
        queryParams: { pruneBefore: '1700000000' },
      });
    });

    it('should handle empty results', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'get_map_device_points')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('No points found');
    });
  });

  describe('add_map_device_point', () => {
    it('should log a GPS point successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({ deviceId: 1, pointId: 42 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'add_map_device_point')!;
      const result = await tool.handler({
        lat: 52.52, lng: 13.405, user_agent: 'TestDevice', altitude: 35,
      });

      expect(result.content[0].text).toContain('device ID: 1');
      expect(result.content[0].text).toContain('point ID: 42');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices', {
        method: 'POST',
        body: { lat: 52.52, lng: 13.405, user_agent: 'TestDevice', altitude: 35 },
      });
    });
  });

  describe('update_map_device', () => {
    it('should update device color', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue({ id: 1, user_agent: 'TestDevice', color: '#0000ff' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'update_map_device')!;
      const result = await tool.handler({ id: 1, color: '#0000ff' });

      expect(result.content[0].text).toContain('Device 1 updated');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', {
        method: 'PUT',
        body: { color: '#0000ff' },
      });
    });
  });

  describe('delete_map_device', () => {
    it('should delete a device successfully', async () => {
      mockFetchMapsExternalAPI.mockResolvedValue('DELETED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map_device')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Device 1 deleted');
      expect(mockFetchMapsExternalAPI).toHaveBeenCalledWith('/devices/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsExternalAPI.mockRejectedValue(new Error('Maps API 400: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map_device')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting device');
    });
  });

  // ── Tracks ─────────────────────────────────────────────────────────────

  describe('list_map_tracks', () => {
    it('should return formatted track list', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, file_id: 100, color: '#ff0000' },
        { id: 2, file_id: 101, color: '#00ff00' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_tracks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Track ID: 1');
      expect(result.content[0].text).toContain('Track ID: 2');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_tracks')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No tracks found');
    });

    it('should pass myMapId param', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_tracks')!;
      await tool.handler({ myMapId: 5 });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/tracks', {
        queryParams: { myMapId: '5' },
      });
    });
  });

  describe('get_map_track', () => {
    it('should return track detail with content', async () => {
      mockFetchMapsAPI.mockResolvedValue({
        metadata: { name: 'Morning Run', distance: 5200 },
        content: '<gpx><trk><name>Morning Run</name></trk></gpx>',
      });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'get_map_track')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Morning Run');
      expect(result.content[0].text).toContain('5200');
      expect(result.content[0].text).toContain('<gpx>');
    });
  });

  describe('update_map_track', () => {
    it('should update track color', async () => {
      mockFetchMapsAPI.mockResolvedValue('EDITED');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'update_map_track')!;
      const result = await tool.handler({ id: 1, color: '#00ff00' });

      expect(result.content[0].text).toContain('Track 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/tracks/1', {
        method: 'PUT',
        body: { color: '#00ff00' },
      });
    });
  });

  // ── Photos ─────────────────────────────────────────────────────────────

  describe('list_map_photos', () => {
    it('should return geolocated photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { fileId: 100, lat: 52.52, lng: 13.405, path: '/Photos/sunset.jpg', dateTaken: 1700000000 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_photos')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('File ID: 100');
      expect(result.content[0].text).toContain('52.52, 13.405');
      expect(result.content[0].text).toContain('sunset.jpg');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_photos')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No geolocated photos found');
    });
  });

  describe('list_map_photos_nonlocalized', () => {
    it('should return non-localized photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { fileId: 200, path: '/Photos/indoor.jpg', dateTaken: 1700000000 },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_photos_nonlocalized')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('File ID: 200');
      expect(result.content[0].text).toContain('indoor.jpg');
    });

    it('should pass pagination params', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_map_photos_nonlocalized')!;
      await tool.handler({ limit: 50, offset: 10, timezone: 'Europe/Berlin' });

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos/nonlocalized', {
        queryParams: { limit: '50', offset: '10', timezone: 'Europe/Berlin' },
      });
    });
  });

  describe('place_map_photos', () => {
    it('should set coordinates on photos', async () => {
      mockFetchMapsAPI.mockResolvedValue({});

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'place_map_photos')!;
      const result = await tool.handler({
        paths: ['/Photos/a.jpg', '/Photos/b.jpg'],
        lats: [52.52, 48.85],
        lngs: [13.405, 2.35],
      });

      expect(result.content[0].text).toContain('2 photo(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos', {
        method: 'POST',
        body: { paths: ['/Photos/a.jpg', '/Photos/b.jpg'], lats: [52.52, 48.85], lngs: [13.405, 2.35] },
      });
    });
  });

  describe('reset_map_photo_coords', () => {
    it('should remove coordinates from photos', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'reset_map_photo_coords')!;
      const result = await tool.handler({ paths: ['/Photos/a.jpg'] });

      expect(result.content[0].text).toContain('1 photo(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/photos', {
        method: 'DELETE',
        body: { paths: ['/Photos/a.jpg'] },
      });
    });
  });

  // ── My Maps ────────────────────────────────────────────────────────────

  describe('list_maps', () => {
    it('should return custom maps', async () => {
      mockFetchMapsAPI.mockResolvedValue([
        { id: 1, name: 'Europe Trip' },
        { id: 2, name: 'Local Hikes' },
      ]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_maps')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('Map ID: 1');
      expect(result.content[0].text).toContain('Europe Trip');
      expect(result.content[0].text).toContain('Map ID: 2');
      expect(result.content[0].text).toContain('2');
    });

    it('should handle empty results', async () => {
      mockFetchMapsAPI.mockResolvedValue([]);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'list_maps')!;
      const result = await tool.handler({});

      expect(result.content[0].text).toContain('No custom maps found');
    });
  });

  describe('create_map', () => {
    it('should create a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 10, name: 'Vacation' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'create_map')!;
      const result = await tool.handler({ name: 'Vacation' });

      expect(result.content[0].text).toContain('Map created');
      expect(result.content[0].text).toContain('ID: 10');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps', {
        method: 'POST',
        body: { values: { newName: 'Vacation' } },
      });
    });

    it('should use default name', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 11, name: 'New Map' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'create_map')!;
      await tool.handler({});

      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps', {
        method: 'POST',
        body: { values: { newName: 'New Map' } },
      });
    });
  });

  describe('update_map', () => {
    it('should update a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 1, name: 'Renamed Trip' });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'update_map')!;
      const result = await tool.handler({ id: 1, values: { newName: 'Renamed Trip' } });

      expect(result.content[0].text).toContain('Map 1 updated');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps/1', {
        method: 'PUT',
        body: { values: { newName: 'Renamed Trip' } },
      });
    });
  });

  describe('delete_map', () => {
    it('should delete a map successfully', async () => {
      mockFetchMapsAPI.mockResolvedValue({});

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map')!;
      const result = await tool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Map 1 deleted');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/maps/1', { method: 'DELETE' });
    });

    it('should handle deletion errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 404: Not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'delete_map')!;
      const result = await tool.handler({ id: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting map');
    });
  });

  // ── Routing ────────────────────────────────────────────────────────────

  describe('export_map_route', () => {
    it('should export a route as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue({ id: 5, file_id: 200 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'export_map_route')!;
      const result = await tool.handler({
        name: 'Morning Walk',
        type: 'track',
        coords: [{ lat: 52.52, lng: 13.405 }, { lat: 52.53, lng: 13.41 }],
      });

      expect(result.content[0].text).toContain('Morning Walk');
      expect(result.content[0].text).toContain('exported');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/exportRoute', {
        method: 'POST',
        body: {
          name: 'Morning Walk',
          type: 'track',
          coords: [{ lat: 52.52, lng: 13.405 }, { lat: 52.53, lng: 13.41 }],
        },
      });
    });
  });

  // ── Import/Export ──────────────────────────────────────────────────────

  describe('export_map_favorites', () => {
    it('should export favorites as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue('/Maps/2024-01-01 favorites.gpx');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'export_map_favorites')!;
      const result = await tool.handler({ categoryList: ['Restaurant', 'Home'] });

      expect(result.content[0].text).toContain('exported');
      expect(result.content[0].text).toContain('favorites.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/export/favorites', {
        method: 'POST',
        body: { categoryList: ['Restaurant', 'Home'] },
      });
    });
  });

  describe('import_map_favorites', () => {
    it('should import favorites from file', async () => {
      mockFetchMapsAPI.mockResolvedValue({ imported: 5 });

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'import_map_favorites')!;
      const result = await tool.handler({ path: '/Maps/favorites.gpx' });

      expect(result.content[0].text).toContain('imported');
      expect(result.content[0].text).toContain('favorites.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/import/favorites', {
        method: 'POST',
        body: { path: '/Maps/favorites.gpx' },
      });
    });

    it('should handle import errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: Unsupported format'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'import_map_favorites')!;
      const result = await tool.handler({ path: '/Maps/bad.txt' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error importing favorites');
    });
  });

  describe('export_map_devices', () => {
    it('should export device data as GPX', async () => {
      mockFetchMapsAPI.mockResolvedValue('/Maps/2024-01-01 devices.gpx');

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'export_map_devices')!;
      const result = await tool.handler({ deviceIdList: [1, 2] });

      expect(result.content[0].text).toContain('exported');
      expect(result.content[0].text).toContain('devices.gpx');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/export/devices', {
        method: 'POST',
        body: { deviceIdList: [1, 2] },
      });
    });
  });

  describe('import_map_devices', () => {
    it('should import device data from file', async () => {
      mockFetchMapsAPI.mockResolvedValue(42);

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'import_map_devices')!;
      const result = await tool.handler({ path: '/Maps/track.gpx' });

      expect(result.content[0].text).toContain('42 device point(s)');
      expect(mockFetchMapsAPI).toHaveBeenCalledWith('/import/devices', {
        method: 'POST',
        body: { path: '/Maps/track.gpx' },
      });
    });

    it('should handle import errors', async () => {
      mockFetchMapsAPI.mockRejectedValue(new Error('Maps API 400: File not found'));

      const { mapsTools } = await import('../tools/apps/maps.js');
      const tool = mapsTools.find(t => t.name === 'import_map_devices')!;
      const result = await tool.handler({ path: '/Maps/missing.gpx' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error importing devices');
    });
  });

  describe('run_occ', () => {
    it('should return success output for a successful command', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'app:list output',
        stderr: '',
      });

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(false);
      expect(result.content[0].text).toContain('Command completed successfully');
      expect(result.content[0].text).toContain('app:list output');
    });

    it('should return clear permission denied message on 403', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(403, 'Forbidden', '<html>forbidden</html>'));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
      expect(result.content[0].text).toContain('admin rights');
      expect(result.content[0].text).not.toContain('<html>');
    });

    it('should return clear auth failed message on 401', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(401, 'Unauthorized', ''));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Authentication failed');
    });

    it('should return clear not found message on 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockExecuteOCC.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('OCC endpoint not found');
      expect(result.content[0].text).toContain('AIquila app');
    });

    it('should handle generic errors gracefully', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('Network timeout'));

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'app:list' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network timeout');
    });
  });
});
