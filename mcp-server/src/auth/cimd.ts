// SPDX-License-Identifier: MIT

// Client ID Metadata Documents (draft-ietf-oauth-client-id-metadata-document;
// MCP 2026-07-28 "Client Registration"). The client_id is an HTTPS URL that
// dereferences to the client's registration metadata, so any client can start
// a flow without POST /register.
//
// The client_id is attacker-supplied and fetched server-side, which makes this
// an SSRF sink. The fetch is open to any public HTTPS host — an allowlist would
// defeat the registration-free onboarding CIMD exists for — and hardened instead:
//   - https only, no userinfo, no fragment, no dot segments (draft §3)
//   - every resolved address is checked against the RFC 6890 special-purpose
//     ranges, and the connection is pinned to the checked address, so a DNS
//     rebind between check and connect is not possible (draft §8.6)
//   - redirects are never followed (draft §5)
//   - 5 s timeout, 5 KB body cap read from the stream, not Content-Length (§8.7)
//   - errors and invalid documents are never cached (§5.2)

import { BlockList, isIP } from 'node:net';
import { lookup as dnsLookup } from 'node:dns/promises';
import https from 'node:https';
import type { IncomingHttpHeaders } from 'node:http';
import {
  specTypeSchemas,
  type OAuthClientInformationFull,
  type OAuthClientMetadata,
} from '@modelcontextprotocol/server';
import { isLoopbackRedirect } from './redirect-uri.js';

const FETCH_TIMEOUT_MS = 5_000;
// draft-ietf-oauth-client-id-metadata-document §8.7: "The recommended maximum
// size to read is 5 kilobytes."
const MAX_DOCUMENT_BYTES = 5 * 1024;
// Used when the response carries no freshness information.
const DEFAULT_CACHE_TTL_MS = 15 * 60 * 1000;
// Upper bound on any max-age, so a revoked or edited document is picked up.
const MAX_CACHE_TTL_MS = 24 * 60 * 60 * 1000;
const MAX_CACHE_ENTRIES = 1000;

// RFC 6890 special-purpose address registries (with the later additions in the
// IANA registries), plus multicast. None of these is a public HTTPS origin.
const BLOCKED = new BlockList();
for (const [net, prefix] of [
  ['0.0.0.0', 8], // "this network"
  ['10.0.0.0', 8], // private-use (RFC 1918)
  ['100.64.0.0', 10], // shared address space (CGNAT)
  ['127.0.0.0', 8], // loopback
  ['169.254.0.0', 16], // link local — cloud metadata endpoints live here
  ['172.16.0.0', 12], // private-use
  ['192.0.0.0', 24], // IETF protocol assignments
  ['192.0.2.0', 24], // TEST-NET-1
  ['192.88.99.0', 24], // 6to4 relay anycast
  ['192.168.0.0', 16], // private-use
  ['198.18.0.0', 15], // benchmarking
  ['198.51.100.0', 24], // TEST-NET-2
  ['203.0.113.0', 24], // TEST-NET-3
  ['224.0.0.0', 4], // multicast
  ['240.0.0.0', 4], // reserved, incl. limited broadcast
] as const) {
  BLOCKED.addSubnet(net, prefix, 'ipv4');
}
for (const [net, prefix] of [
  ['::', 128], // unspecified
  ['::1', 128], // loopback
  // IPv4-mapped (::ffff:0:0/96) is handled in isBlockedAddress: BlockList
  // matches IPv4 queries against it, so as a rule it would block all of IPv4.
  ['64:ff9b::', 96], // IPv4/IPv6 translation — likewise
  ['64:ff9b:1::', 48], // local-use translation
  ['100::', 64], // discard-only
  ['2001::', 23], // IETF protocol assignments, incl. Teredo
  ['2001:db8::', 32], // documentation
  ['2002::', 16], // 6to4
  ['fc00::', 7], // unique local
  ['fe80::', 10], // link local
  ['ff00::', 8], // multicast
] as const) {
  BLOCKED.addSubnet(net, prefix, 'ipv6');
}

