// SPDX-License-Identifier: MIT

/**
 * Minimal vCard (RFC 6350 / RFC 2426) parser and serializer.
 *
 * Replaces the regex string-surgery that used to mutate vCard text in place.
 * That approach produced bodies SabreDAV rejected with `415 Unsupported Media
 * Type` (GH #396): it mixed line endings, never re-folded long lines, dropped
 * property parameters, and could not see grouped properties (`item1.TEL`).
 *
 * The model is deliberately flat: a vCard is an ordered list of properties,
 * including the `BEGIN`, `VERSION` and `END` lines. That keeps round-tripping a
 * card we did not author byte-stable apart from re-folding, which matters
 * because we PUT back whatever the server gave us.
 */

/** Maximum octets per line before folding (RFC 6350 §3.2). */
const MAX_LINE_OCTETS = 75;

export interface VCardProperty {
  /** Property group, e.g. `item1` in `item1.TEL` — used by the Nextcloud Contacts app for labels. */
  group?: string;
  /** Upper-cased property name, e.g. `TEL`. Use this for comparisons. */
  name: string;
  /**
   * Original spelling of the name, when it differs from {@link name}. Property
   * names are case-insensitive, but some clients look for the exact spelling —
   * Apple and the Nextcloud Contacts app write `X-ABLabel`, not `X-ABLABEL` —
   * so we write back what we read.
   */
  rawName?: string;
  /**
   * Parameters in source order, with names and values kept raw so quoting and
   * spelling survive a round trip (`TYPE="voice,work"`). A valueless parameter
   * (vCard 2.1 style `TEL;WORK:`) is stored with an empty value. Look values up
   * with {@link getParamValues}, which compares case-insensitively.
   */
  params: Array<[string, string]>;
  /** Property value, still escaped as it appears on the wire. */
  value: string;
}

export interface VCardDocument {
  properties: VCardProperty[];
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Split a content line at the first colon that is not inside a quoted
 * parameter value. `TEL;TYPE="voice:work";VALUE=uri:tel:+41...` must split at
 * the colon before `tel:`, not at either of the earlier ones.
 */
function splitAtValueColon(line: string): [string, string] | null {
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      inQuotes = !inQuotes;
    } else if (ch === ':' && !inQuotes) {
      return [line.slice(0, i), line.slice(i + 1)];
    }
  }
  return null;
}

/** Split the part before the value colon on unquoted semicolons. */
function splitParams(head: string): string[] {
  const segments: string[] = [];
  let current = '';
  let inQuotes = false;
  for (const ch of head) {
    if (ch === '"') {
      inQuotes = !inQuotes;
      current += ch;
    } else if (ch === ';' && !inQuotes) {
      segments.push(current);
      current = '';
    } else {
      current += ch;
    }
  }
  segments.push(current);
  return segments;
}

function parseLine(line: string): VCardProperty | null {
  const split = splitAtValueColon(line);
  if (!split) return null;

  const [head, value] = split;
  const segments = splitParams(head);
  const nameToken = segments[0];
  if (!nameToken) return null;

  let group: string | undefined;
  let name = nameToken;
  const dot = nameToken.indexOf('.');
  if (dot > 0) {
    group = nameToken.slice(0, dot);
    name = nameToken.slice(dot + 1);
  }
  if (!name) return null;

  const params: Array<[string, string]> = [];
  for (const segment of segments.slice(1)) {
    if (!segment) continue;
    const eq = segment.indexOf('=');
    if (eq === -1) {
      params.push([segment, '']);
    } else {
      params.push([segment.slice(0, eq), segment.slice(eq + 1)]);
    }
  }

  const upper = name.toUpperCase();
  return {
    group,
    name: upper,
    ...(name === upper ? {} : { rawName: name }),
    params,
    value,
  };
}

/**
 * Parse vCard text into a document.
 *
 * Normalizes all line-ending flavours, unfolds continuation lines, and skips
 * blank lines. Unparseable lines are dropped rather than throwing: we are
 * usually handling a card written by some other client and would rather
 * preserve the rest than fail the whole update.
 */
export function parseVCard(text: string): VCardDocument {
  const unfolded = text
    .replace(/\r\n|\r/g, '\n')
    .replace(/\n[ \t]/g, '')
    .trim();

  const properties: VCardProperty[] = [];
  for (const line of unfolded.split('\n')) {
    if (!line.trim()) continue;
    const property = parseLine(line);
    if (property) properties.push(property);
  }

  return { properties };
}

// ---------------------------------------------------------------------------
// Serialization
// ---------------------------------------------------------------------------

