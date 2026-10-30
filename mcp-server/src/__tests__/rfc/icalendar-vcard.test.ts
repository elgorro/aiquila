// SPDX-License-Identifier: MIT

// What the calendar, task and contact tools PUT to Nextcloud, checked
// against iCalendar (RFC 5545), CalDAV (RFC 4791) and vCard (RFC 6350 /
// RFC 2426). Nextcloud's SabreDAV is lenient; other clients reading the same
// objects may not be.

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));

/** What `/ocs/v2.php/cloud/user` answers for the signed-in user. */
let ocsUser: Record<string, unknown> = {};

const puts: { url: string; body: string; headers: Record<string, string> }[] = [];

const MULTISTATUS =
  '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
  '<d:response><d:href>/x.ics</d:href><d:propstat><d:prop>' +
  '<c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>' +
  '<d:getetag>"e"</d:getetag>' +
  '<c:calendar-data>BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:u\nSUMMARY:x\nDTSTART:20240315T120000Z\nEND:VEVENT\nEND:VCALENDAR</c:calendar-data>' +
  '</d:prop></d:propstat></d:response></d:multistatus>';

beforeEach(() => {
  puts.length = 0;
  ocsUser = { id: 'admin', email: 'ada@example.com', displayname: 'Ada, Admin' };
  process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
  process.env.NEXTCLOUD_USER = 'admin';
  process.env.NEXTCLOUD_PASSWORD = 'testpass';
  global.fetch = vi.fn(async (url: string, init: RequestInit = {}) => {
    if (init.method === 'PUT') {
      puts.push({
        url,
        body: String(init.body),
        headers: init.headers as Record<string, string>,
      });
      return { ok: true, status: 201, text: async () => '', headers: new Headers() };
    }
    if (url.includes('/ocs/v2.php/cloud/user')) {
      const meta = { status: 'ok', statuscode: 200, message: 'OK' };
      return { ok: true, status: 200, json: async () => ({ ocs: { meta, data: ocsUser } }) };
    }
    return { ok: true, status: 207, text: async () => MULTISTATUS, headers: new Headers() };
  }) as unknown as typeof fetch;
});

const LONG = 'Ünïcödé ' + 'lorem ipsum dolor sit amet, '.repeat(8) + '— 終わり';

/** Content lines of a wire-format object, still folded. */
function rawLines(body: string): string[] {
  expect(body.endsWith('\r\n')).toBe(true);
  return body.slice(0, -2).split('\r\n');
}

function unfold(body: string): string[] {
  return body
    .replace(/\r\n[ \t]/g, '')
    .split('\r\n')
    .filter(Boolean);
}

/** RFC 5545 §3.1 / RFC 6350 §3.2 content-line rules shared by both formats. */
function expectWellFormedContentLines(body: string) {
  // Every line, the last one included, ends in CRLF; no bare CR or LF.
  expect(body).not.toMatch(/[^\r]\n/);
  expect(body).not.toMatch(/\r[^\n]/);
  for (const line of rawLines(body)) {
    expect(line.length, 'empty line').toBeGreaterThan(0);
    // SHOULD NOT be longer than 75 octets, excluding the line break.
    expect(Buffer.byteLength(line, 'utf8'), line).toBeLessThanOrEqual(75);
    // A multi-octet sequence MUST NOT be split: each line is valid UTF-8.
    expect(Buffer.from(line, 'utf8').toString('utf8')).toBe(line);
    expect(line).not.toContain('�');
  }
}

function property(lines: string[], name: string): string | undefined {
  return lines.find((l) => l.startsWith(`${name}:`) || l.startsWith(`${name};`));
}

