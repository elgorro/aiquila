// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { decodeXmlEntities, fetchCalDAV, nsTagContent } from '../../client/caldav.js';
import { escapeICalValue, unescapeICalValue } from '../dav-utils.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Calendar App Tools
 * Provides calendar event management via CalDAV
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ParsedCalendar {
  displayName: string;
  url: string;
  ctag?: string;
  color?: string;
  supportsEvents: boolean;
  supportsTasks: boolean;
  supportsJournals: boolean;
  enabled: boolean;
  order?: number;
  timezone?: string;
}

interface ParsedEvent {
  uid: string;
  summary: string;
  dtstart: string;
  dtend?: string;
  duration?: string;
  isAllDay: boolean;
  location?: string;
  description?: string;
  status?: string;
  transp?: string;
  categories: string[];
  attendees: ParsedAttendee[];
  organizer?: string;
  rrule?: string;
  recurrenceId?: string;
  created?: string;
  lastModified?: string;
  url?: string;
  color?: string;
  accessClass?: string;
  etag?: string;
  href?: string;
  calendarName?: string;
}

interface ParsedAttendee {
  cn?: string;
  email?: string;
  role?: string;
  partstat?: string;
  cutype?: string;
}

// ---------------------------------------------------------------------------
// iCalendar helpers
// ---------------------------------------------------------------------------

function unfoldICalLines(text: string): string {
  return text.replace(/\r?\n[ \t]/g, '');
}

function formatICalDate(icalDate: string): string {
  if (icalDate.length === 8) {
    return `${icalDate.slice(0, 4)}-${icalDate.slice(4, 6)}-${icalDate.slice(6, 8)}`;
  }
  const match = icalDate.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/);
  if (match) {
    const [, y, m, d, hh, mm] = match;
    return `${y}-${m}-${d} ${hh}:${mm}`;
  }
  return icalDate;
}

function icalNow(): string {
  return new Date().toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

/**
 * Convert a JS Date or ISO string to iCalendar UTC format.
 */
function toICalDateTime(dateStr: string): string {
  // Already in iCal format
  if (/^\d{8}(T\d{6}Z?)?$/.test(dateStr)) {
    if (dateStr.length === 8) {
      // Parse as local-midnight date, then convert to UTC for CalDAV time-range
      const d = new Date(+dateStr.slice(0, 4), +dateStr.slice(4, 6) - 1, +dateStr.slice(6, 8));
      return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
    }
    return dateStr;
  }
  // ISO format -> iCal UTC
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

function setICalProperty(icalData: string, propName: string, value: string | null): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  if (regex.test(icalData)) {
    return icalData.replace(regex, `${propName}:${value}`);
  }
  return icalData.replace(/END:VEVENT/, `${propName}:${value}\r\nEND:VEVENT`);
}

function setICalDateProperty(
  icalData: string,
  propName: string,
  value: string | null,
  tzid?: string
): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  let formatted: string;
  if (value.length === 8) {
    formatted = `${propName};VALUE=DATE:${value}`;
  } else if (tzid) {
    formatted = `${propName};TZID=${tzid}:${normalizeLocalICal(value, tzid)}`;
  } else {
    formatted = `${propName}:${value}`;
  }
  if (regex.test(icalData)) {
    return icalData.replace(regex, formatted);
  }
  return icalData.replace(/END:VEVENT/, `${formatted}\r\nEND:VEVENT`);
}

/**
 * Format the UTC offset for a given IANA zone at a specific instant.
 * Returns "+HHMM" or "-HHMM" (e.g. "+0200", "-0500", "+0000").
 */
function getTimezoneOffset(tzid: string, date: Date): string {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: tzid,
    timeZoneName: 'longOffset',
  });
  const tzPart = fmt.formatToParts(date).find((p) => p.type === 'timeZoneName')?.value || 'GMT';
  // tzPart is "GMT", "GMT+02:00", "GMT-05:30", or "GMT+5:45" depending on engine
  const m = tzPart.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/);
  if (!m) return '+0000';
  const sign = m[1];
  const hh = m[2].padStart(2, '0');
  const mm = m[3] ?? '00';
  return `${sign}${hh}${mm}`;
}

/**
 * Build a minimal but RFC 5545-conformant VTIMEZONE component for the given
 * IANA zone. Uses two reference instants in the current year to detect DST.
 * No external tzdata required — sufficient for iOS / Thunderbird to accept
 * the event and apply the TZID correctly to upcoming occurrences.
 */
export function buildVTimezone(tzid: string): string {
  const year = new Date().getUTCFullYear();
  const janOffset = getTimezoneOffset(tzid, new Date(Date.UTC(year, 0, 15)));
  const julOffset = getTimezoneOffset(tzid, new Date(Date.UTC(year, 6, 15)));
  const lines: string[] = ['BEGIN:VTIMEZONE', `TZID:${tzid}`];
  if (janOffset === julOffset) {
    lines.push(
      'BEGIN:STANDARD',
      'DTSTART:19700101T000000',
      `TZOFFSETFROM:${janOffset}`,
      `TZOFFSETTO:${janOffset}`,
      `TZNAME:${tzid}`,
      'END:STANDARD'
    );
  } else {
    // Higher numeric offset = daylight, lower = standard (works for both hemispheres)
    const standardOffset = janOffset < julOffset ? janOffset : julOffset;
    const daylightOffset = janOffset < julOffset ? julOffset : janOffset;
    lines.push(
      'BEGIN:STANDARD',
      'DTSTART:19701101T000000',
      `TZOFFSETFROM:${daylightOffset}`,
      `TZOFFSETTO:${standardOffset}`,
      `TZNAME:${tzid}`,
      'END:STANDARD',
      'BEGIN:DAYLIGHT',
      'DTSTART:19700301T000000',
      `TZOFFSETFROM:${standardOffset}`,
      `TZOFFSETTO:${daylightOffset}`,
      `TZNAME:${tzid}`,
      'END:DAYLIGHT'
    );
  }
  lines.push('END:VTIMEZONE');
  return lines.join('\r\n');
}

