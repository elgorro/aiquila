// SPDX-License-Identifier: MIT

// RFC 4918 (WebDAV) behaviour of the Files client in src/client/webdav.ts,
// run against a recorded fetch so every method, header and body is visible.

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { getWebDAVClient, parseMultistatus, WebDAVError } from '../../client/webdav.js';

interface Call {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: unknown;
}

const calls: Call[] = [];
let replies: Response[] = [];

const ROOT = 'https://cloud.example.com/remote.php/dav/files/ad%20min';

function multistatus(body: string, prefix = 'd'): Response {
  const ns = prefix ? `xmlns:${prefix}="DAV:"` : 'xmlns="DAV:"';
  return new Response(
    `<?xml version="1.0"?><${prefix ? `${prefix}:` : ''}multistatus ${ns} xmlns:oc="http://owncloud.org/ns">${body}</${prefix ? `${prefix}:` : ''}multistatus>`,
    {
      status: 207,
      statusText: 'Multi-Status',
    }
  );
}

function status(code: number, text: string): Response {
  return new Response(code === 204 ? null : '', { status: code, statusText: text });
}

beforeEach(() => {
  calls.length = 0;
  replies = [];
  process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
  process.env.NEXTCLOUD_USER = 'ad min';
  process.env.NEXTCLOUD_PASSWORD = 'testpass';
  global.fetch = vi.fn(async (url: string, init: RequestInit = {}) => {
    calls.push({
      url,
      method: init.method ?? 'GET',
      headers: (init.headers ?? {}) as Record<string, string>,
      body: init.body,
    });
    const reply = replies.shift();
    if (!reply) throw new Error(`unexpected request ${init.method} ${url}`);
    return reply;
  }) as unknown as typeof fetch;
});

const FILE = `
  <d:response>
    <d:href>/remote.php/dav/files/ad%20min/Docs/a%20%26%20b.txt</d:href>
    <d:propstat>
      <d:prop>
        <d:getlastmodified>Tue, 10 Nov 2026 08:00:00 GMT</d:getlastmodified>
        <d:getcontentlength>12</d:getcontentlength>
        <d:resourcetype/>
        <d:getcontenttype>text/plain; charset=utf-8</d:getcontenttype>
        <d:getetag>&quot;abc123&quot;</d:getetag>
        <oc:fileid>42</oc:fileid>
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>`;

const SELF = `
  <d:response>
    <d:href>/remote.php/dav/files/ad%20min/Docs/</d:href>
    <d:propstat>
      <d:prop><d:resourcetype><d:collection/></d:resourcetype><d:getetag>"dir"</d:getetag></d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
    <d:propstat>
      <d:prop><d:getcontentlength/><d:getcontenttype/></d:prop>
      <d:status>HTTP/1.1 404 Not Found</d:status>
    </d:propstat>
  </d:response>`;

describe('RFC 4918 §9.1 — PROPFIND', () => {
  it('§10.2: lists a folder with Depth 1 and leaves the folder itself out', async () => {
    replies.push(multistatus(SELF + FILE));
    const items = await getWebDAVClient().getDirectoryContents('/Docs');

    expect(calls[0].method).toBe('PROPFIND');
    expect(calls[0].headers.Depth).toBe('1');
    expect(calls[0].url).toBe(`${ROOT}/Docs/`);
    expect(String(calls[0].body)).toMatch(
      /<d:propfind [^>]*xmlns:d="DAV:"[^>]*><d:prop>.*<d:getetag\/>.*<\/d:prop><\/d:propfind>/
    );
    expect(items).toEqual([
      {
        filename: '/Docs/a & b.txt',
        basename: 'a & b.txt',
        lastmod: 'Tue, 10 Nov 2026 08:00:00 GMT',
        size: 12,
        type: 'file',
        etag: 'abc123',
        mime: 'text/plain',
      },
    ]);
  });

  it('§10.2: stats a resource with Depth 0 and returns the extra properties asked for', async () => {
    replies.push(multistatus(FILE));
    const stat = await getWebDAVClient().stat('/Docs/a & b.txt', { props: ['oc:fileid'] });

    expect(calls[0].headers.Depth).toBe('0');
    expect(calls[0].url).toBe(`${ROOT}/Docs/a%20%26%20b.txt`);
    expect(String(calls[0].body)).toContain('<oc:fileid/>');
    expect(stat.props).toEqual({ fileid: '42' });
    expect(stat.mime).toBe('text/plain');
  });

  it('rejects a property outside the known namespaces rather than sending it', async () => {
    await expect(getWebDAVClient().stat('/a', { props: ['x:evil'] })).rejects.toThrow(
      /Unsupported WebDAV property/
    );
    expect(calls).toHaveLength(0);
  });

  it('§9.1: a success status other than 207 Multi-Status is an error', async () => {
    replies.push(new Response('<html/>', { status: 200, statusText: 'OK' }));
    await expect(getWebDAVClient().stat('/a')).rejects.toMatchObject({ status: 200 });
  });

  it('exists() is false on 404 and true on a multistatus', async () => {
    replies.push(status(404, 'Not Found'), multistatus(FILE));
    const client = getWebDAVClient();
    expect(await client.exists('/nope')).toBe(false);
    expect(await client.exists('/Docs/a & b.txt')).toBe(true);
  });
});