describe('RFC 5545 — create_event', () => {
  async function create(args: Record<string, unknown> = {}) {
    const { createEventTool } = await import('../../tools/apps/calendar.js');
    await createEventTool.handler({
      summary: 'Planning, Q3; review\\notes',
      calendarName: 'personal',
      dtstart: '20240315T100000Z',
      description: LONG,
      ...args,
    } as any);
    expect(puts).toHaveLength(1);
    return puts[0];
  }

  it('§3.1: content lines end in CRLF and are folded to 75 octets', async () => {
    expectWellFormedContentLines((await create()).body);
  });

  it('§3.1: folding is transparent — unfolding restores the value', async () => {
    const lines = unfold((await create()).body);
    const { unescapeICalValue } = await import('../../tools/dav-utils.js');
    expect(unescapeICalValue(property(lines, 'DESCRIPTION')!.slice('DESCRIPTION:'.length))).toBe(
      LONG
    );
  });

  it('§3.4 / §3.6: one VCALENDAR with VERSION:2.0 and PRODID', async () => {
    const lines = unfold((await create()).body);
    expect(lines[0]).toBe('BEGIN:VCALENDAR');
    expect(lines.at(-1)).toBe('END:VCALENDAR');
    expect(property(lines, 'VERSION')).toBe('VERSION:2.0');
    expect(property(lines, 'PRODID')).toBeDefined();
  });

  it('§3.6.1: the VEVENT has UID and DTSTAMP, and DTSTAMP is UTC (§3.8.7.2)', async () => {
    const lines = unfold((await create()).body);
    expect(property(lines, 'UID')).toMatch(/^UID:.+/);
    expect(property(lines, 'DTSTAMP')).toMatch(/^DTSTAMP:\d{8}T\d{6}Z$/);
  });

  it('§3.3.11: TEXT escapes backslash, semicolon and comma', async () => {
    const lines = unfold((await create()).body);
    expect(property(lines, 'SUMMARY')).toBe('SUMMARY:Planning\\, Q3\\; review\\\\notes');
  });

  it('§3.3.11: a newline in TEXT is written as \\n, never as a line break', async () => {
    const lines = unfold((await create({ description: 'line1\nline2\r\nBEGIN:VALARM' })).body);
    expect(property(lines, 'DESCRIPTION')).toBe('DESCRIPTION:line1\\nline2\\nBEGIN:VALARM');
    expect(lines.filter((l) => l === 'BEGIN:VALARM')).toHaveLength(0);
  });

  it('RFC 4791 §5.3.2: PUT as text/calendar, If-None-Match: * on create', async () => {
    const put = await create();
    expect(put.headers['Content-Type']).toMatch(/^text\/calendar/);
    expect(put.headers['If-None-Match']).toBe('*');
  });

  it('§3.8.5.3 / §3.2.19: an all-day event uses VALUE=DATE', async () => {
    const lines = unfold((await create({ dtstart: '20240315' })).body);
    expect(property(lines, 'DTSTART')).toBe('DTSTART;VALUE=DATE:20240315');
    expect(property(lines, 'DTEND')).toBe('DTEND;VALUE=DATE:20240316');
  });

  it('§3.8.4.3 / §3.3.3: ORGANIZER is a mailto: of the user email, CN a quoted-string (§3.2)', async () => {
    const lines = unfold((await create({ attendees: [{ email: 'bob@example.com' }] })).body);
    expect(property(lines, 'ORGANIZER')).toBe('ORGANIZER;CN="Ada, Admin":mailto:ada@example.com');
  });

  it('§3.8.4.3: without a known email ORGANIZER is omitted, never mailto:<login name>', async () => {
    ocsUser = { id: 'admin', email: '', displayname: 'admin' };
    const lines = unfold((await create({ attendees: [{ email: 'bob@example.com' }] })).body);
    expect(property(lines, 'ORGANIZER')).toBeUndefined();
    expect(lines.join('\n')).not.toContain('mailto:admin\n');
  });

  it('§3.6.6: every VALARM has ACTION and TRIGGER, and DISPLAY has DESCRIPTION', async () => {
    const lines = unfold((await create({ alarm: 15 })).body);
    const start = lines.indexOf('BEGIN:VALARM');
    const end = lines.indexOf('END:VALARM');
    expect(start).toBeGreaterThan(-1);
    const alarm = lines.slice(start, end);
    expect(property(alarm, 'ACTION')).toBe('ACTION:DISPLAY');
    expect(property(alarm, 'TRIGGER')).toMatch(/^TRIGGER:-PT15M$/);
    expect(property(alarm, 'DESCRIPTION')).toBeDefined();
  });
});