/** True for an IPv4-mapped IPv6 address (::ffff:a.b.c.d), in any spelling. */
function isIPv4Mapped(address: string): boolean {
  const normalized = new URL(`http://[${address}]`).hostname.slice(1, -1);
  return /^::ffff:[0-9a-f]{1,4}:[0-9a-f]{1,4}$/.test(normalized);
}

/** True when `address` is in a range a client ID document must never be fetched from. */
export function isBlockedAddress(address: string): boolean {
  const family = isIP(address);
  if (family === 4) return BLOCKED.check(address, 'ipv4');
  if (family === 6) {
    // A resolver has no business answering with a mapped address; it would
    // reach whatever IPv4 address is embedded, so it is never a public origin.
    return isIPv4Mapped(address) || BLOCKED.check(address, 'ipv6');
  }
  return true;
}

/** A client_id that is not a usable metadata document URL, or a document that fails validation. */
export class ClientIdDocumentError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ClientIdDocumentError';
  }
}

/** True when `clientId` is meant as a metadata document URL rather than an opaque id. */
export function looksLikeClientIdUrl(clientId: string): boolean {
  return clientId.startsWith('https://');
}

/**
 * Parses and checks a client identifier URL against
 * draft-ietf-oauth-client-id-metadata-document §3. The raw string is inspected
 * as well as the parsed URL, because WHATWG URL parsing silently removes dot
 * segments and an empty fragment.
 */
