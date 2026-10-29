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