describe('RFC 5545 — create_task', () => {
  async function create(args: Record<string, unknown> = {}) {
    const { createTaskTool } = await import('../../tools/apps/tasks.js');
    await createTaskTool.handler({
      summary: 'Buy milk, eggs; bread',
      calendarName: 'tasks',
      description: LONG,
      ...args,
    } as any);
    expect(puts).toHaveLength(1);
    return puts[0];
  }

  it('§3.1: content lines end in CRLF and are folded to 75 octets', async () => {
    expectWellFormedContentLines((await create()).body);
  });

  it('§3.6.2: the VTODO has UID and a UTC DTSTAMP', async () => {
    const lines = unfold((await create()).body);
    expect(lines).toContain('BEGIN:VTODO');
    expect(property(lines, 'UID')).toMatch(/^UID:.+/);
    expect(property(lines, 'DTSTAMP')).toMatch(/^DTSTAMP:\d{8}T\d{6}Z$/);
    expect(property(lines, 'VERSION')).toBe('VERSION:2.0');
    expect(property(lines, 'PRODID')).toBeDefined();
  });

  it('§3.3.11: TEXT is escaped', async () => {
    const lines = unfold((await create()).body);
    expect(property(lines, 'SUMMARY')).toBe('SUMMARY:Buy milk\\, eggs\\; bread');
  });
});

describe('RFC 5545 — update_task', () => {
  it('§3.1: the rewritten VTODO is folded to 75 octets and unfolds to the value', async () => {
    const vtodo =
      '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
      '<d:response><d:href>/t.ics</d:href><d:propstat><d:prop><d:getetag>"t"</d:getetag>' +
      '<c:calendar-data>BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VTODO\nUID:t\nSUMMARY:x\nEND:VTODO\nEND:VCALENDAR</c:calendar-data>' +
      '</d:prop></d:propstat></d:response></d:multistatus>';
    const base = global.fetch;
    global.fetch = vi.fn(async (url: string, init: RequestInit = {}) =>
      init.method === 'REPORT'
        ? { ok: true, status: 207, text: async () => vtodo, headers: new Headers() }
        : base(url, init)
    ) as unknown as typeof fetch;

    const { updateTaskTool } = await import('../../tools/apps/tasks.js');
    await updateTaskTool.handler({ uid: 't', calendarName: 'tasks', description: LONG } as any);
    expect(puts).toHaveLength(1);
    expectWellFormedContentLines(puts[0].body);
    const { unescapeICalValue } = await import('../../tools/dav-utils.js');
    const lines = unfold(puts[0].body);
    expect(unescapeICalValue(property(lines, 'DESCRIPTION')!.slice('DESCRIPTION:'.length))).toBe(
      LONG
    );
  });
});