describe('RFC 4918 §13 / §14.16 — multistatus parsing', () => {
  const base = '/remote.php/dav/files/ad min';

  it('§14.22: only properties from a 200 propstat count', () => {
    const [dir] = parseMultistatus(`<d:multistatus xmlns:d="DAV:">${SELF}</d:multistatus>`, base);
    expect(dir).toMatchObject({ filename: '/Docs', type: 'directory', size: 0, etag: 'dir' });
    expect(dir.mime).toBeUndefined();
  });

  it('accepts any namespace prefix, or none, for DAV: elements', () => {
    const body = FILE.replace(/d:/g, 'D:');
    const [a] = parseMultistatus(`<D:multistatus xmlns:D="DAV:">${body}</D:multistatus>`, base);
    const [b] = parseMultistatus(
      `<multistatus xmlns="DAV:">${FILE.replace(/d:/g, '')}</multistatus>`,
      base
    );
    expect(a.filename).toBe('/Docs/a & b.txt');
    expect(b).toEqual(a);
  });

  it('§8.3: an href may be an absolute URI', () => {
    const body = FILE.replace(
      '<d:href>/remote.php',
      '<d:href>https://cloud.example.com/remote.php'
    );
    const [stat] = parseMultistatus(`<d:multistatus xmlns:d="DAV:">${body}</d:multistatus>`, base);
    expect(stat.filename).toBe('/Docs/a & b.txt');
  });

  it('skips an href outside the account root', () => {
    const body = FILE.replace('/files/ad%20min/', '/files/someone/');
    expect(parseMultistatus(`<d:multistatus xmlns:d="DAV:">${body}</d:multistatus>`, base)).toEqual(
      []
    );
  });

  it('handles an instance served under a path prefix', () => {
    const body = FILE.replace('<d:href>/remote.php', '<d:href>/nextcloud/remote.php');
    const [stat] = parseMultistatus(
      `<d:multistatus xmlns:d="DAV:">${body}</d:multistatus>`,
      `/nextcloud${base}`
    );
    expect(stat.filename).toBe('/Docs/a & b.txt');
  });
});

describe('RFC 4918 §9.8 / §9.9 — COPY and MOVE', () => {
  it('§10.3: Destination is the absolute, encoded URI; §10.6: Overwrite defaults to T', async () => {
    replies.push(status(201, 'Created'));
    await getWebDAVClient().moveFile('/a b.txt', '/Archive/#1.txt');
    expect(calls[0]).toMatchObject({
      method: 'MOVE',
      url: `${ROOT}/a%20b.txt`,
      headers: { Destination: `${ROOT}/Archive/%231.txt`, Overwrite: 'T' },
    });
  });

  it('§10.6: overwrite: false sends F, and the 412 the server answers is an error', async () => {
    replies.push(status(412, 'Precondition Failed'));
    const err = await getWebDAVClient()
      .copyFile('/a.txt', '/b.txt', { overwrite: false })
      .catch((e: unknown) => e);
    expect(calls[0].headers.Overwrite).toBe('F');
    expect(err).toBeInstanceOf(WebDAVError);
    expect(err).toMatchObject({
      status: 412,
      message: 'Invalid response: 412 Precondition Failed',
    });
  });
});

describe('RFC 4918 §9.3 — MKCOL', () => {
  it('creates one collection, and a 405 (already exists) is an error', async () => {
    replies.push(status(405, 'Method Not Allowed'));
    await expect(getWebDAVClient().createDirectory('/Docs')).rejects.toMatchObject({ status: 405 });
    expect(calls[0]).toMatchObject({ method: 'MKCOL', url: `${ROOT}/Docs/` });
  });

  it('§9.3.1: recursive creates every level, since a missing parent is a 409', async () => {
    // /Recipes exists already (405), /Recipes/Soup is new (201).
    replies.push(status(405, 'Method Not Allowed'), status(201, 'Created'));
    await getWebDAVClient().createDirectory('/Recipes/Soup/', { recursive: true });
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      `MKCOL ${ROOT}/Recipes/`,
      `MKCOL ${ROOT}/Recipes/Soup/`,
    ]);
  });

  it('§9.3.1: a 409 is not mistaken for "already exists"', async () => {
    replies.push(status(409, 'Conflict'));
    await expect(
      getWebDAVClient().createDirectory('/a/b', { recursive: false })
    ).rejects.toMatchObject({ status: 409 });
  });
});

describe('GET, PUT, DELETE', () => {
  it('reads text and bytes', async () => {
    replies.push(new Response('héllo'), new Response(new Uint8Array([0, 255])));
    const client = getWebDAVClient();
    expect(await client.getFileContents('/a.txt', { format: 'text' })).toBe('héllo');
    expect(await client.getFileContents('/b.bin', { format: 'binary' })).toEqual(
      new Uint8Array([0, 255])
    );
  });

  it('RFC 9110 §13.1.2: overwrite: false sends If-None-Match: *', async () => {
    replies.push(status(201, 'Created'), status(204, 'No Content'));
    const client = getWebDAVClient();
    await client.putFileContents('/new.txt', 'x', { overwrite: false });
    await client.putFileContents('/new.txt', 'y', { overwrite: true });
    expect(calls[0].headers['If-None-Match']).toBe('*');
    expect(calls[1].headers['If-None-Match']).toBeUndefined();
    expect(calls[1]).toMatchObject({ method: 'PUT', body: 'y' });
  });

  it.each([
    [403, 'Forbidden'],
    [404, 'Not Found'],
    [409, 'Conflict'],
    [507, 'Insufficient Storage'],
  ])('maps %i to an error tools report as before', async (code, text) => {
    replies.push(status(code, text));
    await expect(getWebDAVClient().deleteFile('/a.txt')).rejects.toMatchObject({
      status: code,
      message: `Invalid response: ${code} ${text}`,
    });
  });

  it('RFC 3986 §5.2.4: a dot-segment never leaves the account root', async () => {
    await expect(getWebDAVClient().deleteFile('/Docs/../../other')).rejects.toThrow(/Invalid name/);
    expect(calls).toHaveLength(0);
  });
});
