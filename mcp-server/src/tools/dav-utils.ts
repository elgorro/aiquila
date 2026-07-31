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

/** Escape for vCard properties. */
export const escapeVCardValue = escapeDavValue;

/** Unescape vCard property values (case-insensitive \\n per RFC 6350). */
export const unescapeVCardValue = (value: string) =>
  unescapeDavValue(value, { caseInsensitiveNewline: true });
