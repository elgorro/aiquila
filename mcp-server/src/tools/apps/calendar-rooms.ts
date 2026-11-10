// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { decodeXmlEntities, fetchCalDAV, nsTagContent } from '../../client/caldav.js';
import { davPath, formatICalendar } from '../dav-utils.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Calendar rooms and resources, and their availability.
 *
 * Nextcloud exposes bookable rooms and resources as CalDAV principals under
 * `principals/calendar-rooms` and `principals/calendar-resources`, filled by a
 * backend app such as Calendar Resource Management. Their metadata is a set of
 * `{http://nextcloud.com/ns}` properties (OCP\Calendar\Room\IRoomMetadata,
 * OCP\Calendar\Resource\IResourceMetadata).
 *
 * Availability is a free-busy request: a VFREEBUSY with METHOD:REQUEST POSTed
 * to the signed-in user's scheduling outbox (RFC 6638 §5, RFC 5546 §3.3.2).
 * Each recipient comes back in a CALDAV:schedule-response with its busy
 * periods as FREEBUSY properties (RFC 5545 §3.8.2.6).
 *
 * A room is booked by inviting it: an ATTENDEE with CUTYPE=ROOM (RFC 5545
 * §3.2.3) and RSVP=TRUE. Nextcloud answers for the room at once, ACCEPTED when
 * it is free and DECLINED when it is not, except for recurring events, which
 * it leaves unanswered.
 */

const NC_NS = 'http://nextcloud.com/ns';

export type PrincipalKind = 'room' | 'resource';

export interface BookablePrincipal {
  kind: PrincipalKind;
  name: string;
  email: string;
  principal: string;
  type: string;
  capacity: number | null;
  building: string;
  address: string;
  story: string;
  roomNumber: string;
  features: string[];
  contact: string;
}

type ToolResult = { content: { type: 'text'; text: string }[]; isError?: boolean };

function text(t: string, isError = false): ToolResult {
  return { content: [{ type: 'text' as const, text: t }], ...(isError ? { isError: true } : {}) };
}

const COLLECTIONS: Record<PrincipalKind, string> = {
  room: 'calendar-rooms',
  resource: 'calendar-resources',
};

const PROPFIND = `<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:x="${NC_NS}">
  <d:prop>
    <d:displayname/>
    <cal:calendar-user-type/>
    <cal:calendar-user-address-set/>
    <x:room-type/>
    <x:room-seating-capacity/>
    <x:room-building-name/>
    <x:room-building-address/>
    <x:room-building-story/>
    <x:room-building-room-number/>
    <x:room-features/>
    <x:resource-type/>
    <x:resource-contact-person/>
  </d:prop>
</d:propfind>`;

function prop(block: string, name: string): string {
  const m = block.match(nsTagContent(name));
  return m ? decodeXmlEntities(m[1]).trim() : '';
}

/** The principals in a PROPFIND multistatus, read from their 200 propstat only. */
export function parsePrincipals(xml: string, kind: PrincipalKind): BookablePrincipal[] {
  const responses = xml.match(/<d:response>[\s\S]*?<\/d:response>/g) ?? [];
  const result: BookablePrincipal[] = [];
  for (const r of responses) {
    const href = prop(r, 'href');
    const ok = (r.match(/<d:propstat>[\s\S]*?<\/d:propstat>/g) ?? []).find((p) =>
      /<d:status>[^<]*\s200\s/.test(p)
    );
    if (!ok) continue;
    const cutype = prop(ok, 'calendar-user-type');
    if (cutype === '') continue; // the collection itself
    const addresses = [...ok.matchAll(/<d:href>([^<]*)<\/d:href>/g)].map((m) =>
      decodeXmlEntities(m[1])
    );
    const mailto = addresses.find((a) => a.toLowerCase().startsWith('mailto:'));
    const capacity = parseInt(prop(ok, 'room-seating-capacity'), 10);
    result.push({
      kind,
      name: prop(ok, 'displayname'),
      email: mailto ? mailto.slice('mailto:'.length) : '',
      principal: href,
      type: prop(ok, kind === 'room' ? 'room-type' : 'resource-type'),
      capacity: Number.isFinite(capacity) ? capacity : null,
      building: prop(ok, 'room-building-name'),
      address: prop(ok, 'room-building-address'),
      story: prop(ok, 'room-building-story'),
      roomNumber: prop(ok, 'room-building-room-number'),
      features: prop(ok, 'room-features')
        .split(',')
        .map((f) => f.trim().toUpperCase())
        .filter(Boolean),
      contact: prop(ok, 'resource-contact-person'),
    });
  }
  return result;
}

