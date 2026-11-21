// SPDX-License-Identifier: MIT

// Resources over the real app. MCP 2026-07-28, Tools › Tool Result › Embedded
// Resources: "Servers that use embedded resources SHOULD implement the
// `resources` capability". Resources › Capabilities, Protocol Messages, Data
// Types and Error Handling give what that capability has to answer.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  type Stack,
} from './harness.js';

const PDF = Buffer.concat([Buffer.from('%PDF-1.7\n'), Buffer.from([0, 1, 2, 250, 255])]);

let s: Stack;
let token: string;
beforeAll(async () => {
  s = await startStack({ MCP_TOOLS: 'files' });
  token = (await obtainTokens(s, (await register(s)).client_id)).access_token;
  s.nc.files.set('alice/Notes/à faire.md', {
    body: Buffer.from('# À faire'),
    mime: 'text/markdown',
  });
  s.nc.files.set('alice/Report 1.pdf', { body: PDF, mime: 'application/pdf' });
  s.nc.files.set('bob/secret.txt', { body: Buffer.from('bob only'), mime: 'text/plain' });
});
afterAll(() => s.close());

// The 2026-07-28 Streamable HTTP binding mirrors the URI of a resources/read in `Mcp-Name`.
const read = async (id: number, uri: string) =>
  rpcResult(
    await mcp(s, modernBody(id, 'resources/read', { uri }), { token, headers: { 'mcp-name': uri } })
  );

describe('MCP 2026-07-28 Resources', () => {
  it('Capabilities: servers that support resources MUST declare the resources capability', async () => {
    const body = await rpcResult(await mcp(s, modernBody(1, 'server/discover'), { token }));
    // Neither optional feature is offered: the templates never change, nothing is watched.
    expect(body.result.capabilities.resources).toEqual({ listChanged: false });
  });

  it('Capabilities: resources/list MUST answer; the set MAY be empty', async () => {
    const body = await rpcResult(await mcp(s, modernBody(2, 'resources/list'), { token }));
    expect(body.result.resources).toEqual([]);
  });

  it('Resource Templates: resources/templates/list describes files as an RFC 6570 template', async () => {
    const body = await rpcResult(
      await mcp(s, modernBody(3, 'resources/templates/list'), { token })
    );
    const templates: { name: string; uriTemplate: string }[] = body.result.resourceTemplates;
    expect(templates.map((t) => t.uriTemplate)).toEqual(['nextcloud:///{+path}']);
  });

  it('Text Content: a text file is read as `text` with its media type', async () => {
    const uri = 'nextcloud:///Notes/%C3%A0%20faire.md';
    const body = await read(4, uri);
    expect(body.result.contents).toEqual([{ uri, mimeType: 'text/markdown', text: '# À faire' }]);
  });

  it('Binary Content: any other file is read as `blob`, the bytes in base64', async () => {
    const uri = 'nextcloud:///Report%201.pdf';
    const body = await read(5, uri);
    expect(body.result.contents).toEqual([
      { uri, mimeType: 'application/pdf', blob: PDF.toString('base64') },
    ]);
  });

  it('Embedded Resources: the URI a tool embeds a file under reads the same file back', async () => {
    const call = modernBody(6, 'tools/call', {
      name: 'read_file',
      arguments: { path: '/Report 1.pdf' },
    });
    const { result } = await rpcResult(await mcp(s, call, { token }));
    const embedded = result.content.find((c: { type: string }) => c.type === 'resource').resource;
    expect(embedded.blob).toBe(PDF.toString('base64'));
    const body = await read(7, embedded.uri);
    expect(body.result.contents).toEqual([embedded]);
  });

  it('Error Handling: a resource that does not exist MUST be a -32602 error, not empty contents', async () => {
    const uri = 'nextcloud:///absent.pdf';
    const body = await read(8, uri);
    expect(body.result).toBeUndefined();
    expect(body.error.code).toBe(-32602);
    expect(body.error.data.uri).toBe(uri);
  });

  it('Error Handling: a URI in no served scheme is not found', async () => {
    const body = await read(9, 'file:///etc/passwd');
    expect(body.error.code).toBe(-32602);
  });

  it('Security: URIs MUST be validated; a path cannot leave the account', async () => {
    // RFC 3986 §5.2.4: the dot-segments of the first URI resolve inside the
    // account, where no such file is. The second hides them and a slash in
    // one segment, and is refused before any request is made.
    for (const uri of [
      'nextcloud:///../bob/secret.txt',
      'nextcloud:///%2E%2E%2Fbob%2Fsecret.txt',
    ]) {
      const body = await read(10, uri);
      expect(body.result).toBeUndefined();
      expect(body.error.code).toBe(-32602);
    }
    const dav = s.nc.requests.filter((r) => r.url.startsWith('/remote.php/dav/files/'));
    expect(dav.length).toBeGreaterThan(0);
    expect(dav.every((r) => r.url.startsWith('/remote.php/dav/files/alice/'))).toBe(true);
  });

  it('Security: access is controlled; resources/read needs a token, the listings do not', async () => {
    const uri = 'nextcloud:///Report%201.pdf';
    const res = await mcp(s, modernBody(11, 'resources/read', { uri }), {
      headers: { 'mcp-name': uri },
    });
    expect(res.status).toBe(401);
    const list = await mcp(s, modernBody(12, 'resources/templates/list'));
    expect(list.status).toBe(200);
  });
});

describe('a server whose tools embed no file', () => {
  let bare: Stack;
  beforeAll(async () => {
    bare = await startStack({ MCP_TOOLS: 'status' });
  });
  afterAll(() => bare.close());

  it('does not declare the resources capability', async () => {
    const body = await rpcResult(await mcp(bare, modernBody(1, 'server/discover')));
    expect(body.result.capabilities.resources).toBeUndefined();
  });
});
