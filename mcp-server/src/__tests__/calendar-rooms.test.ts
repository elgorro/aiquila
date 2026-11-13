// SPDX-License-Identifier: MIT

import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchCalDAV = vi.fn();
vi.mock('../client/caldav.js', async () => {
  const actual = await vi.importActual<typeof import('../client/caldav.js')>('../client/caldav.js');
  return { ...actual, fetchCalDAV: (...args: unknown[]) => mockFetchCalDAV(...args) };
});

import {
  declinedBookings,
  describeBookings,
  findFreeRoomsTool,
  getFreeBusyTool,
  listCalendarRoomsTool,
  parseFreeBusy,
  parsePrincipals,
  parseScheduleResponse,
  removeAttendees,
  toUtcICal,
} from '../tools/apps/calendar-rooms.js';

const NS = 'xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav"';

function principal(id: string, props: Record<string, string>, cutype = 'ROOM'): string {
  const x = Object.entries(props)
    .map(([k, v]) => `<x1:${k} xmlns:x1="http://nextcloud.com/ns">${v}</x1:${k}>`)
    .join('');
  return `<d:response><d:href>/remote.php/dav/principals/calendar-rooms/${id}/</d:href>
<d:propstat><d:prop><d:displayname>${id}</d:displayname><cal:calendar-user-type>${cutype}</cal:calendar-user-type>
<cal:calendar-user-address-set><d:href>mailto:${id}@example.com</d:href><d:href>/remote.php/dav/principals/calendar-rooms/${id}/</d:href></cal:calendar-user-address-set>
${x}</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>
<d:propstat><d:prop><x1:resource-type xmlns:x1="http://nextcloud.com/ns"/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>`;
}

const COLLECTION = `<d:response><d:href>/remote.php/dav/principals/calendar-rooms/</d:href><d:propstat><d:prop><d:displayname/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>`;

function multistatus(...r: string[]): Response {
  return new Response(`<?xml version="1.0"?><d:multistatus ${NS}>${r.join('')}</d:multistatus>`, {
    status: 207,
  });
}

const ROOMS = multistatus(
  COLLECTION,
  principal('big', {
    'room-type': 'Meeting room',
    'room-seating-capacity': '12',
    'room-building-name': 'HQ',
    'room-building-story': '2',
    'room-building-room-number': '201',
    'room-features': 'PROJECTOR,VIDEO-CONFERENCING',
  }),
  principal('booth', { 'room-seating-capacity': '1', 'room-features': 'PHONE' })
);

function freeBusyReply(address: string, freebusy: string[], status = '2.0;Success'): string {
  const data = freebusy.length
    ? `BEGIN:VCALENDAR\nMETHOD:REPLY\nBEGIN:VFREEBUSY\n${freebusy.join('\n')}\nEND:VFREEBUSY\nEND:VCALENDAR\n`
    : 'BEGIN:VCALENDAR\nBEGIN:VFREEBUSY\nEND:VFREEBUSY\nEND:VCALENDAR\n';
  return `<cal:response><cal:recipient><d:href>mailto:${address}</d:href></cal:recipient>
<cal:request-status>${status}</cal:request-status>${status.startsWith('2.') ? `<cal:calendar-data>${data}</cal:calendar-data>` : ''}</cal:response>`;
}

const PRINCIPAL =
  multistatus(`<d:response><d:href>/remote.php/dav/principals/users/alice/</d:href><d:propstat><d:prop>
<cal:schedule-outbox-URL><d:href>/remote.php/dav/calendars/alice/outbox/</d:href></cal:schedule-outbox-URL>
<cal:calendar-user-address-set><d:href>/remote.php/dav/principals/users/alice/</d:href><d:href>mailto:alice@example.com</d:href></cal:calendar-user-address-set>
</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>`);