export async function listPrincipals(kind: PrincipalKind): Promise<BookablePrincipal[]> {
  const { url } = getNextcloudConfig();
  const response = await fetchCalDAV(`${url}${davPath('principals', COLLECTIONS[kind])}/`, {
    method: 'PROPFIND',
    body: PROPFIND,
    headers: { Depth: '1' },
  });
  if (response.status === 404) return [];
  if (response.status !== 207) {
    throw new Error(`listing ${COLLECTIONS[kind]}: ${response.status} ${response.statusText}`);
  }
  return parsePrincipals(await response.text(), kind);
}

function formatPrincipal(p: BookablePrincipal): string {
  const lines = [`- ${p.name} <${p.email || 'no email'}>${p.type ? ` — ${p.type}` : ''}`];
  const where = [
    p.building,
    p.story && `floor ${p.story}`,
    p.roomNumber && `room ${p.roomNumber}`,
  ].filter(Boolean);
  if (where.length > 0) lines.push(`  Location: ${where.join(', ')}`);
  if (p.address) lines.push(`  Address: ${p.address}`);
  if (p.capacity !== null) lines.push(`  Capacity: ${p.capacity}`);
  if (p.features.length > 0) lines.push(`  Features: ${p.features.join(', ')}`);
  if (p.contact) lines.push(`  Contact: ${p.contact}`);
  return lines.join('\n');
}

function matches(p: BookablePrincipal, minCapacity?: number, features?: string[]): boolean {
  if (minCapacity !== undefined && (p.capacity === null || p.capacity < minCapacity)) return false;
  if (features && features.some((f) => !p.features.includes(f.trim().toUpperCase()))) return false;
  return true;
}

// ---------------------------------------------------------------------------
// Free-busy
// ---------------------------------------------------------------------------

/** `YYYYMMDDTHHmmssZ`, from that form or from an ISO 8601 date-time with an offset. */
export function toUtcICal(value: string): string {
  if (/^\d{8}T\d{6}Z$/.test(value)) return value;
  const date = new Date(value);
  if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(value) || Number.isNaN(date.getTime())) {
    throw new Error(
      `"${value}" is not a UTC time. Use YYYYMMDDTHHmmssZ or ISO 8601 with Z or an offset.`
    );
  }
  return date
    .toISOString()
    .replace(/[-:]/g, '')
    .replace(/\.\d{3}/, '');
}

function icalToDate(v: string): Date {
  return new Date(
    `${v.slice(0, 4)}-${v.slice(4, 6)}-${v.slice(6, 8)}T${v.slice(9, 11)}:${v.slice(11, 13)}:${v.slice(13, 15)}Z`
  );
}

/** Seconds in an RFC 5545 §3.3.6 duration such as PT1H30M or P1D. */
function durationSeconds(d: string): number {
  const m = d.match(/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/);
  if (!m) return NaN;
  const [, sign, w, day, h, min, s] = m;
  const total =
    (+(w ?? 0) * 7 + +(day ?? 0)) * 86400 + +(h ?? 0) * 3600 + +(min ?? 0) * 60 + +(s ?? 0);
  return sign === '-' ? -total : total;
}

export interface BusyPeriod {
  start: Date;
  end: Date;
  type: string;
}

export interface FreeBusyResult {
  address: string;
  status: string;
  busy: BusyPeriod[];
}

/** The FREEBUSY periods in a VFREEBUSY reply (RFC 5545 §3.8.2.6). FBTYPE=FREE is skipped. */
export function parseFreeBusy(ical: string): BusyPeriod[] {
  const lines = ical.replace(/\r?\n[ \t]/g, '').split(/\r?\n/);
  const periods: BusyPeriod[] = [];
  for (const line of lines) {
    const m = line.match(/^FREEBUSY((?:;[^:]*)?):(.*)$/i);
    if (!m) continue;
    const type = m[1].match(/FBTYPE=([^;]+)/i)?.[1].toUpperCase() ?? 'BUSY';
    if (type === 'FREE') continue;
    for (const period of m[2].split(',')) {
      const [from, to] = period.trim().split('/');
      if (!from || !to) continue;
      const start = icalToDate(from);
      const end = /^[+-]?P/.test(to)
        ? new Date(start.getTime() + durationSeconds(to) * 1000)
        : icalToDate(to);
      if (!Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime())) {
        periods.push({ start, end, type });
      }
    }
  }
  return periods.sort((a, b) => a.start.getTime() - b.start.getTime());
}

