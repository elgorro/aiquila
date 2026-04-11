/**
 * Shared DAV escape/unescape utilities for iCalendar and vCard values.
 *
 * iCalendar (RFC 5545) and vCard (RFC 6350) use nearly identical text escaping.
 * The only difference: vCard allows uppercase \N for newlines, so unescape is
 * case-insensitive for that sequence.
 */

export function escapeDavValue(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\n/g, '\\n');
}

export function unescapeDavValue(
  value: string,
  options?: { caseInsensitiveNewline?: boolean }
): string {
  return value
    .replace(options?.caseInsensitiveNewline ? /\\n/gi : /\\n/g, '\n')
    .replace(/\\,/g, ',')
    .replace(/\\;/g, ';')
    .replace(/\\\\/g, '\\');
}

/** Escape for iCalendar (VEVENT, VTODO) properties. */
export const escapeICalValue = escapeDavValue;

/** Unescape iCalendar property values (case-sensitive \\n). */
export const unescapeICalValue = (value: string) => unescapeDavValue(value);

/** Escape for vCard properties. */
export const escapeVCardValue = escapeDavValue;

/** Unescape vCard property values (case-insensitive \\n per RFC 6350). */
export const unescapeVCardValue = (value: string) =>
  unescapeDavValue(value, { caseInsensitiveNewline: true });