describe('parsing', () => {
  it('reads room principals and their Nextcloud metadata, skipping the collection', () => {
    const xml = `<d:multistatus ${NS}>${COLLECTION}${principal('big', { 'room-seating-capacity': '12', 'room-features': 'projector, tv' })}</d:multistatus>`;
    const [room, ...rest] = parsePrincipals(xml, 'room');
    expect(rest).toEqual([]);
    expect(room).toMatchObject({
      name: 'big',
      email: 'big@example.com',
      capacity: 12,
      features: ['PROJECTOR', 'TV'],
    });
  });

  it('reads FREEBUSY periods, folded lines, durations and FBTYPE (RFC 5545 §3.8.2.6)', () => {
    const periods = parseFreeBusy(
      [
        'BEGIN:VFREEBUSY',
        'FREEBUSY:20261120T100000Z/20261120T110000Z,20261120T',
        ' 140000Z/PT30M',
        'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20261120T090000Z/20261120T093000Z',
        'FREEBUSY;FBTYPE=FREE:20261120T120000Z/20261120T130000Z',
        'END:VFREEBUSY',
      ].join('\r\n')
    );
    expect(periods.map((p) => [p.start.toISOString(), p.end.toISOString(), p.type])).toEqual([
      ['2026-11-20T09:00:00.000Z', '2026-11-20T09:30:00.000Z', 'BUSY-TENTATIVE'],
      ['2026-11-20T10:00:00.000Z', '2026-11-20T11:00:00.000Z', 'BUSY'],
      ['2026-11-20T14:00:00.000Z', '2026-11-20T14:30:00.000Z', 'BUSY'],
    ]);
  });

  it('reads each recipient of a schedule-response (RFC 6638 §10.1)', () => {
    const results = parseScheduleResponse(
      `<cal:schedule-response ${NS}>${freeBusyReply('a@example.com', ['FREEBUSY:20261120T100000Z/20261120T110000Z'])}${freeBusyReply('b@example.com', [], '3.7;Could not find principal')}</cal:schedule-response>`
    );
    expect(results).toHaveLength(2);
    expect(results[0]).toMatchObject({ address: 'a@example.com', status: '2.0;Success' });
    expect(results[0].busy).toHaveLength(1);
    expect(results[1]).toMatchObject({
      address: 'b@example.com',
      status: '3.7;Could not find principal',
      busy: [],
    });
  });

  it('takes UTC times only', () => {
    expect(toUtcICal('20261120T100000Z')).toBe('20261120T100000Z');
    expect(toUtcICal('2026-11-20T11:00:00+01:00')).toBe('20261120T100000Z');
    expect(() => toUtcICal('2026-11-20T10:00:00')).toThrow('not a UTC time');
  });

  it('describes what each room answered', () => {
    const ics = (attendees: string, extra = '') =>
      `BEGIN:VEVENT\r\nORGANIZER:mailto:alice@example.com\r\n${extra}${attendees}\r\nEND:VEVENT`;
    expect(
      describeBookings(
        ics(
          'ATTENDEE;CN=Big;CUTYPE=ROOM;PARTSTAT=ACCEPTED:mailto:big@example.com\r\n' +
            'ATTENDEE;CUTYPE=RESOURCE;PARTSTAT=DECLINED:mailto:beamer@example.com\r\n' +
            'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:bob@example.com'
        )
      )
    ).toEqual([
      'Room Big: booked',
      expect.stringMatching(/^Resource beamer@example.com: DECLINED .*double booking.*Remove it/),
    ]);
    expect(
      describeBookings(ics('ATTENDEE;CUTYPE=ROOM:mailto:big@example.com', 'RRULE:FREQ=WEEKLY\r\n'))
    ).toEqual([expect.stringContaining('does not auto-book recurring events')]);
    expect(
      describeBookings('BEGIN:VEVENT\r\nATTENDEE;CUTYPE=ROOM:mailto:big@example.com\r\nEND:VEVENT')
    ).toEqual([expect.stringContaining('no organizer')]);
  });
});

describe('declined bookings', () => {
  const event =
    'BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:e1\r\nORGANIZER:mailto:alice@example.com\r\n' +
    'ATTENDEE;CN="Big room";CUTYPE=ROOM;PARTSTAT=DECLINED;RSVP=TRUE:mailto:BIG@\r\n example.com\r\n' +
    'ATTENDEE;CUTYPE=RESOURCE;PARTSTAT=ACCEPTED:mailto:beamer@example.com\r\n' +
    'ATTENDEE;PARTSTAT=DECLINED:mailto:bob@example.com\r\n' +
    'END:VEVENT\r\nEND:VCALENDAR\r\n';

  it('lists only rooms and resources that declined a scheduled event', () => {
    expect(declinedBookings(event)).toEqual(['mailto:BIG@example.com']);
    expect(declinedBookings(event.replace(/ORGANIZER[^\r]*\r\n/, ''))).toEqual([]);
  });

  it('removes a folded ATTENDEE whatever the address case, and nothing else', () => {
    const out = removeAttendees(event, ['mailto:big@example.com']);
    expect(out).not.toContain('Big room');
    expect(out).not.toMatch(/^ example\.com/m);
    expect(out).toContain('mailto:beamer@example.com');
    expect(out).toContain('ATTENDEE;PARTSTAT=DECLINED:mailto:bob@example.com');
    expect(out).toContain('ORGANIZER:mailto:alice@example.com');
  });

  it('says a released room was removed', () => {
    expect(describeBookings(event, ['mailto:big@example.com'])).toEqual([
      expect.stringMatching(/^Room Big room: DECLINED .*Removed from the event/),
      'Resource beamer@example.com: booked',
    ]);
  });
});