/** The responses of a CALDAV:schedule-response (RFC 6638 §10.1). */
export function parseScheduleResponse(xml: string): FreeBusyResult[] {
  const blocks = xml.match(/<(?:\w+:)?response>[\s\S]*?<\/(?:\w+:)?response>/g) ?? [];
  return blocks.map((b) => {
    const recipient = b.match(nsTagContent('recipient'))?.[1] ?? '';
    const address = decodeXmlEntities(recipient.match(nsTagContent('href'))?.[1] ?? recipient)
      .trim()
      .replace(/^mailto:/i, '');
    const status = decodeXmlEntities(b.match(nsTagContent('request-status'))?.[1] ?? '').trim();
    const data = b.match(nsTagContent('calendar-data'))?.[1];
    return { address, status, busy: data ? parseFreeBusy(decodeXmlEntities(data)) : [] };
  });
}

async function principalProps(): Promise<{ outbox: string; organizer: string }> {
  const { url, user } = getNextcloudConfig();
  const response = await fetchCalDAV(`${url}${davPath('principals', 'users', user)}/`, {
    method: 'PROPFIND',
    headers: { Depth: '0' },
    body: `<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop><cal:schedule-outbox-URL/><cal:calendar-user-address-set/></d:prop>
</d:propfind>`,
  });
  if (response.status !== 207) {
    throw new Error(`reading your scheduling outbox: ${response.status} ${response.statusText}`);
  }
  const xml = await response.text();
  const outbox = xml
    .match(nsTagContent('schedule-outbox-URL'))?.[1]
    .match(nsTagContent('href'))?.[1];
  const set = xml.match(nsTagContent('calendar-user-address-set'))?.[1] ?? '';
  const addresses = [...set.matchAll(/<(?:\w+:)?href>([^<]*)<\/(?:\w+:)?href>/g)].map((m) =>
    decodeXmlEntities(m[1])
  );
  if (!outbox || addresses.length === 0) {
    throw new Error('This account has no scheduling outbox, so availability cannot be queried.');
  }
  // A mailto address where the account has one; otherwise its principal URL,
  // which is also in its calendar-user-address-set.
  const organizer = addresses.find((a) => a.toLowerCase().startsWith('mailto:')) ?? addresses[0];
  return { outbox: new URL(decodeXmlEntities(outbox), url).toString(), organizer };
}