describe('RFC 5545 §3.1 / RFC 6350 §3.2 — folding', () => {
  it('unfold(fold(x)) == x, and no folded line exceeds 75 octets', async () => {
    const { foldContentLine } = await import('../../tools/dav-utils.js');
    // One-, two-, three- and four-octet code points, so every split offset is hit.
    for (const unit of ['a', 'ü', '終', '😀']) {
      for (let n = 60; n < 160; n++) {
        const line = `DESCRIPTION:${unit.repeat(n)}`;
        const folded = foldContentLine(line);
        expect(folded.replace(/\r\n /g, '')).toBe(line);
        for (const part of folded.split('\r\n')) {
          expect(Buffer.byteLength(part, 'utf8')).toBeLessThanOrEqual(75);
          // A multi-octet sequence MUST NOT be split: a split one (or a lone
          // surrogate) does not survive a UTF-8 round trip.
          expect(Buffer.from(part, 'utf8').toString('utf8')).toBe(part);
        }
      }
    }
  });

  it('formatICalendar does not fold an already folded object twice', async () => {
    const { formatICalendar } = await import('../../tools/dav-utils.js');
    const once = formatICalendar(`BEGIN:VCALENDAR\nSUMMARY:${'x'.repeat(200)}\nEND:VCALENDAR`);
    expect(formatICalendar(once)).toBe(once);
  });
});

describe('RFC 6350 / 2426 — create_contact', () => {
  async function create(args: Record<string, unknown> = {}) {
    const { createContactTool } = await import('../../tools/apps/contacts.js');
    await createContactTool.handler({
      fullName: 'Doe, Jane; Dr.',
      addressBookName: 'contacts',
      firstName: 'Jane',
      lastName: 'Doe',
      note: LONG,
      emails: [{ value: 'jane@example.com', type: 'WORK' }],
      ...args,
    } as any);
    expect(puts).toHaveLength(1);
    return puts[0];
  }

  it('§3.2: content lines end in CRLF and are folded to 75 octets', async () => {
    expectWellFormedContentLines((await create()).body);
  });

  it('§6.1.1 / §6.7.9: BEGIN first, VERSION immediately after, END last', async () => {
    const lines = unfold((await create()).body);
    expect(lines[0]).toBe('BEGIN:VCARD');
    expect(lines[1]).toMatch(/^VERSION:(3\.0|4\.0)$/);
    expect(lines.at(-1)).toBe('END:VCARD');
  });

  it('§6.2.1: FN is present, with TEXT escaping (§3.4)', async () => {
    const lines = unfold((await create()).body);
    expect(property(lines, 'FN')).toBe('FN:Doe\\, Jane\\; Dr.');
  });

  it('§6.7.6: a UID is present', async () => {
    const lines = unfold((await create()).body);
    expect(property(lines, 'UID')).toMatch(/^UID:.+/);
  });

  it('RFC 6352 §6.3.2: PUT as text/vcard', async () => {
    const put = await create();
    expect(put.headers['Content-Type']).toMatch(/^text\/vcard/);
  });
});

describe('RFC 9110 §13.1 — conditional writes (lost-update prevention)', () => {
  it('§13.1.1: update_event PUTs with If-Match carrying the quoted ETag it read', async () => {
    const { updateEventTool } = await import('../../tools/apps/calendar.js');
    const result = await updateEventTool.handler({
      uid: 'u',
      calendarName: 'personal',
      summary: 'Renamed',
    } as any);
    expect(result.isError).toBeFalsy();
    expect(puts).toHaveLength(1);
    // An entity-tag is a quoted string (§8.8.3) and is sent back unchanged.
    expect(puts[0].headers['If-Match']).toBe('"e"');
    expect(puts[0].headers['If-None-Match']).toBeUndefined();
  });

  it('§13.1.1: the rewritten object is still RFC 5545 content lines', async () => {
    const { updateEventTool } = await import('../../tools/apps/calendar.js');
    await updateEventTool.handler({
      uid: 'u',
      calendarName: 'personal',
      description: LONG,
    } as any);
    expectWellFormedContentLines(puts[0].body);
  });

  it('§13.1.2: create uses If-None-Match: *, so it never overwrites', async () => {
    const { createEventTool } = await import('../../tools/apps/calendar.js');
    await createEventTool.handler({
      summary: 'x',
      calendarName: 'personal',
      dtstart: '20240315T100000Z',
    } as any);
    expect(puts[0].headers['If-None-Match']).toBe('*');
    expect(puts[0].headers['If-Match']).toBeUndefined();
  });
});