describe('tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'secret';
  });

  function serve(rooms: Response, schedule: string) {
    mockFetchCalDAV.mockImplementation(async (url: string, options: { method: string }) => {
      if (url.includes('/principals/calendar-rooms/')) return rooms.clone();
      if (url.includes('/principals/calendar-resources/')) return multistatus();
      if (url.includes('/principals/users/')) return PRINCIPAL.clone();
      if (options.method === 'POST') {
        return new Response(`<cal:schedule-response ${NS}>${schedule}</cal:schedule-response>`, {
          status: 200,
        });
      }
      throw new Error(`unexpected ${options.method} ${url}`);
    });
  }

  it('lists rooms, filtered by capacity and features', async () => {
    serve(ROOMS, '');
    const all = (await listCalendarRoomsTool.handler({})).content[0].text;
    expect(all).toContain('Rooms (2)');
    expect(all).toContain('Location: HQ, floor 2, room 201');
    const big = (await listCalendarRoomsTool.handler({ minCapacity: 4, features: ['projector'] }))
      .content[0].text;
    expect(big).toContain('Rooms (1)');
    expect(big).not.toContain('booth');
  });

  it('says how rooms are set up when there are none', async () => {
    serve(multistatus(COLLECTION), '');
    const result = await listCalendarRoomsTool.handler({});
    expect(result.content[0].text).toContain('calendar_resource_management');
  });

  it('asks free/busy through the outbox as the mailto organizer', async () => {
    serve(
      ROOMS,
      freeBusyReply('big@example.com', ['FREEBUSY:20261120T100000Z/20261120T110000Z']) +
        freeBusyReply('x@example.com', [], '3.7;Could not find principal')
    );

    const result = await getFreeBusyTool.handler({
      start: '2026-11-20T09:00:00Z',
      end: '20261120T120000Z',
      addresses: ['big@example.com', 'x@example.com'],
    });

    const post = mockFetchCalDAV.mock.calls.find(([, o]) => o.method === 'POST')!;
    expect(post[0]).toBe('https://cloud.example.com/remote.php/dav/calendars/alice/outbox/');
    expect(post[1].body).toContain('METHOD:REQUEST');
    expect(post[1].body).toContain('ORGANIZER:mailto:alice@example.com');
    expect(post[1].body).toContain('DTSTART:20261120T090000Z');
    expect(post[1].body).toContain('ATTENDEE:mailto:x@example.com');
    const out = result.content[0].text;
    expect(out).toContain('big@example.com: busy\n    2026-11-20T10:00Z – 2026-11-20T11:00Z');
    expect(out).toContain('x@example.com: unknown address');
  });

  it('finds the rooms that are free for the whole range', async () => {
    serve(
      ROOMS,
      freeBusyReply('big@example.com', ['FREEBUSY:20261120T103000Z/20261120T113000Z']) +
        freeBusyReply('booth@example.com', [])
    );
    const out = (
      await findFreeRoomsTool.handler({ start: '20261120T100000Z', end: '20261120T110000Z' })
    ).content[0].text;
    expect(out).toContain('Free rooms 20261120T100000Z – 20261120T110000Z (1)');
    expect(out).toContain('- booth <booth@example.com>');
    expect(out).toContain('Busy:\n- big: 2026-11-20T10:30Z – 2026-11-20T11:30Z');
  });

  it('only asks about rooms that match', async () => {
    serve(ROOMS, freeBusyReply('big@example.com', []));
    await findFreeRoomsTool.handler({
      start: '20261120T100000Z',
      end: '20261120T110000Z',
      minCapacity: 10,
    });
    const post = mockFetchCalDAV.mock.calls.find(([, o]) => o.method === 'POST')!;
    expect(post[1].body).not.toContain('booth');
  });

  it('refuses a range that ends before it starts', async () => {
    const result = await findFreeRoomsTool.handler({
      start: '20261120T110000Z',
      end: '20261120T100000Z',
    });
    expect(result.isError).toBe(true);
    expect(mockFetchCalDAV).not.toHaveBeenCalled();
  });
});
