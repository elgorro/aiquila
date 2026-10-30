// SPDX-License-Identifier: MIT

/**
 * Shared DAV escape/unescape utilities for iCalendar and vCard values.
 *
 * iCalendar (RFC 5545) and vCard (RFC 6350) use nearly identical text escaping.
 * The only difference: vCard allows uppercase \N for newlines, so unescape is
 * case-insensitive for that sequence.
 */

export function escapeDavValue(value: string): string {
  return (
    value
      .replace(/\\/g, '\\\\')
      .replace(/;/g, '\\;')
      .replace(/,/g, '\\,')
      // A raw CR would terminate the content line and make the payload
      // unparseable, so CRLF and lone CR both collapse to an escaped newline.
      .replace(/\r\n|\r|\n/g, '\\n')
  );
}

/**
 * Sanitize a value for a URI- or date-valued property (URL, TEL, EMAIL, BDAY).
 *
 * Text escaping must NOT be applied to these: `;` and `,` carry no special
 * meaning in their values, and escaping them leaves a literal backslash in the
 * URI once a strict parser reads it back. All that is actually required is that
 * the value cannot terminate the content line.
 */
export function sanitizeDavUriValue(value: string): string {
  // eslint-disable-next-line no-control-regex
  return value.replace(/[\x00-\x1f\x7f]/g, '');
}

export function unescapeDavValue(
  value: string,
  options?: { caseInsensitiveNewline?: boolean }
): string {
  // Single pass: a chained replace would rewrite the `\n` inside an escaped
  // backslash sequence (`\\n` — a literal backslash followed by `n`) into a
  // newline before the backslash itself was unescaped.
  const newlinePattern = options?.caseInsensitiveNewline ? /n/i : /n/;
  return value.replace(/\\(.)/g, (match, char: string) => {
    if (newlinePattern.test(char)) return '\n';
    if (char === ',' || char === ';' || char === '\\') return char;
    return match;
  });
}

/** Escape for iCalendar (VEVENT, VTODO) properties. */
export const escapeICalValue = escapeDavValue;

/** Unescape iCalendar property values (case-sensitive \\n). */
export const unescapeICalValue = (value: string) => unescapeDavValue(value);

/** Escape for vCard text properties (FN, N, ADR, ORG, NOTE, CATEGORIES, ...). */
export const escapeVCardValue = escapeDavValue;

/** Sanitize vCard URI/date properties (URL, TEL, EMAIL, BDAY) — no escaping. */
export const sanitizeVCardUriValue = sanitizeDavUriValue;

/** Unescape vCard property values (case-insensitive \\n per RFC 6350). */
export const unescapeVCardValue = (value: string) =>
  unescapeDavValue(value, { caseInsensitiveNewline: true });

// --- Values written unescaped into iCalendar ---
//
// Text values go through escapeICalValue. Dates, recurrence rules, time zone
// ids and calendar addresses are written as they are, so they must be checked:
// a CR or LF in any of them ends the content line, and whatever follows
// becomes a property of its own (an extra ATTENDEE, say, that Nextcloud then
// sends an invitation to).

/** RFC 5545 §3.3.4 DATE (`YYYYMMDD`) or §3.3.5 DATE-TIME (`YYYYMMDDTHHMMSS`, optionally `Z`). */
export const ICAL_DATE_OR_DATE_TIME = /^\d{8}(T\d{6}Z?)?$/;

/** RFC 5545 §3.3.10 RECUR: rule parts made of letters, digits and `= ; , + -`. */
export const ICAL_RECUR = /^[A-Za-z0-9=;,+-]+$/;

/** An IANA time zone name, written unquoted after `TZID=` (RFC 5545 §3.2.19). */
export const IANA_TZID = /^[A-Za-z0-9_+-]+(\/[A-Za-z0-9_+-]+)*$/;

/**
 * A parameter value as an RFC 5545 §3.2 quoted-string. QSAFE-CHAR excludes
 * control characters (other than tab) and DQUOTE, so those are dropped.
 */
export function quoteICalParam(value: string): string {
  // eslint-disable-next-line no-control-regex
  return `"${value.replace(/[\x00-\x08\x0a-\x1f\x7f"]/g, '')}"`;
}

// --- Content lines (RFC 5545 §3.1, RFC 6350 §3.2) ---

// Lines SHOULD NOT be longer than 75 octets, excluding the line break.
const MAX_LINE_OCTETS = 75;

/**
 * Fold a content line to 75 octets, splitting only on whole code points so
 * multi-byte UTF-8 sequences are never cut in half (RFC 5545 §3.1: "a
 * multi-octet sequence MUST NOT be split"). Continuation lines carry a single
 * leading space, which counts toward the limit.
 */
export function foldContentLine(line: string): string {
  if (Buffer.byteLength(line, 'utf8') <= MAX_LINE_OCTETS) return line;

  const chunks: string[] = [];
  let current = '';
  let currentOctets = 0;
  // First line has the full budget; continuation lines lose one octet to the
  // leading space.
  let limit = MAX_LINE_OCTETS;

  for (const char of line) {
    const octets = Buffer.byteLength(char, 'utf8');
    if (currentOctets + octets > limit) {
      chunks.push(current);
      current = '';
      currentOctets = 0;
      limit = MAX_LINE_OCTETS - 1;
    }
    current += char;
    currentOctets += octets;
  }
  chunks.push(current);

  return chunks.join('\r\n ');
}

/**
 * An iCalendar object in wire format (RFC 5545 §3.1): every content line
 * ends in CRLF — the last one too — and is folded to 75 octets. Input may
 * use any line ending and may already be folded; it is unfolded first so
 * nothing is folded twice. Empty lines, which are not content lines, go.
 */
export function formatICalendar(text: string): string {
  const lines = text
    .replace(/\r\n|\r|\n/g, '\n')
    .replace(/\n[ \t]/g, '')
    .split('\n')
    .filter((line) => line !== '');
  return lines.map(foldContentLine).join('\r\n') + '\r\n';
}

// --- Request paths (RFC 3986 §3.3, §2.1) ---

/**
 * One path segment taken from tool input or config (a user id, a calendar or
 * address book name, a file name), percent-encoded so it addresses exactly
 * that resource. A path segment admits only `pchar`; `/`, `?`, `#`, `%` or a
 * space would end it, start the query or fragment, or break the URL. `.` and
 * `..` are dot-segments that a URL parser resolves (§5.2.4), walking out of
 * the collection, so they are rejected; so is an empty segment.
 */
export function davSegment(value: string | number): string {
  const text = String(value);
  if (text === '' || text === '.' || text === '..') {
    throw new Error(`Invalid name for a DAV path: "${text}"`);
  }
  return encodeURIComponent(text);
}

/**
 * A path under `/remote.php/dav/`, every segment encoded with davSegment.
 * Collections need the trailing slash added by the caller.
 */
export function davPath(...segments: (string | number)[]): string {
  return `/remote.php/dav/${segments.map(davSegment).join('/')}`;
}

/**
 * A slash-separated file path (`/Documents/a b.txt`) as encoded segments.
 * Leading, trailing and doubled slashes carry no segment and are dropped.
 */
export function davFilePath(path: string): string {
  return path
    .split('/')
    .filter((segment) => segment !== '')
    .map(davSegment)
    .join('/');
}

/**
 * The last segment of an href the server sent, decoded: the name the tools
 * take as input and encode again. An href with a malformed escape is returned
 * as it is.
 */
export function lastHrefSegment(href: string): string {
  const segment = href.split('/').filter(Boolean).pop() ?? '';
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}
