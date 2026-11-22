import { randomBytes } from 'node:crypto';

/**
 * Markers around third-party content handed to the model.
 *
 * A wrapper reads
 *
 *     [UNTRUSTED EXTERNAL CONTENT - MAIL ATTACHMENT boundary=<hex>] "name" (...)
 *     <content>
 *     [END EXTERNAL CONTENT boundary=<hex>]
 *
 * The content is written by someone else, so it can contain text that looks
 * like a closing marker followed by text that looks trusted. Two defences:
 *
 * - every wrapper carries a fresh random boundary, and only the end line with
 *   the same boundary closes it, so the content cannot guess the real close;
 * - marker phrases inside the content are replaced, so even a reader that
 *   ignores the boundary does not see a close (or a new open) inside it.
 */

/** What the content is, as named in the start line. */
export type UntrustedKind = 'MAIL ATTACHMENT' | 'PDF ATTACHMENT';

const START = 'UNTRUSTED EXTERNAL CONTENT';
const END = 'END EXTERNAL CONTENT';

/**
 * Either marker phrase, in any case, with any run of spaces, underscores or
 * hyphens between the words, so `[end_external-content` is caught too. No
 * bracket is required: a look-alike bracket (`［`, `〔`) would otherwise slip
 * past, and the words alone are what reads as a marker.
 */
const MARKER_PHRASE = /(?:END|UNTRUSTED)[\s_-]+EXTERNAL[\s_-]+CONTENT/gi;

/** What a marker phrase inside the content is replaced with. */
export const NEUTRALISED_MARKER = '(marker text removed)';

/** `content` with every marker phrase replaced by {@link NEUTRALISED_MARKER}. */
export function neutraliseMarkers(content: string): string {
  return content.replace(MARKER_PHRASE, NEUTRALISED_MARKER);
}

/**
 * The start line alone, for a label ahead of content that is not text (an
 * image or an embedded resource): `[UNTRUSTED EXTERNAL CONTENT - <kind>] <detail>`.
 * `detail` is the caller's to make safe (quote a sender-chosen name with
 * `JSON.stringify`), and it must not hold a line break.
 */
export function untrustedLabel(kind: UntrustedKind, detail?: string): string {
  return detail === undefined ? `[${START} - ${kind}]` : `[${START} - ${kind}] ${detail}`;
}

/**
 * `content` between a start line and an end line that share a random
 * boundary, with any marker phrase inside the content neutralised. `detail`
 * follows the start line's closing bracket, as in {@link untrustedLabel}.
 */
export function wrapUntrusted(kind: UntrustedKind, content: string, detail?: string): string {
  const body = neutraliseMarkers(content);
  // 128 random bits: the content cannot hold it but by chance. Draw again if it does.
  let boundary: string;
  do {
    boundary = randomBytes(16).toString('hex');
  } while (body.includes(boundary));
  const start = `[${START} - ${kind} boundary=${boundary}]`;
  return `${detail === undefined ? start : `${start} ${detail}`}\n${body}\n[${END} boundary=${boundary}]`;
}