/**
 * Fold a content line to {@link MAX_LINE_OCTETS} octets, splitting only on
 * whole code points so multi-byte UTF-8 sequences are never cut in half.
 * Continuation lines carry a single leading space, which counts toward the
 * limit.
 */
function foldLine(line: string): string {
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

function serializeProperty(property: VCardProperty): string {
  const bare = property.rawName ?? property.name;
  const name = property.group ? `${property.group}.${bare}` : bare;
  const params = property.params
    .map(([key, value]) => (value === '' ? `;${key}` : `;${key}=${value}`))
    .join('');
  return foldLine(`${name}${params}:${property.value}`);
}

/**
 * Serialize a document to wire format: CRLF throughout, folded, and with the
 * structural properties forced into the order SabreDAV's strict MimeDir parser
 * expects (`BEGIN` first, `VERSION` second, `END` last).
 */
export function serializeVCard(doc: VCardDocument): string {
  const body = doc.properties.filter(
    (p) => p.name !== 'BEGIN' && p.name !== 'END' && p.name !== 'VERSION'
  );
  const version = doc.properties.find((p) => p.name === 'VERSION');

  const ordered: VCardProperty[] = [
    { name: 'BEGIN', params: [], value: 'VCARD' },
    version ?? { name: 'VERSION', params: [], value: '3.0' },
    ...body,
    { name: 'END', params: [], value: 'VCARD' },
  ];

  return ordered.map(serializeProperty).join('\r\n') + '\r\n';
}

// ---------------------------------------------------------------------------
// Mutation helpers
// ---------------------------------------------------------------------------

/** Build a property, defaulting the parameter list. */
export function property(
  name: string,
  value: string,
  params: Array<[string, string]> = []
): VCardProperty {
  return { name: name.toUpperCase(), params, value };
}

/** First property with the given name, in any group. */
export function getProperty(doc: VCardDocument, name: string): VCardProperty | undefined {
  const upper = name.toUpperCase();
  return doc.properties.find((p) => p.name === upper);
}

/** All values of a parameter across a property's (possibly repeated) entries. */
export function getParamValues(prop: VCardProperty, paramName: string): string[] {
  const upper = paramName.toUpperCase();
  return prop.params
    .filter(([key]) => key.toUpperCase() === upper)
    .flatMap(([, value]) => value.replace(/^"|"$/g, '').split(','))
    .map((v) => v.trim())
    .filter(Boolean);
}

/** Index just before the terminating `END:VCARD`, or the end of the list. */
function insertionIndex(doc: VCardDocument): number {
  const end = doc.properties.findIndex((p) => p.name === 'END');
  return end === -1 ? doc.properties.length : end;
}

/**
 * Replace the value of a single-valued property, or remove it when `value` is
 * null. Existing parameters and the group prefix are preserved — replacing
 * `NOTE;CHARSET=UTF-8:old` must not silently produce `NOTE:new`.
 */
export function setProperty(doc: VCardDocument, name: string, value: string | null): void {
  const upper = name.toUpperCase();

  if (value === null) {
    doc.properties = doc.properties.filter((p) => p.name !== upper);
    removeOrphanedLabels(doc);
    return;
  }

  const existing = doc.properties.find((p) => p.name === upper);
  if (existing) {
    existing.value = value;
    return;
  }

  doc.properties.splice(insertionIndex(doc), 0, property(upper, value));
}

/**
 * Replace every instance of a multi-valued property (EMAIL, TEL, ADR, ...)
 * with a new set. Grouped instances are removed too, so re-setting phones does
 * not leave the Contacts app's `item1.TEL` entries behind alongside the new
 * ones.
 */
export function replaceAll(doc: VCardDocument, name: string, replacements: VCardProperty[]): void {
  const upper = name.toUpperCase();
  doc.properties = doc.properties.filter((p) => p.name !== upper);
  removeOrphanedLabels(doc);
  doc.properties.splice(insertionIndex(doc), 0, ...replacements);
}

/**
 * Drop `X-ABLabel` properties whose group no longer has anything to label.
 * Removing `item1.TEL` without this leaves a dangling `item1.X-ABLabel`, which
 * the Contacts app renders as an empty labelled field.
 */
function removeOrphanedLabels(doc: VCardDocument): void {
  const groupsInUse = new Set(
    doc.properties.filter((p) => p.group && p.name !== 'X-ABLABEL').map((p) => p.group)
  );
  doc.properties = doc.properties.filter(
    (p) => p.name !== 'X-ABLABEL' || !p.group || groupsInUse.has(p.group)
  );
}