/**
 * Convert a UTC iCal datetime ("YYYYMMDDTHHmmssZ") to wall-clock time in the
 * given IANA zone, returning "YYYYMMDDTHHmmss" (no Z).
 */
function convertUtcICalToLocalICal(utcICal: string, tzid: string): string {
  const m = utcICal.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/);
  if (!m) return utcICal.replace(/Z$/, '');
  const [, y, mo, d, h, mi, s] = m;
  const instant = new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi, +s));
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: tzid,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  });
  const parts = fmt.formatToParts(instant);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00';
  let hour = get('hour');
  if (hour === '24') hour = '00';
  return `${get('year')}${get('month')}${get('day')}T${hour}${get('minute')}${get('second')}`;
}

/**
 * If `value` ends in Z, convert it to wall-clock time in `tzid`. Otherwise
 * assume it is already a floating local datetime in that zone.
 */
function normalizeLocalICal(value: string, tzid: string): string {
  if (/Z$/.test(value)) return convertUtcICalToLocalICal(value, tzid);
  return value;
}

/**
 * Add `hours` to a floating "YYYYMMDDTHHmmss" string, returning the same form.
 */
function addHoursToLocalICal(local: string, hours: number): string {
  const m = local.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/);
  if (!m) return local;
  const [, y, mo, d, h, mi, s] = m;
  const dt = new Date(Date.UTC(+y, +mo - 1, +d, +h + hours, +mi, +s));
  const pad = (n: number) => String(n).padStart(2, '0');
  return (
    `${dt.getUTCFullYear()}${pad(dt.getUTCMonth() + 1)}${pad(dt.getUTCDate())}` +
    `T${pad(dt.getUTCHours())}${pad(dt.getUTCMinutes())}${pad(dt.getUTCSeconds())}`
  );
}

/**
 * Ensure the VCALENDAR contains a VTIMEZONE with the requested TZID. Insert
 * one before the first VEVENT if not already present.
 */
function ensureVTimezone(icalData: string, tzid: string): string {
  const escaped = tzid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const existing = new RegExp(`BEGIN:VTIMEZONE[\\s\\S]*?TZID:${escaped}[\\s\\S]*?END:VTIMEZONE`);
  if (existing.test(icalData)) return icalData;
  const vtz = buildVTimezone(tzid);
  return icalData.replace(/BEGIN:VEVENT/, `${vtz}\r\nBEGIN:VEVENT`);
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Parse calendar collections from a PROPFIND response.
 */
function parseCalendars(responseXml: string): ParsedCalendar[] {
  const calendars: ParsedCalendar[] = [];

  const responseBlocks = responseXml.match(/<d:response>[\s\S]*?<\/d:response>/g);
  if (!responseBlocks) return calendars;

  for (const block of responseBlocks) {
    // Skip the root collection itself
    const resourceTypes = block.match(/<d:resourcetype>([\s\S]*?)<\/d:resourcetype>/);
    if (!resourceTypes || !resourceTypes[1].includes('<cal:calendar')) continue;

    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/);
    const displayNameMatch = block.match(/<d:displayname>([^<]*)<\/d:displayname>/);
    const ctagMatch = block.match(/<cs:getctag>([^<]*)<\/cs:getctag>/);
    const colorMatch = block.match(/<x1:calendar-color[^>]*>([^<]*)<\/x1:calendar-color>/);
    const enabledMatch = block.match(/<x2:calendar-enabled[^>]*>([^<]*)<\/x2:calendar-enabled>/);
    const orderMatch = block.match(/<x1:calendar-order[^>]*>([^<]*)<\/x1:calendar-order>/);

    const compSet = block.match(nsTagContent('supported-calendar-component-set'));
    const components = compSet ? compSet[1] : '';

    const url = hrefMatch?.[1] || '';
    const name = displayNameMatch?.[1] || url.split('/').filter(Boolean).pop() || '';

    calendars.push({
      displayName: name,
      url,
      ctag: ctagMatch?.[1],
      color: colorMatch?.[1],
      supportsEvents: components.includes('VEVENT'),
      supportsTasks: components.includes('VTODO'),
      supportsJournals: components.includes('VJOURNAL'),
      enabled: enabledMatch ? enabledMatch[1] !== '0' : true,
      order: orderMatch ? parseInt(orderMatch[1], 10) : undefined,
    });
  }

  return calendars;
}

/**
 * Parse an ATTENDEE property line into a ParsedAttendee.
 */
