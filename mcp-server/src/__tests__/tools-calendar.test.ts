import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock declarations
vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));
global.fetch = vi.fn();

describe('Calendar Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
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
});
