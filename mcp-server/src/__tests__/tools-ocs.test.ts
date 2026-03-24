import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock webdav client (needed for recipe tests)
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

// Mock the OCS client module
const mockFetchOCS = vi.fn();
const mockFetchStatus = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: (...args: unknown[]) => mockFetchStatus(...args),
}));

// Mock the AIquila API client module
const mockExecuteOCC = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

// Mock the Notes API client module
const mockFetchNotesAPI = vi.fn();

vi.mock('../client/notes.js', () => ({
  fetchNotesAPI: (...args: unknown[]) => mockFetchNotesAPI(...args),
}));

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

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps', {
        queryParams: { filter: 'disabled' },
      });
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
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps/tasks', { method: 'POST' });
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
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps/tasks', {
        method: 'DELETE',
      });
    });
  });

  describe('install_app', () => {
    it('should install an app successfully', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks installed', stderr: '' });

      const { installAppTool } = await import('../tools/system/apps.js');
      const result = await installAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks installed');
      expect(result).not.toHaveProperty('isError');
      expect(mockExecuteOCC).toHaveBeenCalledWith('app:install', ['tasks'], 300);
    });

    it('should pass --keep-disabled flag when requested', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks installed', stderr: '' });

      const { installAppTool } = await import('../tools/system/apps.js');
      await installAppTool.handler({ appId: 'tasks', keepDisabled: true });

      expect(mockExecuteOCC).toHaveBeenCalledWith('app:install', ['tasks', '--keep-disabled'], 300);
    });

    it('should return error on failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        stdout: '',
        stderr: 'App not found in App Store',
      });

      const { installAppTool } = await import('../tools/system/apps.js');
      const result = await installAppTool.handler({ appId: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('App not found in App Store');
    });
  });

  describe('uninstall_app', () => {
    it('should remove an app successfully', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks removed', stderr: '' });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      const result = await uninstallAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks removed');
      expect(result).not.toHaveProperty('isError');
      expect(mockExecuteOCC).toHaveBeenCalledWith('app:remove', ['tasks'], 120);
    });

    it('should pass --keep-data flag when requested', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks removed', stderr: '' });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      await uninstallAppTool.handler({ appId: 'tasks', keepData: true });

      expect(mockExecuteOCC).toHaveBeenCalledWith('app:remove', ['tasks', '--keep-data'], 120);
    });

    it('should return error on failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        stdout: '',
        stderr: 'App is not installed',
      });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      const result = await uninstallAppTool.handler({ appId: 'tasks' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('App is not installed');
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

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users', {
        queryParams: { search: 'ali', limit: '10', offset: '0' },
      });
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
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/enable', {
        method: 'PUT',
      });
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
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/disable', {
        method: 'PUT',
      });
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
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/groups', {
        method: 'POST',
        body: { groupid: 'marketing' },
      });
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
      const result = await removeUserFromGroupTool.handler({
        userId: 'alice',
        groupId: 'marketing',
      });

      expect(result.content[0].text).toContain('removed');
      expect(result.content[0].text).toContain('marketing');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/alice/groups', {
        method: 'DELETE',
        body: { groupid: 'marketing' },
      });
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
        .mockResolvedValueOnce({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });

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
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: 'personal',
          favorite: true,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (2)');
    });

    it('should filter notes by search term', async () => {
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({ search: 'meeting' });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).not.toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (1)');
    });

    it('should handle empty notes', async () => {
      mockFetchNotesAPI.mockResolvedValue([]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('No notes found');
    });

    it('should handle errors', async () => {
      mockFetchNotesAPI.mockRejectedValue(new Error('Network error'));

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('get_note', () => {
    it('should return note content', async () => {
      mockFetchNotesAPI.mockResolvedValue({
        id: 1,
        title: 'Meeting Notes',
        content: 'Discussed project timeline',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      });

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Discussed project timeline');
    });

    it('should handle nonexistent note', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('update_note', () => {
    it('should fetch current note then update', async () => {
      const current = {
        id: 1,
        title: 'Old Title',
        content: 'Old content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      const updated = { ...current, title: 'New Title', etag: 'xyz' };
      mockFetchNotesAPI.mockResolvedValueOnce(current).mockResolvedValueOnce(updated);

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, title: 'New Title' });

      expect(result.content[0].text).toContain('New Title');
      expect(mockFetchNotesAPI).toHaveBeenCalledTimes(2);
    });

    it('should handle conflict (412)', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      const current = {
        id: 1,
        title: 'Note',
        content: 'Content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      mockFetchNotesAPI
        .mockResolvedValueOnce(current)
        .mockRejectedValueOnce(new ApiError(412, 'Precondition Failed', ''));

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, content: 'New content' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Conflict');
    });
  });

  describe('delete_note', () => {
    it('should delete a note by id', async () => {
      mockFetchNotesAPI.mockResolvedValue(undefined);

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted');
      expect(mockFetchNotesAPI).toHaveBeenCalledWith('/notes/1', { method: 'DELETE' });
    });

    it('should handle nonexistent note on delete', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('list_recipes', () => {
    const sampleRecipeJson = (name: string, category: string, keywords: string) =>
      JSON.stringify({
        id: '123',
        name,
        description: '',
        url: '',
        image: '',
        prepTime: 'PT30M',
        cookTime: 'PT1H',
        totalTime: 'PT1H30M',
        recipeCategory: category,
        keywords,
        recipeYield: 4,
        tool: [],
        recipeIngredient: [],
        recipeInstructions: [],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
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
        id: '123',
        name: 'Pasta Carbonara',
        description: 'Classic Italian',
        url: '',
        image: '',
        prepTime: 'PT15M',
        cookTime: 'PT20M',
        totalTime: 'PT35M',
        recipeCategory: 'Italian',
        keywords: 'pasta,quick',
        recipeYield: 4,
        tool: ['Pot'],
        recipeIngredient: ['400g spaghetti', '200g pancetta'],
        recipeInstructions: ['Cook pasta', 'Fry pancetta'],
        nutrition: { '@type': 'NutritionInformation', calories: '500 kJ' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
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
        { format: 'text' }
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
        id: '123',
        name: 'Pasta Carbonara',
        description: 'Classic',
        url: '',
        image: '',
        prepTime: 'PT15M',
        cookTime: 'PT20M',
        totalTime: 'PT35M',
        recipeCategory: 'Italian',
        keywords: 'pasta',
        recipeYield: 4,
        tool: [],
        recipeIngredient: ['400g spaghetti'],
        recipeInstructions: ['Cook pasta'],
        nutrition: { '@type': 'NutritionInformation' },
        '@context': 'http://schema.org',
        '@type': 'Recipe',
        dateModified: '2024-12-01T00:00:00+0000',
        dateCreated: '2024-11-01T00:00:00+0000',
        datePublished: null,
        printImage: true,
        imageUrl: '',
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
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Pasta', recipeCategory: 'Italian', keywords: '', recipeYield: 0 })
        )
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Curry', recipeCategory: 'Indian', keywords: '', recipeYield: 0 })
        )
        .mockResolvedValueOnce(
          JSON.stringify({ name: 'Salad', recipeCategory: 'Italian', keywords: '', recipeYield: 0 })
        );

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
    // PROPFIND response confirming the calendar supports VEVENT (new pre-flight check)
    const propfindVeventOk = {
      ok: true,
      status: 207,
      text: () =>
        Promise.resolve(
          '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
            '<d:response><d:propstat><d:prop>' +
            '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>' +
            '</d:prop></d:propstat></d:response></d:multistatus>'
        ),
    };

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
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

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

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
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
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Holiday',
        calendarName: 'personal',
        dtstart: '20240401',
      });

      expect(result.content[0].text).toContain('created successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('DTSTART;VALUE=DATE:20240401');
      expect(putCall[1].body).toContain('DTEND;VALUE=DATE:20240402');
    });

    it('should create event with attendees and alarm', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Team sync',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
        attendees: [{ email: 'bob@example.com', cn: 'Bob', role: 'REQ-PARTICIPANT' }],
        alarm: 15,
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
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
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Weekly standup',
        calendarName: 'personal',
        dtstart: '20240315T090000Z',
        rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
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
        .mockResolvedValueOnce({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'New title',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });

    it('should extract ETag correctly when server uses non-d: namespace prefix', async () => {
      const resolveResponseUpperNs = `<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:response>
    <D:href>/remote.php/dav/calendars/admin/personal/event-1.ics</D:href>
    <D:propstat><D:prop>
      <D:getetag>"etag-upper-ns"</D:getetag>
      <C:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
END:VEVENT
END:VCALENDAR</C:calendar-data>
    </D:prop></D:propstat>
  </D:response>
</D:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponseUpperNs) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'Updated title',
      });

      expect(result.content[0].text).toContain('updated successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].headers['If-Match']).toBe('"etag-upper-ns"');
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

    it('should add alarm to event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        alarm: 15,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).toContain('BEGIN:VALARM');
      expect(putBody).toContain('TRIGGER:-PT15M');
      expect(putBody).toContain('ACTION:DISPLAY');
      expect(putBody).toContain('END:VALARM');
    });

    it('should remove alarm when set to null', async () => {
      const responseWithAlarm = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
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
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Reminder
TRIGGER:-PT15M
END:VALARM
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(responseWithAlarm) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        alarm: null,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).not.toContain('BEGIN:VALARM');
      expect(putBody).not.toContain('END:VALARM');
    });

    it('should add attendees to event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        attendees: [
          { email: 'alice@example.com', cn: 'Alice' },
          { email: 'bob@example.com', role: 'OPT-PARTICIPANT', rsvp: false },
        ],
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).toContain('ORGANIZER;CN=admin:mailto:admin');
      expect(putBody).toContain(
        'ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.com'
      );
      expect(putBody).toContain(
        'ATTENDEE;ROLE=OPT-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:bob@example.com'
      );
    });

    it('should remove attendees when set to null', async () => {
      const responseWithAttendees = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
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
ORGANIZER;CN=admin:mailto:admin
ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT:mailto:alice@example.com
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(responseWithAttendees) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        attendees: null,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).not.toMatch(/^ORGANIZER/m);
      expect(putBody).not.toMatch(/^ATTENDEE/m);
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
      const result = await deleteEventTool.handler({
        uid: 'nonexistent',
        calendarName: 'personal',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should handle ETag conflict', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });

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

  describe('create_share', () => {
    it('should create a user share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 42,
            share_type: 0,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 19,
            stime: 1707312000,
            path: '/Documents/report.pdf',
            share_with: 'bob',
            share_with_displayname: 'Bob',
          },
        },
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/Documents/report.pdf',
        shareType: 0,
        shareWith: 'bob',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares', {
        method: 'POST',
        body: { path: '/Documents/report.pdf', shareType: '0', shareWith: 'bob' },
      });
      expect(result.content[0].text).toContain('Share created');
      expect(result.content[0].text).toContain('42');
      expect(result.content[0].text).toContain('User');
    });

    it('should create a public link share and return token/url', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 55,
            share_type: 3,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 1,
            stime: 1707312000,
            path: '/Photos/cat.jpg',
            token: 'xYz789',
            url: 'https://cloud.example.com/s/xYz789',
          },
        },
      });

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/Photos/cat.jpg',
        shareType: 3,
      });

      expect(result.content[0].text).toContain('xYz789');
      expect(result.content[0].text).toContain('https://cloud.example.com/s/xYz789');
    });

    it('should handle API errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Share not found'));

      const { createShareTool } = await import('../tools/apps/shares.js');
      const result = await createShareTool.handler({
        path: '/nonexistent',
        shareType: 0,
        shareWith: 'bob',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating share');
    });
  });

  describe('update_share', () => {
    it('should update share permissions', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: 42,
            share_type: 0,
            uid_owner: 'alice',
            displayname_owner: 'Alice',
            permissions: 1,
            stime: 1707312000,
            path: '/Documents/report.pdf',
            share_with: 'bob',
          },
        },
      });

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 42, permissions: 1 });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares/42', {
        method: 'PUT',
        body: { permissions: '1' },
      });
      expect(result.content[0].text).toContain('Share 42 updated');
    });

    it('should handle nonexistent share', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Wrong share ID'));

      const { updateShareTool } = await import('../tools/apps/shares.js');
      const result = await updateShareTool.handler({ shareId: 9999, permissions: 1 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error updating share');
    });
  });

  describe('delete_share', () => {
    it('should delete a share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [],
        },
      });

      const { deleteShareTool } = await import('../tools/apps/shares.js');
      const result = await deleteShareTool.handler({ shareId: 42 });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/files_sharing/api/v1/shares/42', {
        method: 'DELETE',
      });
      expect(result.content[0].text).toContain('Share 42 deleted successfully');
    });

    it('should handle nonexistent share', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 404: Wrong share ID'));

      const { deleteShareTool } = await import('../tools/apps/shares.js');
      const result = await deleteShareTool.handler({ shareId: 9999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting share');
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
      const result = await getContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
      });

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
      const result = await getContactTool.handler({
        uid: 'nonexistent',
        addressBookName: 'contacts',
      });

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
      const result = await deleteContactTool.handler({
        uid: 'contact-1',
        addressBookName: 'contacts',
      });

      expect(result.content[0].text).toContain('Contact deleted successfully');

      // Verify DELETE was called with correct ETag
      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-1"');
    });
  });

  // ---------------------------------------------------------------------------
  // OCC tools (moved from Maps Tools)
  // ---------------------------------------------------------------------------

  describe('run_occ', () => {
    afterEach(() => {
      delete process.env.MCP_OCC_ALLOWLIST;
    });

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

    it('should reject commands not in the allowlist', async () => {
      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'user:delete' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not in the OCC allowlist');
      expect(result.content[0].text).toContain('user:delete');
      expect(mockExecuteOCC).not.toHaveBeenCalled();
    });

    it('should not allow prefix matching (user:delete vs user:list)', async () => {
      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'user:delete' });

      expect(result.isError).toBe(true);
      expect(mockExecuteOCC).not.toHaveBeenCalled();
    });

    it('should use custom allowlist from MCP_OCC_ALLOWLIST env var', async () => {
      process.env.MCP_OCC_ALLOWLIST = 'custom:cmd,another:cmd';

      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'custom output',
        stderr: '',
      });

      const { runOccTool, getOccAllowlist } = await import('../tools/system/occ.js');

      expect(getOccAllowlist()).toEqual(['custom:cmd', 'another:cmd']);

      const result = await runOccTool.handler({ command: 'custom:cmd' });
      expect(result.isError).toBe(false);

      // Default commands should be blocked when custom list is set
      const result2 = await runOccTool.handler({ command: 'app:list' });
      expect(result2.isError).toBe(true);
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

  describe('OCC output redaction', () => {
    it('should redact PHP array format key-value pairs', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `"dbpassword" => "s3cret_db_pass"`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`"dbpassword" => "[REDACTED]"`);
    });

    it('should redact JSON format key-value pairs', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `"password": "my_secret_pass"`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`"password": "[REDACTED]"`);
    });

    it('should redact multiple sensitive keys', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = [
        `"secret": "abc123"`,
        `"mail_smtppassword": "smtp_pass"`,
        `"api_key": "key-xyz"`,
        `"passwordsalt": "saltyvalue"`,
      ].join('\n');
      const result = redactSensitiveOutput(input);
      expect(result).toContain(`"secret": "[REDACTED]"`);
      expect(result).toContain(`"mail_smtppassword": "[REDACTED]"`);
      expect(result).toContain(`"api_key": "[REDACTED]"`);
      expect(result).toContain(`"passwordsalt": "[REDACTED]"`);
    });

    it('should pass through non-sensitive output unchanged', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `Nextcloud 28.0.4\ninstalled: true\nmaintenance: false`;
      expect(redactSensitiveOutput(input)).toBe(input);
    });

    it('should redact database URI credentials', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `mysql://admin:super_secret@localhost/nextcloud`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`mysql://admin:[REDACTED]@localhost/nextcloud`);
    });

    it('should redact PHP stack trace paths', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = `Error at /var/www/html/lib/private/App.php:123`;
      const result = redactSensitiveOutput(input);
      expect(result).toBe(`Error at [REDACTED_PATH]`);
    });

    it('should handle a realistic config:list JSON blob', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      const input = JSON.stringify(
        {
          system: {
            version: '28.0.4',
            dbpassword: 'real_db_password',
            secret: 'instance_secret_value',
            mail_smtppassword: 'smtp_pw',
            installed: true,
            overwrite_cli_url: 'https://cloud.example.com',
          },
        },
        null,
        2
      );
      const result = redactSensitiveOutput(input);
      expect(result).toContain(`"version": "28.0.4"`);
      expect(result).toContain(`"installed": true`);
      expect(result).toContain(`"dbpassword": "[REDACTED]"`);
      expect(result).toContain(`"secret": "[REDACTED]"`);
      expect(result).toContain(`"mail_smtppassword": "[REDACTED]"`);
      expect(result).not.toContain('real_db_password');
      expect(result).not.toContain('instance_secret_value');
    });

    it('should handle empty string input', async () => {
      const { redactSensitiveOutput } = await import('../tools/system/occ-redact.js');

      expect(redactSensitiveOutput('')).toBe('');
    });

    it('should redact output in the handler response', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: `"dbpassword": "hunter2"`,
        stderr: `Warning at /var/www/html/lib/foo.php:42`,
      });

      const { runOccTool } = await import('../tools/system/occ.js');
      const result = await runOccTool.handler({ command: 'config:app:get', args: ['core'] });

      expect(result.content[0].text).toContain(`"dbpassword": "[REDACTED]"`);
      expect(result.content[0].text).not.toContain('hunter2');
      expect(result.content[0].text).toContain('[REDACTED_PATH]');
      expect(result.content[0].text).not.toContain('/var/www/html/lib/foo.php');
    });

    it('should not include config:list in default allowlist', async () => {
      delete process.env.MCP_OCC_ALLOWLIST;
      const { getOccAllowlist } = await import('../tools/system/occ.js');
      const allowlist = getOccAllowlist();
      expect(allowlist).not.toContain('config:list');
    });
  });

  // ─── Bulk File Operations ─────────────────────────────────────────────

  describe('bulk_file_operations', () => {
    it('should execute multiple operations', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);
      mockClient.copyFile.mockResolvedValue(undefined);
      mockClient.deleteFile.mockResolvedValue(undefined);

      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [
          { action: 'move', source: '/a.txt', destination: '/b.txt' },
          { action: 'copy', source: '/c.txt', destination: '/d.txt' },
          { action: 'delete', source: '/e.txt' },
        ],
      });

      expect(result.content[0].text).toContain('3/3 succeeded');
      expect(result.content[0].text).toContain('move /a.txt');
      expect(result.content[0].text).toContain('delete /e.txt');
    });

    it('should handle partial failures', async () => {
      mockClient.moveFile.mockResolvedValue(undefined);
      mockClient.deleteFile.mockRejectedValue(new Error('Not found'));

      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [
          { action: 'move', source: '/a.txt', destination: '/b.txt' },
          { action: 'delete', source: '/missing.txt' },
        ],
      });

      expect(result.content[0].text).toContain('1/2 succeeded');
      expect(result.isError).toBe(true);
    });

    it('should reject move/copy without destination', async () => {
      const { bulkFileOperationsTool } = await import('../tools/system/files.js');
      const result = await bulkFileOperationsTool.handler({
        operations: [{ action: 'move', source: '/a.txt' }],
      });

      expect(result.content[0].text).toContain('missing destination');
    });
  });

  // ─── Trash Tools ─────────────────────────────────────────────────────

  describe('list_trash', () => {
    it('should list trash items', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/doc.pdf.d1711234567</d:href>
    <d:propstat><d:prop>
      <d:displayname>doc.pdf</d:displayname>
      <d:getcontentlength>10240</d:getcontentlength>
      <oc:trashbin-original-location>Documents/doc.pdf</oc:trashbin-original-location>
      <oc:trashbin-delete-timestamp>1711234567</oc:trashbin-delete-timestamp>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        statusText: 'Multi-Status',
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listTrashTool } = await import('../tools/apps/trash.js');
      const result = await listTrashTool.handler();

      expect(result.content[0].text).toContain('doc.pdf');
      expect(result.content[0].text).toContain('Documents/doc.pdf');
    });

    it('should handle empty trash', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/trashbin/admin/trash/</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listTrashTool } = await import('../tools/apps/trash.js');
      const result = await listTrashTool.handler();

      expect(result.content[0].text).toContain('empty');
    });
  });

  describe('restore_from_trash', () => {
    it('should restore a trash item', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        statusText: 'Created',
        headers: new Headers(),
      });

      const { restoreFromTrashTool } = await import('../tools/apps/trash.js');
      const result = await restoreFromTrashTool.handler({
        trashKey: 'doc.pdf.d1711234567',
      });

      expect(result.content[0].text).toContain('Restored');
    });
  });

  describe('empty_trash', () => {
    it('should empty the trash', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 204,
        statusText: 'No Content',
        headers: new Headers(),
      });

      const { emptyTrashTool } = await import('../tools/apps/trash.js');
      const result = await emptyTrashTool.handler();

      expect(result.content[0].text).toContain('emptied');
    });
  });

  // ─── File Versioning Tools ──────────────────────────────────────────────

  describe('list_file_versions', () => {
    it('should list file versions', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345/1711234567</d:href>
    <d:propstat><d:prop>
      <d:getlastmodified>Sat, 23 Mar 2024 15:16:07 GMT</d:getlastmodified>
      <d:getcontentlength>5120</d:getcontentlength>
    </d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listFileVersionsTool } = await import('../tools/apps/versions.js');
      const result = await listFileVersionsTool.handler({ fileId: 12345 });

      expect(result.content[0].text).toContain('1711234567');
      expect(result.content[0].text).toContain('Sat, 23 Mar 2024');
    });

    it('should handle no versions', async () => {
      const xml = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/versions/admin/versions/12345</d:href>
    <d:propstat><d:prop></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 207,
        text: () => Promise.resolve(xml),
        headers: new Headers(),
      });

      const { listFileVersionsTool } = await import('../tools/apps/versions.js');
      const result = await listFileVersionsTool.handler({ fileId: 12345 });

      expect(result.content[0].text).toContain('No previous versions');
    });
  });

  describe('restore_file_version', () => {
    it('should restore a file version', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 201,
        statusText: 'Created',
        headers: new Headers(),
      });

      const { restoreFileVersionTool } = await import('../tools/apps/versions.js');
      const result = await restoreFileVersionTool.handler({
        fileId: 12345,
        versionId: '1711234567',
      });

      expect(result.content[0].text).toContain('Restored version 1711234567');
    });
  });

  // ─── Notification Tools ──────────────────────────────────────────────

  describe('list_notifications', () => {
    it('should list notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              notification_id: 1,
              app: 'files_sharing',
              subject: 'Alice shared doc.pdf with you',
              message: '',
              datetime: '2026-03-24T12:00:00+00:00',
            },
          ],
        },
      });

      const { listNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await listNotificationsTool.handler();

      expect(result.content[0].text).toContain('Alice shared doc.pdf');
    });

    it('should handle empty notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await listNotificationsTool.handler();

      expect(result.content[0].text).toContain('No notifications');
    });
  });

  describe('get_notification', () => {
    it('should get notification details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { notification_id: 1, app: 'files', subject: 'Test' },
        },
      });

      const { getNotificationTool } = await import('../tools/apps/notifications.js');
      const result = await getNotificationTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Test');
    });
  });

  describe('mark_notification_read', () => {
    it('should mark notification as read', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { markNotificationReadTool } = await import('../tools/apps/notifications.js');
      const result = await markNotificationReadTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('marked as read');
    });
  });

  describe('delete_all_notifications', () => {
    it('should delete all notifications', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { deleteAllNotificationsTool } = await import('../tools/apps/notifications.js');
      const result = await deleteAllNotificationsTool.handler();

      expect(result.content[0].text).toContain('All notifications deleted');
    });
  });

  // ─── Share Enhancement Tools ──────────────────────────────────────────

  describe('get_share', () => {
    it('should return share details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 42, share_type: 0, path: '/doc.pdf', share_with: 'bob' },
        },
      });

      const { getShareTool } = await import('../tools/apps/shares.js');
      const result = await getShareTool.handler({ shareId: 42 });

      expect(result.content[0].text).toContain('42');
      expect(result.content[0].text).toContain('doc.pdf');
    });
  });

  describe('list_shares_with_me', () => {
    it('should list shares shared with current user', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            {
              id: 1,
              share_type: 0,
              path: '/shared-doc.pdf',
              uid_owner: 'alice',
              displayname_owner: 'Alice',
            },
          ],
        },
      });

      const { listSharesWithMeTool } = await import('../tools/apps/shares.js');
      const result = await listSharesWithMeTool.handler();

      expect(result.content[0].text).toContain('shared-doc.pdf');
      expect(result.content[0].text).toContain('Alice');
    });

    it('should handle no shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listSharesWithMeTool } = await import('../tools/apps/shares.js');
      const result = await listSharesWithMeTool.handler();

      expect(result.content[0].text).toContain('No shares found');
    });
  });

  describe('search_sharees', () => {
    it('should search for share recipients', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 100, message: 'OK' },
          data: {
            exact: { users: [], groups: [] },
            users: [{ label: 'Bob', value: { shareType: 0, shareWith: 'bob' } }],
          },
        },
      });

      const { searchShareesTool } = await import('../tools/apps/shares.js');
      const result = await searchShareesTool.handler({ search: 'bob' });

      expect(result.content[0].text).toContain('Bob');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v1.php/apps/files_sharing/api/v1/sharees',
        expect.objectContaining({
          queryParams: expect.objectContaining({ search: 'bob', 'shareType[]': expect.any(Array) }),
        })
      );
    });
  });

  describe('list_pending_shares', () => {
    it('should list pending remote shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 100, message: 'OK' },
          data: [{ id: 5, name: 'shared-folder', remote: 'https://other.cloud' }],
        },
      });

      const { listPendingSharesTool } = await import('../tools/apps/shares.js');
      const result = await listPendingSharesTool.handler();

      expect(result.content[0].text).toContain('shared-folder');
    });

    it('should handle no pending shares', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: [] },
      });

      const { listPendingSharesTool } = await import('../tools/apps/shares.js');
      const result = await listPendingSharesTool.handler();

      expect(result.content[0].text).toContain('No pending');
    });
  });

  describe('accept_pending_share', () => {
    it('should accept pending share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: {} },
      });

      const { acceptPendingShareTool } = await import('../tools/apps/shares.js');
      const result = await acceptPendingShareTool.handler({ shareId: 5 });

      expect(result.content[0].text).toContain('accepted');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v1.php/apps/files_sharing/api/v1/remote_shares/pending/5',
        expect.objectContaining({ method: 'POST' })
      );
    });
  });

  describe('decline_pending_share', () => {
    it('should decline pending share', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 100, message: 'OK' }, data: {} },
      });

      const { declinePendingShareTool } = await import('../tools/apps/shares.js');
      const result = await declinePendingShareTool.handler({ shareId: 5 });

      expect(result.content[0].text).toContain('declined');
    });
  });

  // ─── User Status Tools ──────────────────────────────────────────────────

  describe('get_user_status', () => {
    it('should return current user status', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            userId: 'admin',
            status: 'online',
            icon: '☕',
            message: 'Coffee break',
            clearAt: null,
          },
        },
      });

      const { getUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await getUserStatusTool.handler({});

      expect(result.content[0].text).toContain('online');
      expect(result.content[0].text).toContain('Coffee break');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('API error'));

      const { getUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await getUserStatusTool.handler({});

      expect(result.isError).toBe(true);
    });
  });

  describe('set_user_status', () => {
    it('should set status type', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setUserStatusTool } = await import('../tools/apps/user-status.js');
      const result = await setUserStatusTool.handler({ statusType: 'dnd' });

      expect(result.content[0].text).toContain('dnd');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/user_status/api/v1/user_status/status',
        expect.objectContaining({ method: 'PUT', body: { statusType: 'dnd' } })
      );
    });
  });

  describe('set_user_status_message', () => {
    it('should set custom message with icon', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setUserStatusMessageTool } = await import('../tools/apps/user-status.js');
      const result = await setUserStatusMessageTool.handler({
        message: 'In a meeting',
        statusIcon: '📅',
      });

      expect(result.content[0].text).toContain('📅');
      expect(result.content[0].text).toContain('In a meeting');
    });
  });

  describe('clear_user_status_message', () => {
    it('should clear status message', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { clearUserStatusMessageTool } = await import('../tools/apps/user-status.js');
      const result = await clearUserStatusMessageTool.handler();

      expect(result.content[0].text).toContain('cleared');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/user_status/api/v1/user_status/message',
        expect.objectContaining({ method: 'DELETE' })
      );
    });
  });

  describe('list_user_statuses', () => {
    it('should list all user statuses', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: [
            { userId: 'alice', status: 'online', icon: null, message: null },
            { userId: 'bob', status: 'away', icon: '🏠', message: 'Working from home' },
          ],
        },
      });

      const { listUserStatusesTool } = await import('../tools/apps/user-status.js');
      const result = await listUserStatusesTool.handler({});

      expect(result.content[0].text).toContain('alice: online');
      expect(result.content[0].text).toContain('bob: away');
      expect(result.content[0].text).toContain('Working from home');
    });

    it('should handle empty statuses', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: [] },
      });

      const { listUserStatusesTool } = await import('../tools/apps/user-status.js');
      const result = await listUserStatusesTool.handler({});

      expect(result.content[0].text).toContain('No user statuses found');
    });
  });

  // ─── Absence / Out-of-Office Tools ──────────────────────────────────────

  describe('get_out_of_office', () => {
    it('should return absence status', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            id: '1',
            firstDay: '2026-04-01',
            lastDay: '2026-04-10',
            status: 'Vacation',
            message: 'On holiday',
          },
        },
      });

      const { getOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await getOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('Vacation');
      expect(result.content[0].text).toContain('2026-04-01');
    });

    it('should handle 404 gracefully', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found'));

      const { getOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await getOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('No out-of-office status set');
      expect(result).not.toHaveProperty('isError');
    });
  });

  describe('set_out_of_office', () => {
    it('should set absence period', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { setOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await setOutOfOfficeTool.handler({
        firstDay: '2026-04-01',
        lastDay: '2026-04-10',
        status: 'Vacation',
        message: 'On holiday',
      });

      expect(result.content[0].text).toContain('Out-of-office set');
      expect(result.content[0].text).toContain('2026-04-01');
      expect(result.content[0].text).toContain('2026-04-10');
    });
  });

  describe('clear_out_of_office', () => {
    it('should clear absence', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: {} },
      });

      const { clearOutOfOfficeTool } = await import('../tools/apps/absence.js');
      const result = await clearOutOfOfficeTool.handler({});

      expect(result.content[0].text).toContain('cleared');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        expect.stringContaining('/outOfOffice/'),
        expect.objectContaining({ method: 'DELETE' })
      );
    });
  });
});