export function parseClientIdUrl(clientId: string): URL {
  let url: URL;
  try {
    url = new URL(clientId);
  } catch {
    throw new ClientIdDocumentError('client_id is not a valid URL');
  }
  if (url.protocol !== 'https:') {
    throw new ClientIdDocumentError('client_id URL must use https');
  }
  if (url.username || url.password) {
    throw new ClientIdDocumentError('client_id URL must not contain userinfo');
  }
  if (clientId.includes('#')) {
    throw new ClientIdDocumentError('client_id URL must not contain a fragment');
  }
  if (url.pathname === '/' || url.pathname === '') {
    throw new ClientIdDocumentError('client_id URL must contain a path component');
  }
  const rawPath = clientId.slice(clientId.indexOf('/', 'https://'.length)).split(/[?#]/)[0];
  for (const segment of rawPath.split('/')) {
    const decoded = segment.replace(/%2e/gi, '.');
    if (decoded === '.' || decoded === '..') {
      throw new ClientIdDocumentError('client_id URL must not contain dot path segments');
    }
  }
  return url;
}

export interface FetchedDocument {
  status: number;
  headers: IncomingHttpHeaders;
  body: string;
}

/** Fetches `url` over a connection pinned to `address`, never following redirects. */
export type DocumentFetcher = (
  url: URL,
  address: { address: string; family: number }
) => Promise<FetchedDocument>;

export type HostResolver = (hostname: string) => Promise<{ address: string; family: number }[]>;

/** Reads at most `maxBytes` from `stream`; throws as soon as the limit is passed. */
export async function readCapped(stream: AsyncIterable<Buffer>, maxBytes: number): Promise<string> {
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const chunk of stream) {
    size += chunk.length;
    if (size > maxBytes) {
      throw new ClientIdDocumentError(`client metadata document exceeds ${maxBytes} bytes`);
    }
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

const fetchPinned: DocumentFetcher = (url, pinned) =>
  new Promise((resolve, reject) => {
    const req = https.request(
      url,
      {
        method: 'GET',
        // Some hosts refuse requests that carry no User-Agent.
        headers: { Accept: 'application/json', 'User-Agent': 'aiquila-mcp (client ID metadata)' },
        // Connect to the address that passed the blocklist check, not to
        // whatever the name resolves to a moment later. TLS still verifies the
        // certificate against the URL's hostname (SNI defaults to it).
        lookup: (_hostname, options, callback) => {
          if ((options as { all?: boolean }).all) {
            (callback as (err: null, addresses: { address: string; family: number }[]) => void)(
              null,
              [pinned]
            );
          } else {
            callback(null, pinned.address, pinned.family);
          }
        },
        signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
      },
      (res) => {
        readCapped(res, MAX_DOCUMENT_BYTES).then(
          (body) => resolve({ status: res.statusCode ?? 0, headers: res.headers, body }),
          (err) => {
            res.destroy();
            reject(err);
          }
        );
      }
    );
    req.on('error', reject);
    req.end();
  });

const resolveHost: HostResolver = (hostname) => dnsLookup(hostname, { all: true, verbatim: true });

/**
 * Freshness lifetime for a fetched document, from its Cache-Control header
 * (RFC 9111 §5.2.2). Returns 0 when the response must not be stored or reused
 * without revalidation.
 */
export function cacheLifetimeMs(headers: IncomingHttpHeaders): number {
  const cc = String(headers['cache-control'] ?? '').toLowerCase();
  if (/(^|[\s,])(no-store|no-cache|private)([\s,=]|$)/.test(cc)) return 0;
  const maxAge = /(?:^|[\s,])max-age\s*=\s*"?(\d+)/.exec(cc);
  if (maxAge) return Math.min(Number(maxAge[1]) * 1000, MAX_CACHE_TTL_MS);
  return DEFAULT_CACHE_TTL_MS;
}

function isJsonContentType(headers: IncomingHttpHeaders): boolean {
  const type = String(headers['content-type'] ?? '')
    .split(';')[0]
    .trim()
    .toLowerCase();
  return type === 'application/json' || /^application\/[\w.+-]+\+json$/.test(type);
}

/**
 * Redirect URIs a document may list. Same origin as the client_id keeps the
 * party named on the consent page (the client_id host) the party that receives
 * the code; loopback redirects (RFC 8252 §7.3) reach the user's own machine and
 * are what native clients register.
 */
function allowedRedirectUri(uri: string, clientIdUrl: URL): boolean {
  let parsed: URL;
  try {
    parsed = new URL(uri);
  } catch {
    return false;
  }
  if (parsed.hash) return false;
  return parsed.origin === clientIdUrl.origin || isLoopbackRedirect(parsed);
}

/** Validates a fetched document and converts it to client information. */
export function validateClientIdDocument(
  clientId: string,
  clientIdUrl: URL,
  doc: unknown
): OAuthClientInformationFull {
  if (typeof doc !== 'object' || doc === null || Array.isArray(doc)) {
    throw new ClientIdDocumentError('client metadata document is not a JSON object');
  }
  const raw = doc as Record<string, unknown>;

  // Simple string comparison (draft §3), and the document must name itself (§4):
  // otherwise any URL could serve a document impersonating another client.
  if (raw.client_id !== clientId) {
    throw new ClientIdDocumentError('client_id in the document does not match its URL');
  }
  if (typeof raw.client_name !== 'string' || raw.client_name.trim() === '') {
    throw new ClientIdDocumentError('client metadata document lacks client_name');
  }
  if (!Array.isArray(raw.redirect_uris) || raw.redirect_uris.length === 0) {
    throw new ClientIdDocumentError('client metadata document lacks redirect_uris');
  }
  // §4.1: no shared secrets in a public document.
  if ('client_secret' in raw || 'client_secret_expires_at' in raw) {
    throw new ClientIdDocumentError('client metadata document must not contain a client secret');
  }
  // §4.1 forbids the client_secret_* methods; private_key_jwt (§6.2) is not
  // supported here, so only public clients are accepted.
  if (raw.token_endpoint_auth_method !== undefined && raw.token_endpoint_auth_method !== 'none') {
    throw new ClientIdDocumentError(
      `token_endpoint_auth_method "${String(raw.token_endpoint_auth_method)}" is not supported`
    );
  }

  const result = specTypeSchemas.OAuthClientMetadata['~standard'].validate(raw);
  if (result instanceof Promise || result.issues) {
    throw new ClientIdDocumentError('client metadata document is malformed');
  }
  const metadata = result.value as OAuthClientMetadata;

  for (const uri of metadata.redirect_uris) {
    if (!allowedRedirectUri(String(uri), clientIdUrl)) {
      throw new ClientIdDocumentError(
        `redirect_uri ${String(uri)} is neither same-origin with the client_id nor loopback`
      );
    }
  }

  return {
    ...metadata,
    client_id: clientId,
    client_name: raw.client_name,
    token_endpoint_auth_method: 'none',
  };
}

interface CacheEntry {
  client: OAuthClientInformationFull;
  expiresAt: number;
}

export interface ClientIdDocumentResolverOptions {
  resolveHost?: HostResolver;
  fetchDocument?: DocumentFetcher;
  now?: () => number;
}

/** Fetches, validates and caches client ID metadata documents. */
export class ClientIdDocumentResolver {
  private readonly cache = new Map<string, CacheEntry>();
  private readonly resolveHost: HostResolver;
  private readonly fetchDocument: DocumentFetcher;
  private readonly now: () => number;

  constructor(options?: ClientIdDocumentResolverOptions) {
    this.resolveHost = options?.resolveHost ?? resolveHost;
    this.fetchDocument = options?.fetchDocument ?? fetchPinned;
    this.now = options?.now ?? Date.now;
  }

  /** Resolves `clientId` to client information, or throws ClientIdDocumentError. */
  async resolve(clientId: string): Promise<OAuthClientInformationFull> {
    const cached = this.cache.get(clientId);
    if (cached && cached.expiresAt > this.now()) return cached.client;
    this.cache.delete(clientId);

    const url = parseClientIdUrl(clientId);
    // URL keeps IPv6 literals bracketed; lookup wants them bare.
    const hostname = url.hostname.replace(/^\[(.*)\]$/, '$1');

    let addresses: { address: string; family: number }[];
    try {
      addresses = await this.resolveHost(hostname);
    } catch {
      throw new ClientIdDocumentError(`could not resolve ${hostname}`);
    }
    if (addresses.length === 0) {
      throw new ClientIdDocumentError(`could not resolve ${hostname}`);
    }
    // Every answer is checked, not just the one we connect to: a name that
    // resolves to both a public and a private address is not a public origin.
    const blocked = addresses.find((a) => isBlockedAddress(a.address));
    if (blocked) {
      throw new ClientIdDocumentError(
        `${hostname} resolves to a special-purpose address (${blocked.address})`
      );
    }

    let response: FetchedDocument;
    try {
      response = await this.fetchDocument(url, addresses[0]);
    } catch (err) {
      if (err instanceof ClientIdDocumentError) throw err;
      throw new ClientIdDocumentError(
        `fetching the client metadata document failed: ${err instanceof Error ? err.message : String(err)}`
      );
    }
    // §5: anything but 200 is an error — 3xx included, since redirects are not followed.
    if (response.status !== 200) {
      throw new ClientIdDocumentError(
        `client metadata document request returned HTTP ${response.status}`
      );
    }
    if (!isJsonContentType(response.headers)) {
      throw new ClientIdDocumentError('client metadata document is not served as JSON');
    }
    let doc: unknown;
    try {
      doc = JSON.parse(response.body);
    } catch {
      throw new ClientIdDocumentError('client metadata document is not valid JSON');
    }

    const client = validateClientIdDocument(clientId, url, doc);

    const ttl = cacheLifetimeMs(response.headers);
    if (ttl > 0) {
      if (this.cache.size >= MAX_CACHE_ENTRIES) {
        // Map iteration is insertion order: drop the oldest entry.
        const oldest = this.cache.keys().next().value;
        if (oldest !== undefined) this.cache.delete(oldest);
      }
      this.cache.set(clientId, { client, expiresAt: this.now() + ttl });
    }
    return client;
  }
}
