// SPDX-License-Identifier: MIT

// RFC 3986 §3.3: a path segment holds only `pchar`; anything else is
// percent-encoded (§2.1). A calendar, address book, user or file name put into
// a DAV request path must address exactly that resource, and `.` / `..`
// (dot-segments, resolved by §5.2.4) must never walk out of the collection.

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { davFilePath, davPath, davSegment, lastHrefSegment } from '../../tools/dav-utils.js';

vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));

const urls: string[] = [];

beforeEach(() => {
  urls.length = 0;
  process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
  process.env.NEXTCLOUD_USER = 'admin';
  process.env.NEXTCLOUD_PASSWORD = 'testpass';
  global.fetch = vi.fn(async (url: string) => {
    urls.push(url);
    return {
      ok: false,
      status: 404,
      statusText: 'Not Found',
      text: async () => '',
      json: async () => ({}),
      headers: new Headers(),
    };
  }) as unknown as typeof fetch;
});

const HOSTILE = 'a b/c?d#e%f';
const ENCODED = 'a%20b%2Fc%3Fd%23e%25f';

describe('RFC 3986 — path segment helpers', () => {
  it('§2.1: encodes every character outside pchar', () => {
    expect(davSegment(HOSTILE)).toBe(ENCODED);
    expect(davSegment('Ünï')).toBe('%C3%9Cn%C3%AF');
    expect(davSegment(42)).toBe('42');
  });

  it('§3.3: a segment never contains a slash, so one name is one segment', () => {
    expect(davPath('calendars', 'admin', HOSTILE)).toBe(
      `/remote.php/dav/calendars/admin/${ENCODED}`
    );
  });

  it('§5.2.4: dot-segments and empty segments are rejected', () => {
    for (const bad of ['.', '..', '']) {
      expect(() => davSegment(bad)).toThrow(/Invalid name/);
    }
    // An encoded dot-segment is not one, so it is safe to pass through.
    expect(davSegment('%2e%2e')).toBe('%252e%252e');
  });

  it('a file path keeps its slashes and encodes each segment', () => {
    expect(davFilePath('/Docs/a b/#1.txt')).toBe('Docs/a%20b/%231.txt');
    expect(davFilePath('/')).toBe('');
    expect(() => davFilePath('/Docs/../../other')).toThrow(/Invalid name/);
  });

  it('a segment read back from an href is decoded once', () => {
    expect(lastHrefSegment(`/remote.php/dav/calendars/admin/${ENCODED}/`)).toBe(HOSTILE);
    expect(lastHrefSegment('/remote.php/dav/calendars/admin/bad%zz/')).toBe('bad%zz');
  });
});

type Handler = (args: Record<string, unknown>) => Promise<unknown>;

const calendarCases: [string, string, Record<string, unknown>][] = [
  ['calendar.js', 'listEventsTool', {}],
  ['calendar.js', 'getEventTool', { uid: 'u' }],
  ['calendar.js', 'createEventTool', { summary: 's', dtstart: '20240315T100000Z' }],
  ['calendar.js', 'updateEventTool', { uid: 'u', summary: 's' }],
  ['calendar.js', 'deleteEventTool', { uid: 'u' }],
  ['tasks.js', 'listTasksTool', {}],
  ['tasks.js', 'createTaskTool', { summary: 's' }],
  ['tasks.js', 'updateTaskTool', { uid: 'u', summary: 's' }],
  ['tasks.js', 'deleteTaskTool', { uid: 'u' }],
  ['tasks.js', 'completeTaskTool', { uid: 'u' }],
];

const addressBookCases: [string, Record<string, unknown>][] = [
  ['listContactsTool', {}],
  ['getContactTool', { uid: 'u' }],
  ['createContactTool', { fullName: 'n' }],
  ['updateContactTool', { uid: 'u', fullName: 'n' }],
  ['deleteContactTool', { uid: 'u' }],
];

async function tool(file: string, name: string): Promise<Handler> {
  const mod = (await import(`../../tools/apps/${file}`)) as Record<string, { handler: Handler }>;
  return mod[name].handler;
}

describe.each(calendarCases)('%s %s — calendarName', (file, name, args) => {
  it('§2.1: the name addresses exactly that calendar', async () => {
    await (
      await tool(file, name)
    )({ ...args, calendarName: HOSTILE });
    expect(urls[0]).toMatch(
      new RegExp(`^https://cloud\\.example\\.com/remote\\.php/dav/calendars/admin/${ENCODED}/`)
    );
  });

  it('§5.2.4: `..` is rejected as an error result, before any request', async () => {
    const result = (await (
      await tool(file, name)
    )({ ...args, calendarName: '..' })) as {
      isError?: boolean;
    };
    expect(result.isError).toBe(true);
    expect(urls.filter((u) => u.includes('/calendars/admin/../'))).toEqual([]);
  });
});

describe.each(addressBookCases)('contacts.js %s — addressBookName', (name, args) => {
  it('§2.1: the name addresses exactly that address book', async () => {
    await (
      await tool('contacts.js', name)
    )({ ...args, addressBookName: HOSTILE });
    expect(urls[0]).toMatch(
      new RegExp(
        `^https://cloud\\.example\\.com/remote\\.php/dav/addressbooks/users/admin/${ENCODED}/`
      )
    );
  });

  it('§5.2.4: `..` is rejected as an error result, before any request', async () => {
    const result = (await (
      await tool('contacts.js', name)
    )({
      ...args,
      addressBookName: '..',
    })) as { isError?: boolean };
    expect(result.isError).toBe(true);
    expect(urls.filter((u) => u.includes('/admin/../'))).toEqual([]);
  });
});

describe('other DAV paths built from input', () => {
  it('restore_from_trash encodes the trash key', async () => {
    await (
      await tool('trash.js', 'restoreFromTrashTool')
    )({ trashKey: HOSTILE });
    expect(urls[0]).toBe(
      `https://cloud.example.com/remote.php/dav/trashbin/admin/trash/${ENCODED}`
    );
  });

  it('get_file_tags encodes each segment of the file path', async () => {
    const { getFileTagsTool } = await import('../../tools/system/tags.js');
    await getFileTagsTool.handler({ path: '/My Docs/#1?.txt' });
    expect(urls[0]).toBe(
      'https://cloud.example.com/remote.php/dav/files/admin/My%20Docs/%231%3F.txt'
    );
  });

  it('the user id is encoded too', async () => {
    process.env.NEXTCLOUD_USER = 'jane doe';
    await (
      await tool('calendar.js', 'listEventsTool')
    )({ calendarName: 'personal' });
    expect(urls[0]).toMatch(/\/remote\.php\/dav\/calendars\/jane%20doe\/personal\//);
  });
});
