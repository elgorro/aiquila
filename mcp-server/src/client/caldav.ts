// SPDX-License-Identifier: MIT

import { logger } from '../logger.js';
import { basicAuth, getNextcloudConfig } from '../tools/types.js';

/**
 * Build a regex that matches an XML element with any namespace prefix.
 * e.g. nsTagContent("calendar-data") matches <c:calendar-data>, <cal:calendar-data>, <calendar-data>
 */
export function nsTagContent(localName: string): RegExp {
  return new RegExp(
    `<(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}[^>]*>([\\s\\S]*?)</(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}>`
  );
}

/**
 * Decode XML character entities in text extracted via regex.
 * Needed because we parse XML responses with regex instead of a DOM parser.
 */
export function decodeXmlEntities(text: string): string {
  return (
    text
      // Numeric character references. SabreDAV serializes calendar-data and
      // address-data through libxml, which escapes carriage returns as `&#13;`
      // so they survive XML whitespace normalization. Leaving those undecoded
      // corrupts every line ending of the iCalendar/vCard payload (GH #396).
      .replace(/&#x([0-9a-f]+);/gi, (_, hex: string) => String.fromCodePoint(parseInt(hex, 16)))
      .replace(/&#(\d+);/g, (_, dec: string) => String.fromCodePoint(parseInt(dec, 10)))
      .replace(/&quot;/g, '"')
      .replace(/&apos;/g, "'")
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      // `&amp;` last, so an encoded `&amp;#13;` decodes to the literal text
      // `&#13;` rather than being decoded twice into a carriage return.
      .replace(/&amp;/g, '&')
  );
}

/**
 * Pick, from a calendar-query or addressbook-query multistatus, the resource
 * whose UID equals `uid` exactly. A UID prop-filter with text-match is a
 * substring match (RFC 4791 §9.7.5; RFC 6352 §10.5.4 by default), so the
 * server may also return resources whose UID merely contains `uid`.
 *
 * @param dataTag `calendar-data` or `address-data`
 * @returns href, ETag (quoted) and the XML-decoded payload, or undefined
 */
export function findResourceByUid(
  responseXml: string,
  uid: string,
  dataTag: 'calendar-data' | 'address-data'
): { href: string; etag: string; data: string } | undefined {
  const blocks = responseXml.match(new RegExp(nsTagContent('response').source, 'g')) ?? [];
  for (const block of blocks) {
    const hrefMatch = block.match(nsTagContent('href'));
    const etagMatch = block.match(nsTagContent('getetag'));
    const dataMatch = block.match(nsTagContent(dataTag));
    if (!hrefMatch || !etagMatch || !dataMatch) continue;

    const data = decodeXmlEntities(dataMatch[1]);
    // Unfold (RFC 5545 §3.1, RFC 6350 §3.2) before reading the UID lines
    const unfolded = data.replace(/\r?\n[ \t]/g, '');
    const uids = [...unfolded.matchAll(/^UID(?:;[^:\r\n]*)?:(.*?)\r?$/gm)].map((m) => m[1]);
    if (!uids.includes(uid)) continue;

    const rawEtag = decodeXmlEntities(etagMatch[1].trim());
    const etag = rawEtag.startsWith('"') ? rawEtag : `"${rawEtag}"`;
    return { href: hrefMatch[1].trim(), etag, data };
  }
  return undefined;
}

/**
 * Encode text for interpolation into an XML request body.
 * Counterpart to decodeXmlEntities — used when building CalDAV/CardDAV REPORT
 * filters from user-supplied values (GH #402).
 */
export function encodeXmlEntities(text: string): string {
  return (
    text
      // `&` first, otherwise the ampersands introduced below get re-encoded.
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&apos;')
  );
}

/**
 * Fetch data from CalDAV endpoint using basic authentication
 */
export async function fetchCalDAV(
  url: string,
  options: {
    method?: string;
    body?: string;
    headers?: Record<string, string>;
  } = {}
): Promise<Response> {
  const auth = basicAuth(getNextcloudConfig());

  const method = options.method || 'GET';

  // Normalize URL: collapse double slashes (but not in protocol://)
  const normalizedUrl = url.replace(/([^:])\/\/+/g, '$1/');

  const t0 = Date.now();
  const response = await fetch(normalizedUrl, {
    method,
    headers: {
      Authorization: `Basic ${auth}`,
      'Content-Type': 'application/xml; charset=utf-8',
      ...options.headers,
    },
    body: options.body,
    redirect: 'manual',
  });
  logger.trace(
    { method, url: normalizedUrl, status: response.status, ms: Date.now() - t0 },
    '[nc] HTTP'
  );

  // Handle redirects manually to preserve method and body
  if (response.status >= 300 && response.status < 400) {
    const location = response.headers.get('Location');
    if (!location) {
      throw new Error(`CalDAV server returned redirect ${response.status} without Location header`);
    }

    const redirectUrl = new URL(location, normalizedUrl).toString();

    // 307/308 preserve the method and body — safe to follow
    if (response.status === 307 || response.status === 308) {
      return fetchCalDAV(redirectUrl, options);
    }

    // 301/302/303: safe for GET/HEAD, but would change method for PUT/DELETE/etc.
    if (method === 'GET' || method === 'HEAD') {
      return fetchCalDAV(redirectUrl, {
        ...options,
        method: 'GET',
        body: undefined,
      });
    }

    throw new Error(
      `CalDAV server returned redirect ${response.status} for ${method} request. ` +
        `This would change the HTTP method and drop the request body. ` +
        `Redirect target: ${redirectUrl}. ` +
        `Check that NEXTCLOUD_URL is correct and does not need trailing slash adjustment.`
    );
  }

  return response;
}
