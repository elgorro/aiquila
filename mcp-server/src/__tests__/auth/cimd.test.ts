// SPDX-License-Identifier: MIT

import { describe, it, expect, vi } from 'vitest';
import { Readable } from 'node:stream';
import {
  ClientIdDocumentError,
  ClientIdDocumentResolver,
  cacheLifetimeMs,
  isBlockedAddress,
  parseClientIdUrl,
  readCapped,
  type DocumentFetcher,
  type FetchedDocument,
  type HostResolver,
} from '../../auth/cimd.js';

const CLIENT_ID = 'https://app.example.com/oauth/client.json';

const DOC = {
  client_id: CLIENT_ID,
  client_name: 'Example MCP Client',
  redirect_uris: ['https://app.example.com/callback', 'http://127.0.0.1:3000/callback'],
  grant_types: ['authorization_code', 'refresh_token'],
  response_types: ['code'],
  token_endpoint_auth_method: 'none',
};

function response(body: unknown, overrides?: Partial<FetchedDocument>): FetchedDocument {
  return {
    status: 200,
    headers: { 'content-type': 'application/json' },
    body: typeof body === 'string' ? body : JSON.stringify(body),
    ...overrides,
  };
}

function setup(opts?: { addresses?: string[]; fetched?: FetchedDocument; now?: () => number }) {
  const resolveHost = vi.fn<HostResolver>(async () =>
    (opts?.addresses ?? ['93.184.215.14']).map((address) => ({
      address,
      family: address.includes(':') ? 6 : 4,
    }))
  );
  const fetchDocument = vi.fn<DocumentFetcher>(async () => opts?.fetched ?? response(DOC));
  const resolver = new ClientIdDocumentResolver({ resolveHost, fetchDocument, now: opts?.now });
  return { resolver, resolveHost, fetchDocument };
}

async function rejection(promise: Promise<unknown>): Promise<string> {
  const err = await promise.then(
    () => undefined,
    (e: unknown) => e
  );
  expect(err).toBeInstanceOf(ClientIdDocumentError);
  return (err as Error).message;
}

describe('parseClientIdUrl', () => {
  it('accepts an https URL with a path', () => {
    expect(parseClientIdUrl(CLIENT_ID).hostname).toBe('app.example.com');
  });

  it.each([
    ['http://app.example.com/client.json', /https/],
    ['https://user:pw@app.example.com/client.json', /userinfo/],
    ['https://app.example.com/client.json#frag', /fragment/],
    ['https://app.example.com/client.json#', /fragment/],
    ['https://app.example.com', /path/],
    ['https://app.example.com/', /path/],
    ['https://app.example.com/a/../client.json', /dot/],
    ['https://app.example.com/./client.json', /dot/],
    ['https://app.example.com/a/%2E%2E/client.json', /dot/],
    ['not a url', /valid URL/],
  ])('rejects %s', (url, reason) => {
    expect(() => parseClientIdUrl(url)).toThrow(reason);
  });
});