function parseAttendee(line: string): ParsedAttendee {
  const attendee: ParsedAttendee = {};

  const cnMatch = line.match(/CN=([^;:]+)/i);
  if (cnMatch) attendee.cn = cnMatch[1].replace(/"/g, '');

  const roleMatch = line.match(/ROLE=([^;:]+)/i);
  if (roleMatch) attendee.role = roleMatch[1];

  const partstatMatch = line.match(/PARTSTAT=([^;:]+)/i);
  if (partstatMatch) attendee.partstat = partstatMatch[1];

  const cutypeMatch = line.match(/CUTYPE=([^;:]+)/i);
  if (cutypeMatch) attendee.cutype = cutypeMatch[1];

  const valueMatch = line.match(/:(?:mailto:)?(.+)$/i);
  if (valueMatch) attendee.email = valueMatch[1];

  return attendee;
}

/**
 * Parse VEVENT fields from a CalDAV REPORT response.
 */
function parseVEvents(responseXml: string): ParsedEvent[] {
  const events: ParsedEvent[] = [];

  const responseBlocks = responseXml.match(/<d:response>[\s\S]*?<\/d:response>/g);
  if (!responseBlocks) return events;

  for (const responseBlock of responseBlocks) {
    const hrefMatch = responseBlock.match(/<d:href>([^<]+)<\/d:href>/);
    const etagMatch = responseBlock.match(/<d:getetag>"?([^"<]+)"?<\/d:getetag>/);
    const calDataMatch = responseBlock.match(nsTagContent('calendar-data'));
    if (!calDataMatch) continue;

    const icalData = calDataMatch[1];
    const veventBlocks = icalData.match(/BEGIN:VEVENT[\s\S]*?END:VEVENT/g);
    if (!veventBlocks) continue;

    for (const block of veventBlocks) {
      const unfolded = unfoldICalLines(block);
      const lines = unfolded.split(/\r?\n/);

      const event: ParsedEvent = {
        uid: '',
        summary: '',
        dtstart: '',
        isAllDay: false,
        categories: [],
        attendees: [],
        etag: etagMatch?.[1],
        href: hrefMatch?.[1],
      };

      for (const line of lines) {
        // Handle ATTENDEE lines specially (they have complex params)
        if (line.startsWith('ATTENDEE')) {
          event.attendees.push(parseAttendee(line));
          continue;
        }

        // Handle ORGANIZER
        if (line.startsWith('ORGANIZER')) {
          const cnMatch = line.match(/CN=([^;:]+)/i);
          const valueMatch = line.match(/:(?:mailto:)?(.+)$/i);
          event.organizer = cnMatch
            ? `${cnMatch[1].replace(/"/g, '')} <${valueMatch?.[1] || ''}>`
            : valueMatch?.[1] || '';
          continue;
        }

        const propMatch = line.match(/^([A-Z][A-Z0-9-]*)(;[^:]*)?:(.*)/);
        if (!propMatch) continue;

        const [, name, params, value] = propMatch;

        switch (name) {
          case 'UID':
            event.uid = value;
            break;
          case 'SUMMARY':
            event.summary = unescapeICalValue(value);
            break;
          case 'DTSTART':
            event.dtstart = value;
            event.isAllDay =
              params?.includes('VALUE=DATE') === true && !params?.includes('VALUE=DATE-TIME');
            break;
          case 'DTEND':
            event.dtend = value;
            break;
          case 'DURATION':
            event.duration = value;
            break;
          case 'LOCATION':
            event.location = unescapeICalValue(value);
            break;
          case 'DESCRIPTION':
            event.description = unescapeICalValue(value);
            break;
          case 'STATUS':
            event.status = value;
            break;
          case 'TRANSP':
            event.transp = value;
            break;
          case 'URL':
            event.url = value;
            break;
          case 'COLOR':
            event.color = value;
            break;
          case 'CLASS':
            event.accessClass = value;
            break;
          case 'CREATED':
            event.created = value;
            break;
          case 'LAST-MODIFIED':
            event.lastModified = value;
            break;
          case 'RRULE':
            event.rrule = value;
            break;
          case 'RECURRENCE-ID':
            event.recurrenceId = value;
            break;
          case 'CATEGORIES':
            event.categories.push(
              ...value
                .split(',')
                .map((c) => c.trim())
                .filter(Boolean)
            );
            break;
        }
      }

      if (event.uid) {
        events.push(event);
      }
    }
  }

  return events;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function formatAttendee(attendee: ParsedAttendee): string {
  let str = attendee.cn || attendee.email || 'unknown';
  if (attendee.cn && attendee.email) {
    str = `${attendee.cn} <${attendee.email}>`;
  }
  const parts: string[] = [];
  if (attendee.role) parts.push(attendee.role);
  if (attendee.partstat) parts.push(attendee.partstat);
  if (parts.length > 0) str += ` (${parts.join(', ')})`;
  return str;
}

function formatRecurrence(rrule: string): string {
  const parts = rrule.split(';');
  const map: Record<string, string> = {};
  for (const part of parts) {
    const [k, v] = part.split('=');
    if (k && v) map[k] = v;
  }

  const freq = map.FREQ;
  const interval = map.INTERVAL ? parseInt(map.INTERVAL, 10) : 1;

  const freqNames: Record<string, [string, string]> = {
    DAILY: ['day', 'days'],
    WEEKLY: ['week', 'weeks'],
    MONTHLY: ['month', 'months'],
    YEARLY: ['year', 'years'],
  };

  let result: string;
  if (freq && freqNames[freq]) {
    const [singular, plural] = freqNames[freq];
    result = interval === 1 ? `Every ${singular}` : `Every ${interval} ${plural}`;
  } else {
    result = `${freq || 'unknown'}`;
  }

  if (map.BYDAY) result += ` on ${map.BYDAY}`;
  if (map.BYMONTHDAY) result += ` on day ${map.BYMONTHDAY}`;
  if (map.BYMONTH) result += ` in month ${map.BYMONTH}`;
  if (map.COUNT) result += `, ${map.COUNT} times`;
  if (map.UNTIL) result += `, until ${formatICalDate(map.UNTIL)}`;

  return result;
}

function formatEvent(event: ParsedEvent): string {
  const dateRange = event.isAllDay
    ? event.dtend
      ? `${formatICalDate(event.dtstart)} - ${formatICalDate(event.dtend)} (all day)`
      : `${formatICalDate(event.dtstart)} (all day)`
    : event.dtend
      ? `${formatICalDate(event.dtstart)} - ${formatICalDate(event.dtend)}`
      : `${formatICalDate(event.dtstart)}`;

  let line = `${event.summary}`;
  line += `\n    When: ${dateRange}`;

  if (event.status && event.status !== 'CONFIRMED') {
    line += ` [${event.status}]`;
  }

  if (event.location) line += `\n    Where: ${event.location}`;
  if (event.organizer) line += `\n    Organizer: ${event.organizer}`;

  if (event.attendees.length > 0) {
    line += `\n    Attendees: ${event.attendees.map(formatAttendee).join('; ')}`;
  }

  if (event.rrule) {
    line += `\n    Recurrence: ${formatRecurrence(event.rrule)}`;
  }

  if (event.categories.length > 0) {
    line += `\n    Tags: ${event.categories.join(', ')}`;
  }

  if (event.description) {
    const desc =
      event.description.length > 200
        ? event.description.substring(0, 200) + '...'
        : event.description;
    line += `\n    ${desc}`;
  }

  if (event.accessClass && event.accessClass !== 'PUBLIC') {
    line += `\n    Class: ${event.accessClass}`;
  }

  line += `\n    UID: ${event.uid}`;

  return line;
}

function formatCalendar(cal: ParsedCalendar): string {
  const parts: string[] = [];
  if (cal.supportsEvents) parts.push('events');
  if (cal.supportsTasks) parts.push('tasks');
  if (cal.supportsJournals) parts.push('journals');

  let line = `${cal.displayName}`;
  if (cal.color) line += ` [${cal.color}]`;
  if (!cal.enabled) line += ' (disabled)';
  line += `\n    Supports: ${parts.join(', ') || 'none'}`;
  line += `\n    URL: ${cal.url}`;
  return line;
}

// ---------------------------------------------------------------------------
// CalDAV helpers
// ---------------------------------------------------------------------------

/**
 * Assert that a calendar supports VEVENT. Throws a descriptive error if not,
 * so create_event fails early with a useful message instead of a 403 from the server.
 */
async function assertCalendarSupportsEvents(
  calendarUrl: string,
  calendarName: string
): Promise<void> {
  const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <c:supported-calendar-component-set />
  </d:prop>
</d:propfind>`;

  const response = await fetchCalDAV(calendarUrl, {
    method: 'PROPFIND',
    body: propfindBody,
    headers: { Depth: '0' },
  });

  if (response.status === 404) {
    throw new Error(
      `Calendar "${calendarName}" not found. Use list_calendars to find available calendar names.`
    );
  }
  if (!response.ok) return; // Can't check — let the server reject if needed

  const text = await response.text();
  const compSet = text.match(nsTagContent('supported-calendar-component-set'));
  const components = compSet ? compSet[1] : '';

  if (!components.includes('VEVENT')) {
    const supported: string[] = [];
    if (components.includes('VTODO')) supported.push('tasks');
    if (components.includes('VJOURNAL')) supported.push('journals');
    throw new Error(
      `Calendar "${calendarName}" does not support events` +
        (supported.length > 0 ? ` (it only supports: ${supported.join(', ')})` : '') +
        `. Use list_calendars to find an event-capable calendar.`
    );
  }
}

/**
 * Resolve an event's CalDAV href, ETag, and full iCal data by UID.
 */
async function resolveEventByUid(
  calendarName: string,
  uid: string
): Promise<{ href: string; etag: string; icalData: string }> {
  const config = getNextcloudConfig();
  const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${calendarName}/`;

  const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:prop-filter name="UID">
          <c:text-match collation="i;octet">${uid}</c:text-match>
        </c:prop-filter>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

  const response = await fetchCalDAV(calDavUrl, {
    method: 'REPORT',
    body: reportBody,
    headers: { Depth: '1' },
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(
      `CalDAV REPORT failed for calendar "${calendarName}": ${response.status} - ${errorText}`
    );
  }

  const responseText = await response.text();

  const hrefMatch = responseText.match(nsTagContent('href'));
  const etagMatch = responseText.match(nsTagContent('getetag'));
  const calDataMatch = responseText.match(nsTagContent('calendar-data'));

  if (!hrefMatch || !etagMatch || !calDataMatch) {
    throw new Error(`Event with UID "${uid}" not found in calendar "${calendarName}"`);
  }

  const rawEtag = decodeXmlEntities(etagMatch[1].trim());
  const etag = rawEtag.startsWith('"') ? rawEtag : `"${rawEtag}"`;

  return {
    href: hrefMatch[1].trim(),
    etag,
    icalData: decodeXmlEntities(calDataMatch[1]),
  };
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

/**
 * List all calendars available to the user.
 */
export const listCalendarsTool = {
  name: 'list_calendars',
  description:
    'List all calendars available to the current user, including their supported component types (events, tasks, journals) and metadata.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/`;

      const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:prop>
    <d:resourcetype />
    <d:displayname />
    <cs:getctag />
    <x1:calendar-color />
    <x1:calendar-order />
    <x2:calendar-enabled />
    <c:supported-calendar-component-set />
  </d:prop>
</d:propfind>`;

      const response = await fetchCalDAV(calDavUrl, {
        method: 'PROPFIND',
        body: propfindBody,
        headers: { Depth: '1' },
      });

      const responseText = await response.text();
      const calendars = parseCalendars(responseText);

      if (calendars.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'No calendars found.',
            },
          ],
        };
      }

      const formatted = calendars.map(formatCalendar).join('\n\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Calendars (${calendars.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing calendars: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List events from a calendar within a time range.
 */
export const listEventsTool = {
  name: 'list_events',
  description:
    'List events from a Nextcloud calendar within an optional time range. Returns event details including time, location, attendees, recurrence, and UIDs.',
  inputSchema: z.object({
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    from: z
      .string()
      .optional()
      .describe(
        'Start of time range (ISO 8601, e.g. 2026-04-12 or 2026-04-12T09:00:00Z). Defaults to today.'
      ),
    to: z
      .string()
      .optional()
      .describe(
        'End of time range (ISO 8601, e.g. 2026-04-12 or 2026-04-12T09:00:00Z). Defaults to 30 days from now.'
      ),
    limit: z
      .number()
      .min(1)
      .max(200)
      .optional()
      .describe('Maximum number of events to return (default: 50)'),
  }),
  handler: async (args: { calendarName: string; from?: string; to?: string; limit?: number }) => {
    try {
      const config = getNextcloudConfig();
      const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/`;
      const limit = args.limit || 50;

      // Default time range: today to 30 days from now
      const now = new Date();
      const defaultFrom = new Date(now);
      defaultFrom.setHours(0, 0, 0, 0);
      const defaultTo = new Date(now);
      defaultTo.setDate(defaultTo.getDate() + 30);

      const fromStr = toICalDateTime(args.from || defaultFrom.toISOString());
      const toStr = toICalDateTime(args.to || defaultTo.toISOString());

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${fromStr}" end="${toStr}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const response = await fetchCalDAV(calDavUrl, {
        method: 'REPORT',
        body: reportBody,
        headers: { Depth: '1' },
      });

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `CalDAV REPORT failed for calendar "${args.calendarName}": ${response.status} - ${errorText}`
        );
      }

      const responseText = await response.text();
      let events = parseVEvents(responseText);

      // Sort by start date
      events.sort((a, b) => a.dtstart.localeCompare(b.dtstart));

      // Apply limit
      if (events.length > limit) {
        events = events.slice(0, limit);
      }

      if (events.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No events found in "${args.calendarName}" between ${formatICalDate(fromStr)} and ${formatICalDate(toStr)}.`,
            },
          ],
        };
      }

      const formatted = events.map(formatEvent).join('\n\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Events in "${args.calendarName}" (${events.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing events: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get a single event by UID.
 */
export const getEventTool = {
  name: 'get_event',
  description:
    'Get detailed information about a single calendar event by its UID, including full description, attendees, and recurrence rules.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
  }),
  handler: async (args: { uid: string; calendarName: string }) => {
    try {
      const { icalData } = await resolveEventByUid(args.calendarName, args.uid);

      // Parse and format the full event
      const fakeResponse = `<d:response><d:href>/</d:href><d:propstat><d:prop><d:getetag>"x"</d:getetag><c:calendar-data>${icalData}</c:calendar-data></d:prop></d:propstat></d:response>`;
      const events = parseVEvents(fakeResponse);

      if (events.length === 0) {
        throw new Error(`Event with UID "${args.uid}" could not be parsed`);
      }

      const event = events[0];
      let output = formatEvent(event);

      // Include full description without truncation for detail view
      if (event.description && event.description.length > 200) {
        output = output.replace(event.description.substring(0, 200) + '...', event.description);
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Create a new calendar event.
 */
export const createEventTool = {
  name: 'create_event',
  description:
    'Create a new calendar event in Nextcloud with date/time, location, description, attendees, and optional recurrence.',
  inputSchema: z.object({
    summary: z.string().describe('The event title'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    dtstart: z
      .string()
      .describe('Start date/time in YYYYMMDD (all-day) or YYYYMMDDTHHmmssZ (timed) format'),
    dtend: z
      .string()
      .optional()
      .describe(
        'End date/time. For all-day events, this is the exclusive end date (day after last day). If omitted, defaults to 1 hour after start (timed) or next day (all-day).'
      ),
    location: z.string().optional().describe('Event location'),
    description: z.string().optional().describe('Event description'),
    status: z
      .enum(['TENTATIVE', 'CONFIRMED', 'CANCELLED'])
      .optional()
      .describe('Event status (default: CONFIRMED)'),
    transp: z
      .enum(['OPAQUE', 'TRANSPARENT'])
      .optional()
      .describe('Time transparency for free/busy (OPAQUE = busy, TRANSPARENT = free)'),
    categories: z.array(z.string()).optional().describe('Tags/categories'),
    attendees: z
      .array(
        z.object({
          email: z.string().describe('Attendee email'),
          cn: z.string().optional().describe('Display name'),
          role: z
            .enum(['REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT', 'CHAIR'])
            .optional()
            .describe('Attendee role'),
          rsvp: z.boolean().optional().describe('Request RSVP (default: true)'),
        })
      )
      .optional()
      .describe('List of attendees'),
    rrule: z
      .string()
      .optional()
      .describe("Recurrence rule (e.g. 'FREQ=WEEKLY;BYDAY=MO,WE,FR' or 'FREQ=MONTHLY;COUNT=12')"),
    accessClass: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('Event visibility classification'),
    alarm: z
      .number()
      .optional()
      .describe('Reminder in minutes before the event (e.g. 15 for 15 min before)'),
    tzid: z
      .string()
      .optional()
      .describe(
        'IANA time zone (e.g. "Europe/Berlin"). When set, a VTIMEZONE block is emitted and dtstart/dtend use TZID instead of UTC. Required for reliable iOS Calendar and Thunderbird CalDAV sync of timed events. UTC inputs (trailing Z) are converted to wall-clock time in this zone.'
      ),
  }),
  handler: async (args: {
    summary: string;
    calendarName: string;
    dtstart: string;
    dtend?: string;
    location?: string;
    description?: string;
    status?: string;
    transp?: string;
    categories?: string[];
    attendees?: Array<{
      email: string;
      cn?: string;
      role?: string;
      rsvp?: boolean;
    }>;
    rrule?: string;
    accessClass?: string;
    alarm?: number;
    tzid?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const eventUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
      const calendarBaseUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/`;
      const calDavUrl = `${calendarBaseUrl}${eventUid}.ics`;

      // Validate the calendar supports VEVENT before building and sending the iCal payload
      await assertCalendarSupportsEvents(calendarBaseUrl, args.calendarName);
      const now = icalNow();
      const isAllDay = args.dtstart.length === 8;

      const tzid = !isAllDay ? args.tzid : undefined;
      const localDtstart = tzid ? normalizeLocalICal(args.dtstart, tzid) : args.dtstart;

      // Calculate default end
      let dtend = args.dtend;
      if (!dtend) {
        if (isAllDay) {
          // All-day: next day
          const d = new Date(
            parseInt(args.dtstart.slice(0, 4)),
            parseInt(args.dtstart.slice(4, 6)) - 1,
            parseInt(args.dtstart.slice(6, 8)) + 1
          );
          dtend = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
        } else if (tzid) {
          // Timed with TZID: 1 hour after local wall time
          dtend = addHoursToLocalICal(localDtstart, 1);
        } else {
          // Timed: 1 hour later (UTC)
          const startDate = new Date(
            parseInt(args.dtstart.slice(0, 4)),
            parseInt(args.dtstart.slice(4, 6)) - 1,
            parseInt(args.dtstart.slice(6, 8)),
            parseInt(args.dtstart.slice(9, 11)),
            parseInt(args.dtstart.slice(11, 13)),
            parseInt(args.dtstart.slice(13, 15))
          );
          startDate.setHours(startDate.getHours() + 1);
          dtend = startDate.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
        }
      }
      const localDtend = tzid ? normalizeLocalICal(dtend, tzid) : dtend;

      let dtstartProp: string;
      let dtendProp: string;
      if (isAllDay) {
        dtstartProp = `DTSTART;VALUE=DATE:${args.dtstart}`;
        dtendProp = `DTEND;VALUE=DATE:${dtend}`;
      } else if (tzid) {
        dtstartProp = `DTSTART;TZID=${tzid}:${localDtstart}`;
        dtendProp = `DTEND;TZID=${tzid}:${localDtend}`;
      } else {
        dtstartProp = `DTSTART:${args.dtstart}`;
        dtendProp = `DTEND:${dtend}`;
      }

      const vtimezoneBlock = tzid ? `${buildVTimezone(tzid)}\r\n` : '';
      let vevent = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AIquila//MCP Server//EN\r\n${vtimezoneBlock}BEGIN:VEVENT\r\nUID:${eventUid}\r\nDTSTAMP:${now}\r\nCREATED:${now}\r\nLAST-MODIFIED:${now}\r\n${dtstartProp}\r\n${dtendProp}\r\nSUMMARY:${escapeICalValue(args.summary)}`;

      if (args.location) {
        vevent += `\r\nLOCATION:${escapeICalValue(args.location)}`;
      }
      if (args.description) {
        vevent += `\r\nDESCRIPTION:${escapeICalValue(args.description)}`;
      }
      if (args.status) {
        vevent += `\r\nSTATUS:${args.status}`;
      }
      if (args.transp) {
        vevent += `\r\nTRANSP:${args.transp}`;
      }
      if (args.accessClass) {
        vevent += `\r\nCLASS:${args.accessClass}`;
      }
      if (args.categories && args.categories.length > 0) {
        vevent += `\r\nCATEGORIES:${args.categories.map(escapeICalValue).join(',')}`;
      }
      if (args.rrule) {
        vevent += `\r\nRRULE:${args.rrule}`;
      }

      // Add attendees
      if (args.attendees && args.attendees.length > 0) {
        // Add organizer (current user)
        vevent += `\r\nORGANIZER;CN=${config.user}:mailto:${config.user}`;

        for (const attendee of args.attendees) {
          let atLine = 'ATTENDEE';
          if (attendee.cn) atLine += `;CN=${attendee.cn}`;
          atLine += `;ROLE=${attendee.role || 'REQ-PARTICIPANT'}`;
          atLine += `;PARTSTAT=NEEDS-ACTION`;
          if (attendee.rsvp !== false) atLine += `;RSVP=TRUE`;
          atLine += `:mailto:${attendee.email}`;
          vevent += `\r\n${atLine}`;
        }
      }

      // Add alarm
      if (args.alarm !== undefined && args.alarm > 0) {
        const sign = '-';
        const totalSeconds = args.alarm * 60;
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const durationStr = `${sign}PT${hours > 0 ? hours + 'H' : ''}${minutes > 0 ? minutes + 'M' : ''}`;
        vevent += `\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:${durationStr}\r\nEND:VALARM`;
      }

      vevent += `\r\nEND:VEVENT\r\nEND:VCALENDAR`;

      const response = await fetchCalDAV(calDavUrl, {
        method: 'PUT',
        body: vevent,
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-None-Match': '*',
        },
      });

      // CalDAV PUT for creation should return 201 (Created) or 204 (No Content)
      if (response.status !== 201 && response.status !== 204) {
        const errorText = await response.text();
        throw new Error(
          `Failed to create event: server returned ${response.status} (expected 201 or 204) - ${errorText}`
        );
      }

      // Verify the event was actually persisted
      try {
        await resolveEventByUid(args.calendarName, eventUid);
      } catch {
        throw new Error(
          `Event creation appeared to succeed (HTTP ${response.status}) but the event ` +
            `could not be verified. The server may have rejected the request silently. ` +
            `UID: ${eventUid}`
        );
      }

      const timeInfo = isAllDay
        ? formatICalDate(args.dtstart)
        : `${formatICalDate(args.dtstart)} - ${formatICalDate(dtend)}`;
      return {
        content: [
          {
            type: 'text' as const,
            text: `Event created successfully: ${args.summary}\n  When: ${timeInfo}\n  UID: ${eventUid}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Update an existing calendar event by UID.
 */
export const updateEventTool = {
  name: 'update_event',
  description:
    "Update an existing calendar event's fields by UID. Uses CalDAV ETag-based optimistic concurrency.",
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event to update'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    summary: z.string().optional().describe('New event title'),
    dtstart: z.string().optional().describe('New start date/time (YYYYMMDD or YYYYMMDDTHHmmssZ)'),
    dtend: z.string().nullable().optional().describe('New end date/time, or null to remove'),
    location: z.string().nullable().optional().describe('New location, or null to remove'),
    description: z.string().nullable().optional().describe('New description, or null to remove'),
    status: z.enum(['TENTATIVE', 'CONFIRMED', 'CANCELLED']).optional().describe('New status'),
    transp: z.enum(['OPAQUE', 'TRANSPARENT']).optional().describe('New transparency'),
    categories: z.array(z.string()).optional().describe('Replace all tags with these'),
    accessClass: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('New classification'),
    rrule: z.string().nullable().optional().describe('New recurrence rule, or null to remove'),
    alarm: z
      .number()
      .nullable()
      .optional()
      .describe('Reminder in minutes before the event, or null to remove existing alarm'),
    attendees: z
      .array(
        z.object({
          email: z.string().describe('Attendee email'),
          cn: z.string().optional().describe('Display name'),
          role: z
            .enum(['REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT', 'CHAIR'])
            .optional()
            .describe('Attendee role'),
          rsvp: z.boolean().optional().describe('Request RSVP (default: true)'),
        })
      )
      .nullable()
      .optional()
      .describe('Replace attendees list, or null to remove all attendees'),
    tzid: z
      .string()
      .optional()
      .describe(
        'IANA time zone (e.g. "Europe/Berlin") for the updated dtstart/dtend. When set, a VTIMEZONE block is added (if missing) and DTSTART/DTEND use TZID instead of UTC. Required for iOS/Thunderbird CalDAV sync.'
      ),
  }),
  handler: async (args: {
    uid: string;
    calendarName: string;
    summary?: string;
    dtstart?: string;
    dtend?: string | null;
    location?: string | null;
    description?: string | null;
    status?: string;
    transp?: string;
    categories?: string[];
    accessClass?: string;
    rrule?: string | null;
    alarm?: number | null;
    attendees?: Array<{
      email: string;
      cn?: string;
      role?: string;
      rsvp?: boolean;
    }> | null;
    tzid?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag, icalData } = await resolveEventByUid(args.calendarName, args.uid);

      let modified = unfoldICalLines(icalData);

      if (args.summary !== undefined) {
        modified = setICalProperty(modified, 'SUMMARY', escapeICalValue(args.summary));
      }
      // Only timed datetimes carry a TZID; all-day (length 8) values are dates.
      const dtstartTz =
        args.tzid && args.dtstart && args.dtstart.length > 8 ? args.tzid : undefined;
      const dtendTz = args.tzid && args.dtend && args.dtend.length > 8 ? args.tzid : undefined;
      if (args.dtstart !== undefined) {
        modified = setICalDateProperty(modified, 'DTSTART', args.dtstart, dtstartTz);
      }
      if (args.dtend !== undefined) {
        modified = setICalDateProperty(modified, 'DTEND', args.dtend, dtendTz);
      }
      if (args.tzid && (dtstartTz || dtendTz)) {
        modified = ensureVTimezone(modified, args.tzid);
      }
      if (args.location !== undefined) {
        modified = setICalProperty(
          modified,
          'LOCATION',
          args.location ? escapeICalValue(args.location) : null
        );
      }
      if (args.description !== undefined) {
        modified = setICalProperty(
          modified,
          'DESCRIPTION',
          args.description ? escapeICalValue(args.description) : null
        );
      }
      if (args.status !== undefined) {
        modified = setICalProperty(modified, 'STATUS', args.status);
      }
      if (args.transp !== undefined) {
        modified = setICalProperty(modified, 'TRANSP', args.transp);
      }
      if (args.accessClass !== undefined) {
        modified = setICalProperty(modified, 'CLASS', args.accessClass);
      }
      if (args.rrule !== undefined) {
        modified = setICalProperty(modified, 'RRULE', args.rrule);
      }
      if (args.categories !== undefined) {
        modified = modified.replace(/^CATEGORIES(;[^:]*)?:.*\r?\n?/gm, '');
        if (args.categories.length > 0) {
          modified = modified.replace(
            /END:VEVENT/,
            `CATEGORIES:${args.categories.map(escapeICalValue).join(',')}\r\nEND:VEVENT`
          );
        }
      }

      // Handle alarm (VALARM)
      if (args.alarm !== undefined) {
        // Remove existing VALARM block
        modified = modified.replace(/BEGIN:VALARM[\s\S]*?END:VALARM\r?\n?/g, '');
        if (args.alarm !== null && args.alarm > 0) {
          const sign = '-';
          const totalSeconds = args.alarm * 60;
          const hours = Math.floor(totalSeconds / 3600);
          const minutes = Math.floor((totalSeconds % 3600) / 60);
          const durationStr = `${sign}PT${hours > 0 ? hours + 'H' : ''}${minutes > 0 ? minutes + 'M' : ''}`;
          modified = modified.replace(
            /END:VEVENT/,
            `BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:${durationStr}\r\nEND:VALARM\r\nEND:VEVENT`
          );
        }
      }

      // Handle attendees
      if (args.attendees !== undefined) {
        // Remove existing ATTENDEE and ORGANIZER lines
        modified = modified.replace(/^ATTENDEE[;:].*\r?\n?/gm, '');
        modified = modified.replace(/^ORGANIZER[;:].*\r?\n?/gm, '');
        if (args.attendees !== null && args.attendees.length > 0) {
          let attendeeLines = `ORGANIZER;CN=${config.user}:mailto:${config.user}`;
          for (const attendee of args.attendees) {
            let atLine = 'ATTENDEE';
            if (attendee.cn) atLine += `;CN=${attendee.cn}`;
            atLine += `;ROLE=${attendee.role || 'REQ-PARTICIPANT'}`;
            atLine += `;PARTSTAT=NEEDS-ACTION`;
            if (attendee.rsvp !== false) atLine += `;RSVP=TRUE`;
            atLine += `:mailto:${attendee.email}`;
            attendeeLines += `\r\n${atLine}`;
          }
          modified = modified.replace(/END:VEVENT/, `${attendeeLines}\r\nEND:VEVENT`);
        }
      }

      // Update timestamps
      const now = icalNow();
      modified = setICalProperty(modified, 'LAST-MODIFIED', now);
      modified = setICalProperty(modified, 'DTSTAMP', now);

      const putUrl = `${config.url}${href}`;
      const putResponse = await fetchCalDAV(putUrl, {
        method: 'PUT',
        body: modified,
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-Match': etag,
        },
      });

      if (putResponse.ok) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Event updated successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (putResponse.status === 412) {
        throw new Error('Event was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await putResponse.text();
        throw new Error(`Failed to update event: ${putResponse.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a calendar event by UID.
 */
export const deleteEventTool = {
  name: 'delete_event',
  description: 'Delete a calendar event from Nextcloud by UID. This action is irreversible.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event to delete'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
  }),
  handler: async (args: { uid: string; calendarName: string }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag } = await resolveEventByUid(args.calendarName, args.uid);

      const deleteUrl = `${config.url}${href}`;
      const response = await fetchCalDAV(deleteUrl, {
        method: 'DELETE',
        headers: {
          'If-Match': etag,
        },
      });

      if (response.ok || response.status === 204) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Event deleted successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (response.status === 412) {
        throw new Error('Event was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await response.text();
        throw new Error(`Failed to delete event: ${response.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Calendar app tools
 */
export const calendarTools = [
  listCalendarsTool,
  listEventsTool,
  getEventTool,
  createEventTool,
  updateEventTool,
  deleteEventTool,
];
