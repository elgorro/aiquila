// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));
global.fetch = vi.fn();

describe('Task Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
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
});