describe('isBlockedAddress', () => {
  it.each([
    '127.0.0.1',
    '10.1.2.3',
    '172.16.0.1',
    '192.168.1.1',
    '169.254.169.254',
    '100.64.0.1',
    '0.0.0.0',
    '224.0.0.1',
    '255.255.255.255',
    '::1',
    '::',
    '::ffff:127.0.0.1',
    '::ffff:169.254.169.254',
    '64:ff9b::a9fe:a9fe',
    'fc00::1',
    'fd12:3456::1',
    'fe80::1',
    'ff02::1',
    'not-an-ip',
  ])('blocks %s', (address) => {
    expect(isBlockedAddress(address)).toBe(true);
  });

  it.each(['93.184.215.14', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'])(
    'allows public %s',
    (address) => {
      expect(isBlockedAddress(address)).toBe(false);
    }
  );
});

describe('readCapped', () => {
  it('returns the body when it fits', async () => {
    expect(await readCapped(Readable.from([Buffer.from('{"a":'), Buffer.from('1}')]), 64)).toBe(
      '{"a":1}'
    );
  });

  it('throws once the stream passes the cap, whatever Content-Length said', async () => {
    const chunks = Array.from({ length: 10 }, () => Buffer.alloc(1024, 'x'));
    await expect(readCapped(Readable.from(chunks), 5 * 1024)).rejects.toThrow(/exceeds/);
  });
});

describe('cacheLifetimeMs', () => {
  it('uses max-age, capped at 24 hours', () => {
    expect(cacheLifetimeMs({ 'cache-control': 'public, max-age=60' })).toBe(60_000);
    expect(cacheLifetimeMs({ 'cache-control': 'max-age=999999999' })).toBe(24 * 60 * 60 * 1000);
  });

  it.each(['no-store', 'no-cache', 'private, max-age=600', 'max-age=0'])(
    'does not cache with "%s"',
    (cc) => {
      expect(cacheLifetimeMs({ 'cache-control': cc })).toBe(0);
    }
  );

  it('falls back to 15 minutes without freshness information', () => {
    expect(cacheLifetimeMs({})).toBe(15 * 60 * 1000);
  });
});

describe('ClientIdDocumentResolver', () => {
  it('resolves a valid document to a public client', async () => {
    const { resolver, fetchDocument } = setup();
    const client = await resolver.resolve(CLIENT_ID);
    expect(client).toMatchObject({
      client_id: CLIENT_ID,
      client_name: 'Example MCP Client',
      token_endpoint_auth_method: 'none',
    });
    expect(client.client_secret).toBeUndefined();
    expect(client.redirect_uris.map(String)).toEqual(DOC.redirect_uris);
    // Pinned to the address that passed the check.
    expect(fetchDocument).toHaveBeenCalledWith(expect.any(URL), {
      address: '93.184.215.14',
      family: 4,
    });
  });

  it('rejects a non-https client_id without resolving or fetching', async () => {
    const { resolver, resolveHost, fetchDocument } = setup();
    expect(await rejection(resolver.resolve('http://app.example.com/client.json'))).toMatch(
      /https/
    );
    expect(resolveHost).not.toHaveBeenCalled();
    expect(fetchDocument).not.toHaveBeenCalled();
  });

  it('refuses a host that resolves to a private address, with no outbound request', async () => {
    const { resolver, fetchDocument } = setup({ addresses: ['10.0.0.5'] });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/special-purpose/);
    expect(fetchDocument).not.toHaveBeenCalled();
  });

  it('refuses cloud metadata given as an IP literal', async () => {
    const { resolver, fetchDocument } = setup({ addresses: ['169.254.169.254'] });
    expect(await rejection(resolver.resolve('https://169.254.169.254/x'))).toMatch(
      /special-purpose/
    );
    expect(fetchDocument).not.toHaveBeenCalled();
  });

  it('refuses a host when any one of its answers is private', async () => {
    const { resolver, fetchDocument } = setup({ addresses: ['93.184.215.14', '127.0.0.1'] });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/127\.0\.0\.1/);
    expect(fetchDocument).not.toHaveBeenCalled();
  });

  it('reports a host that does not resolve', async () => {
    const resolver = new ClientIdDocumentResolver({
      resolveHost: async () => {
        throw new Error('ENOTFOUND');
      },
      fetchDocument: vi.fn(),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/could not resolve/);
  });

  it('does not follow a redirect', async () => {
    const { resolver } = setup({
      fetched: response('', { status: 302, headers: { location: 'http://169.254.169.254/' } }),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/HTTP 302/);
  });

  it('rejects an oversized body', async () => {
    const resolver = new ClientIdDocumentResolver({
      resolveHost: async () => [{ address: '93.184.215.14', family: 4 }],
      fetchDocument: async () => {
        throw new ClientIdDocumentError('client metadata document exceeds 5120 bytes');
      },
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/exceeds/);
  });

  it('wraps a network failure', async () => {
    const resolver = new ClientIdDocumentResolver({
      resolveHost: async () => [{ address: '93.184.215.14', family: 4 }],
      fetchDocument: async () => {
        throw new Error('The operation was aborted due to timeout');
      },
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/timeout/);
  });

  it('rejects a document not served as JSON', async () => {
    const { resolver } = setup({
      fetched: response(DOC, { headers: { 'content-type': 'text/html' } }),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/JSON/);
  });

  it('accepts an application/*+json content type', async () => {
    const { resolver } = setup({
      fetched: response(DOC, {
        headers: { 'content-type': 'application/oauth-client+json; charset=utf-8' },
      }),
    });
    await expect(resolver.resolve(CLIENT_ID)).resolves.toBeDefined();
  });

  it('rejects a body that is not valid JSON', async () => {
    const { resolver } = setup({ fetched: response('{not json') });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/valid JSON/);
  });

  it('rejects a document that is not self-referential', async () => {
    const { resolver } = setup({
      fetched: response({ ...DOC, client_id: 'https://other.example.com/client.json' }),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/does not match/);
  });

  it.each([
    ['client_name', { client_name: undefined }, /client_name/],
    ['redirect_uris', { redirect_uris: undefined }, /redirect_uris/],
    ['empty redirect_uris', { redirect_uris: [] }, /redirect_uris/],
  ])('rejects a document lacking %s', async (_name, patch, reason) => {
    const { resolver } = setup({ fetched: response({ ...DOC, ...patch }) });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(reason);
  });

  it('rejects a document carrying a client secret', async () => {
    const { resolver } = setup({ fetched: response({ ...DOC, client_secret: 's3cret' }) });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/secret/);
  });

  it.each(['client_secret_post', 'client_secret_basic', 'private_key_jwt'])(
    'rejects token_endpoint_auth_method %s',
    async (method) => {
      const { resolver } = setup({
        fetched: response({ ...DOC, token_endpoint_auth_method: method }),
      });
      expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/not supported/);
    }
  );

  it('rejects a cross-origin redirect_uri', async () => {
    const { resolver } = setup({
      fetched: response({ ...DOC, redirect_uris: ['https://evil.example.net/callback'] }),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/same-origin/);
  });

  it('rejects a private-use URI scheme redirect', async () => {
    const { resolver } = setup({
      fetched: response({ ...DOC, redirect_uris: ['com.example.app:/callback'] }),
    });
    expect(await rejection(resolver.resolve(CLIENT_ID))).toMatch(/same-origin/);
  });

  it('serves a repeat lookup from the cache', async () => {
    const { resolver, fetchDocument } = setup();
    await resolver.resolve(CLIENT_ID);
    await resolver.resolve(CLIENT_ID);
    expect(fetchDocument).toHaveBeenCalledTimes(1);
  });

  it('refetches once the cached document expires', async () => {
    let now = 1_000_000;
    const { resolver, fetchDocument } = setup({
      fetched: response(DOC, {
        headers: { 'content-type': 'application/json', 'cache-control': 'max-age=60' },
      }),
      now: () => now,
    });
    await resolver.resolve(CLIENT_ID);
    now += 61_000;
    await resolver.resolve(CLIENT_ID);
    expect(fetchDocument).toHaveBeenCalledTimes(2);
  });

  it('does not cache a document served with no-store', async () => {
    const { resolver, fetchDocument } = setup({
      fetched: response(DOC, {
        headers: { 'content-type': 'application/json', 'cache-control': 'no-store' },
      }),
    });
    await resolver.resolve(CLIENT_ID);
    await resolver.resolve(CLIENT_ID);
    expect(fetchDocument).toHaveBeenCalledTimes(2);
  });

  it('does not cache a failure — the next attempt fetches again', async () => {
    const { resolver, fetchDocument } = setup();
    fetchDocument.mockResolvedValueOnce(response('', { status: 503 }));
    await rejection(resolver.resolve(CLIENT_ID));
    await expect(resolver.resolve(CLIENT_ID)).resolves.toMatchObject({ client_id: CLIENT_ID });
    expect(fetchDocument).toHaveBeenCalledTimes(2);
  });
});