/** Busy periods of each address between start and end, from one free-busy request. */
export async function queryFreeBusy(
  addresses: string[],
  start: string,
  end: string
): Promise<FreeBusyResult[]> {
  const { outbox, organizer } = await principalProps();
  const now = new Date()
    .toISOString()
    .replace(/[-:]/g, '')
    .replace(/\.\d{3}/, '');
  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//AIquila//MCP Server//EN',
    'METHOD:REQUEST',
    'BEGIN:VFREEBUSY',
    `UID:aiquila-fb-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    `DTSTAMP:${now}`,
    `DTSTART:${start}`,
    `DTEND:${end}`,
    `ORGANIZER:${organizer}`,
    ...addresses.map((a) => `ATTENDEE:mailto:${a}`),
    'END:VFREEBUSY',
    'END:VCALENDAR',
  ];
  const response = await fetchCalDAV(outbox, {
    method: 'POST',
    headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
    body: formatICalendar(lines.join('\r\n')),
  });
  if (response.status !== 200) {
    const body = await response.text().catch(() => '');
    const reason = body.match(nsTagContent('message'))?.[1];
    throw new Error(
      `free-busy request: ${response.status} ${response.statusText}${reason ? ` — ${decodeXmlEntities(reason)}` : ''}`
    );
  }
  return parseScheduleResponse(await response.text());
}

function formatPeriod(p: BusyPeriod): string {
  const iso = (d: Date) => d.toISOString().replace(':00.000Z', 'Z');
  return `${iso(p.start)} – ${iso(p.end)}${p.type === 'BUSY' ? '' : ` (${p.type.toLowerCase()})`}`;
}

/** RFC 5545 §3.8.8.3: 2.x is success; 3.7 is an unknown calendar user. */
function statusProblem(status: string): string | null {
  if (status.startsWith('2.')) return null;
  if (status.startsWith('3.7')) return 'unknown address';
  return status || 'no answer';
}

const TimeArg = z
  .string()
  .describe('UTC time, YYYYMMDDTHHmmssZ or ISO 8601 with an offset, e.g. 2026-11-12T14:00:00Z');

const FeaturesArg = z
  .array(z.string())
  .optional()
  .describe(
    'Features the room must have, e.g. ["PROJECTOR", "VIDEO-CONFERENCING"]. Others: PHONE, TV, WHITEBOARD, WHEELCHAIR-ACCESSIBLE'
  );

function checkRange(start: string, end: string): { start: string; end: string } {
  const s = toUtcICal(start);
  const e = toUtcICal(end);
  if (icalToDate(e) <= icalToDate(s)) throw new Error('end must be after start.');
  return { start: s, end: e };
}

const NO_ROOMS =
  'No bookable rooms or resources are set up on this Nextcloud. They come from a backend app ' +
  'such as Calendar Resource Management (calendar_resource_management).';

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

export const listCalendarRoomsTool = {
  name: 'list_calendar_rooms',
  title: 'List Calendar Rooms and Resources',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the bookable meeting rooms and resources (projectors, vehicles, …) of this Nextcloud, ' +
    'with their email address, type, building, floor, room number, capacity and features. Book ' +
    'one by inviting its email address with cutype ROOM or RESOURCE in create_event.',
  inputSchema: z.object({
    kind: z.enum(['rooms', 'resources', 'all']).optional().describe('What to list (default: all)'),
    minCapacity: z
      .number()
      .int()
      .min(1)
      .optional()
      .describe('Only rooms for at least this many people'),
    features: FeaturesArg,
  }),
  handler: async (args: {
    kind?: 'rooms' | 'resources' | 'all';
    minCapacity?: number;
    features?: string[];
  }) => {
    try {
      const kinds: PrincipalKind[] =
        args.kind === 'rooms'
          ? ['room']
          : args.kind === 'resources'
            ? ['resource']
            : ['room', 'resource'];
      const all = (await Promise.all(kinds.map(listPrincipals))).flat();
      if (all.length === 0) return text(NO_ROOMS);
      const found = all.filter((p) => matches(p, args.minCapacity, args.features));
      if (found.length === 0) return text(`None of the ${all.length} rooms and resources match.`);
      const rooms = found.filter((p) => p.kind === 'room');
      const resources = found.filter((p) => p.kind === 'resource');
      const parts = [];
      if (rooms.length > 0)
        parts.push(`Rooms (${rooms.length}):\n${rooms.map(formatPrincipal).join('\n')}`);
      if (resources.length > 0) {
        parts.push(
          `Resources (${resources.length}):\n${resources.map(formatPrincipal).join('\n')}`
        );
      }
      return text(parts.join('\n\n'));
    } catch (error) {
      return text(
        `Error listing rooms: ${error instanceof Error ? error.message : String(error)}`,
        true
      );
    }
  },
};

export const getFreeBusyTool = {
  name: 'get_free_busy',
  title: 'Get Free/Busy',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Show when rooms, resources or people are busy in a time range, from their calendars. Takes ' +
    'email addresses: those of rooms and resources from list_calendar_rooms, or of Nextcloud users.',
  inputSchema: z.object({
    start: TimeArg,
    end: TimeArg,
    addresses: z.array(z.email()).min(1).max(50).describe('Email addresses to check'),
  }),
  handler: async (args: { start: string; end: string; addresses: string[] }) => {
    try {
      const range = checkRange(args.start, args.end);
      const results = await queryFreeBusy(args.addresses, range.start, range.end);
      const lines = results.map((r) => {
        const problem = statusProblem(r.status);
        if (problem) return `- ${r.address}: ${problem}`;
        if (r.busy.length === 0) return `- ${r.address}: free`;
        return `- ${r.address}: busy\n${r.busy.map((p) => `    ${formatPeriod(p)}`).join('\n')}`;
      });
      return text(`Free/busy ${range.start} – ${range.end}:\n${lines.join('\n')}`);
    } catch (error) {
      return text(
        `Error querying free/busy: ${error instanceof Error ? error.message : String(error)}`,
        true
      );
    }
  },
};

export const findFreeRoomsTool = {
  name: 'find_free_rooms',
  title: 'Find Free Rooms',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Find the meeting rooms that are free for the whole of a time range, optionally with a ' +
    'minimum capacity and required features. Book one with create_event, inviting its email ' +
    'address with cutype ROOM.',
  inputSchema: z.object({
    start: TimeArg,
    end: TimeArg,
    minCapacity: z.number().int().min(1).optional().describe('People the room must hold'),
    features: FeaturesArg,
  }),
  handler: async (args: {
    start: string;
    end: string;
    minCapacity?: number;
    features?: string[];
  }) => {
    try {
      const range = checkRange(args.start, args.end);
      const rooms = await listPrincipals('room');
      if (rooms.length === 0) return text(NO_ROOMS);
      const candidates = rooms.filter(
        (r) => r.email && matches(r, args.minCapacity, args.features)
      );
      if (candidates.length === 0) {
        return text(`None of the ${rooms.length} rooms has the capacity and features asked for.`);
      }
      const results = await queryFreeBusy(
        candidates.map((r) => r.email),
        range.start,
        range.end
      );
      const byAddress = new Map(results.map((r) => [r.address.toLowerCase(), r]));
      const free: BookablePrincipal[] = [];
      const busy: string[] = [];
      for (const room of candidates) {
        const r = byAddress.get(room.email.toLowerCase());
        if (r && !statusProblem(r.status) && r.busy.length === 0) free.push(room);
        else if (r && !statusProblem(r.status)) {
          busy.push(`- ${room.name}: ${r.busy.map(formatPeriod).join('; ')}`);
        } else
          busy.push(
            `- ${room.name}: availability unknown (${r ? statusProblem(r.status) : 'no answer'})`
          );
      }
      const parts = [
        free.length > 0
          ? `Free rooms ${range.start} – ${range.end} (${free.length}):\n${free.map(formatPrincipal).join('\n')}`
          : `No room is free for the whole of ${range.start} – ${range.end}.`,
      ];
      if (busy.length > 0) parts.push(`Busy:\n${busy.join('\n')}`);
      return text(parts.join('\n\n'));
    } catch (error) {
      return text(
        `Error finding free rooms: ${error instanceof Error ? error.message : String(error)}`,
        true
      );
    }
  },
};

export const calendarRoomTools = [listCalendarRoomsTool, getFreeBusyTool, findFreeRoomsTool];

// ---------------------------------------------------------------------------
// Booking results, for create_event and update_event
// ---------------------------------------------------------------------------

/**
 * What each room or resource attendee of a stored event answered. Nextcloud
 * writes the room's PARTSTAT into the organizer's copy while saving it.
 */
export function describeBookings(icalData: string): string[] {
  const unfolded = icalData.replace(/\r?\n[ \t]/g, '');
  const recurring = /^(RRULE|RDATE)[;:]/m.test(unfolded);
  const scheduled = /^ORGANIZER[;:]/m.test(unfolded);
  const lines: string[] = [];
  for (const m of unfolded.matchAll(/^ATTENDEE([^:\r\n]*):(.*)$/gm)) {
    const params = m[1];
    const cutype = params.match(/;CUTYPE=([^;]+)/i)?.[1].toUpperCase();
    if (cutype !== 'ROOM' && cutype !== 'RESOURCE') continue;
    const who =
      params.match(/;CN=("[^"]*"|[^;]*)/i)?.[1].replace(/"/g, '') || m[2].replace(/^mailto:/i, '');
    const partstat = params.match(/;PARTSTAT=([^;]+)/i)?.[1].toUpperCase() ?? 'NEEDS-ACTION';
    const label = cutype === 'ROOM' ? 'Room' : 'Resource';
    if (!scheduled) {
      lines.push(
        `${label} ${who}: not booked — the event has no organizer, so Nextcloud did not ask it. ` +
          'Rooms are booked by an organizer with an email address: add one to your account.'
      );
    } else if (partstat === 'ACCEPTED') lines.push(`${label} ${who}: booked`);
    else if (partstat === 'DECLINED') {
      lines.push(
        `${label} ${who}: DECLINED — it is already booked at that time (double booking). ` +
          "Remove it from the event's attendees: until then Nextcloud keeps the declined " +
          'booking in its calendar and shows it busy.'
      );
    } else if (recurring) {
      lines.push(`${label} ${who}: not answered — Nextcloud does not auto-book recurring events`);
    } else lines.push(`${label} ${who}: ${partstat.toLowerCase()}`);
  }
  return lines;
}
