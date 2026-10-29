// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createEventTool, updateEventTool } from '../tools/apps/calendar.js';
import { createTaskTool, updateTaskTool } from '../tools/apps/tasks.js';
import { quoteICalParam } from '../tools/dav-utils.js';

// A CR or LF in a value written unescaped ends the content line, and what
// follows becomes a property of its own — here an extra attendee that
// Nextcloud would send an invitation to.
const INJECT = '\r\nATTENDEE:mailto:victim@example.com';

const base = { summary: 'Sync', calendarName: 'personal', dtstart: '20240315T100000Z' };

describe('iCalendar input validation', () => {
  it.each([
    ['dtstart', { ...base, dtstart: `20240315T100000Z${INJECT}` }],
    ['dtend', { ...base, dtend: `20240315T110000Z${INJECT}` }],
    ['rrule', { ...base, rrule: `FREQ=DAILY${INJECT}` }],
    ['tzid', { ...base, tzid: `Europe/Berlin${INJECT}` }],
    ['attendee email', { ...base, attendees: [{ email: `bob@example.com${INJECT}` }] }],
    ['ISO date', { ...base, dtstart: '2024-03-15T10:00:00Z' }],
  ])('create_event rejects %s', (_name, input) => {
    expect(createEventTool.inputSchema.safeParse(input).success).toBe(false);
  });

  it('create_event accepts the documented formats', () => {
    for (const input of [
      base,
      { ...base, dtstart: '20240315', dtend: '20240316' },
      { ...base, dtstart: '20240315T100000', tzid: 'America/Argentina/Buenos_Aires' },
      { ...base, rrule: 'FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20241231T000000Z' },
      { ...base, attendees: [{ email: 'bob@example.com', cn: 'Bob, Jr.' }] },
    ]) {
      expect(createEventTool.inputSchema.safeParse(input).success).toBe(true);
    }
  });

  it.each([
    ['dtstart', { uid: 'u', calendarName: 'c', dtstart: `20240315${INJECT}` }],
    ['dtend', { uid: 'u', calendarName: 'c', dtend: `20240315${INJECT}` }],
    ['rrule', { uid: 'u', calendarName: 'c', rrule: `FREQ=DAILY${INJECT}` }],
    ['tzid', { uid: 'u', calendarName: 'c', tzid: `UTC${INJECT}` }],
    ['attendee email', { uid: 'u', calendarName: 'c', attendees: [{ email: INJECT }] }],
  ])('update_event rejects %s', (_name, input) => {
    expect(updateEventTool.inputSchema.safeParse(input).success).toBe(false);
  });

  it.each([
    [
      'create_task due',
      createTaskTool,
      { summary: 's', calendarName: 'c', due: `20240315${INJECT}` },
    ],
    [
      'create_task dtstart',
      createTaskTool,
      { summary: 's', calendarName: 'c', dtstart: `20240315${INJECT}` },
    ],
    ['update_task due', updateTaskTool, { uid: 'u', calendarName: 'c', due: `20240315${INJECT}` }],
    [
      'update_task dtstart',
      updateTaskTool,
      { uid: 'u', calendarName: 'c', dtstart: `20240315${INJECT}` },
    ],
  ])('%s rejects a line break', (_name, tool, input) => {
    expect(tool.inputSchema.safeParse(input).success).toBe(false);
  });
});

describe('quoteICalParam', () => {
  it('quotes and drops CR, LF and DQUOTE (RFC 5545 §3.2 QSAFE-CHAR)', () => {
    expect(quoteICalParam('Bob, Jr.: "the boss"\r\nX')).toBe('"Bob, Jr.: the bossX"');
  });
});

describe('written iCalendar', () => {
  beforeEach(() => {
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'pw';
    global.fetch = vi.fn();
  });

  it('keeps an attendee name with CR/LF on its own line', async () => {
    const propfind = `<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/remote.php/dav/calendars/admin/personal/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><cal:calendar/></d:resourcetype><cal:supported-calendar-component-set><cal:comp name="VEVENT"/></cal:supported-calendar-component-set></d:prop></d:propstat></d:response></d:multistatus>`;
    (global.fetch as ReturnType<typeof vi.fn>)
      .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(propfind) })
      .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
      .mockResolvedValue({ ok: true, status: 207, text: () => Promise.resolve(propfind) });
    await createEventTool.handler({
      ...base,
      attendees: [{ email: 'bob@example.com', cn: `Bob${INJECT}` }],
    } as any);
    const put = (global.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
      ([, init]) => init?.method === 'PUT'
    )!;
    const lines = (put[1].body as string).split('\r\n');
    expect(lines.filter((l) => l.startsWith('ATTENDEE'))).toHaveLength(1);
    expect(lines).not.toContain('ATTENDEE:mailto:victim@example.com');
  });
});
