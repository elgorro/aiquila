// SPDX-License-Identifier: MIT

// The MCP endpoint as an OAuth protected resource (RFC 6750, RFC 9068,
// RFC 8725) and as a Streamable HTTP server (MCP 2026-07-28, with the
// 2025-11-25 handshake still served).

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { SignJWT } from 'jose';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  decodeJwt,
  MODERN,
  LEGACY,
  type Stack,
} from './harness.js';

let s: Stack;
let clientId: string;
let accessToken: string;
beforeAll(async () => {
  s = await startStack();
  clientId = (await register(s)).client_id;
  accessToken = (await obtainTokens(s, clientId)).access_token;
});
afterAll(() => s.close());

const PROTECTED = () => modernBody(1, 'tools/call', { name: 'run_setup_checks', arguments: {} });

async function status(token: string | undefined, headers: Record<string, string> = {}) {
  const res = await mcp(s, PROTECTED(), { token, headers });
  await res.body?.cancel();
  return res;
}

function sign(claims: Record<string, unknown>, header: Record<string, unknown> = {}) {
  const now = Math.floor(Date.now() / 1000);
  return new SignJWT({
    sub: 'alice',
    client_id: clientId,
    scope: '',
    gid: 'g',
    jti: crypto.randomUUID(),
    iss: s.base,
    aud: s.resource,
    iat: now,
    exp: now + 600,
    ...claims,
  })
    .setProtectedHeader({ alg: 'HS256', typ: 'at+jwt', ...header })
    .sign(new TextEncoder().encode(process.env.MCP_AUTH_SECRET));
}

describe('RFC 6750 — bearer token usage', () => {
  it('§2.1: accepts the token in the Authorization header', async () => {
    expect((await status(accessToken)).status).toBe(200);
  });

  it('§3: a request without a token gets 401 and a Bearer challenge', async () => {
    const res = await status(undefined);
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toMatch(/^Bearer /);
  });

  it('§3.1: an invalid token gets 401 with error="invalid_token"', async () => {
    const res = await status('not-a-token');
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toContain('error="invalid_token"');
  });

  it('§2.3 / OAuth 2.1 §5.2: a token in the query string is not accepted', async () => {
    const res = await fetch(`${s.base}/mcp?access_token=${accessToken}`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
        'mcp-protocol-version': MODERN,
        'mcp-method': 'tools/call',
        'mcp-name': 'run_setup_checks',
      },
      body: JSON.stringify(PROTECTED()),
    });
    await res.body?.cancel();
    expect(res.status).toBe(401);
  });

  it('a non-Bearer scheme is not accepted', async () => {
    const res = await status(undefined, { authorization: `Basic ${accessToken}` });
    expect(res.status).toBe(401);
  });

  it('challenges with the scope clients should request', async () => {
    const res = await status(undefined);
    expect(res.headers.get('www-authenticate')).toContain('scope="mcp:tools"');
  });
});

describe('RFC 9068 / RFC 8725 — JWT access tokens', () => {
  it('RFC 9068 §2.1: issued tokens are typed at+jwt', () => {
    expect(decodeJwt(accessToken).header.typ).toBe('at+jwt');
  });

  it('RFC 9068 §2.2: carries iss, exp, aud, sub, client_id, iat, jti', () => {
    const { payload } = decodeJwt(accessToken);
    for (const claim of ['iss', 'exp', 'aud', 'sub', 'client_id', 'iat', 'jti']) {
      expect(payload[claim], claim).toBeDefined();
    }
    expect(payload.client_id).toBe(clientId);
  });

  it('RFC 9068 §2.2.3: scope is a space-separated string', () => {
    expect(typeof decodeJwt(accessToken).payload.scope).toBe('string');
  });

  it('RFC 9068 §4: a JWT without typ at+jwt is rejected', async () => {
    const token = await sign({}, { typ: 'JWT' });
    expect((await status(token)).status).toBe(401);
  });

  it('RFC 8725 §3.1: alg none is rejected', async () => {
    const { payload } = decodeJwt(accessToken);
    const unsigned =
      Buffer.from(JSON.stringify({ alg: 'none', typ: 'at+jwt' })).toString('base64url') +
      '.' +
      Buffer.from(JSON.stringify(payload)).toString('base64url') +
      '.';
    expect((await status(unsigned)).status).toBe(401);
  });

  it('RFC 9068 §4: a token for another audience is rejected', async () => {
    expect((await status(await sign({ aud: 'https://other.example/mcp' }))).status).toBe(401);
  });

  it('RFC 9068 §4: a token from another issuer is rejected', async () => {
    expect((await status(await sign({ iss: 'https://other.example' }))).status).toBe(401);
  });

  it('RFC 9068 §4: an expired token is rejected', async () => {
    const past = Math.floor(Date.now() / 1000) - 3600;
    expect((await status(await sign({ iat: past - 60, exp: past }))).status).toBe(401);
  });

  it('a token signed with another key is rejected', async () => {
    const forged = await new SignJWT(decodeJwt(accessToken).payload)
      .setProtectedHeader({ alg: 'HS256', typ: 'at+jwt' })
      .sign(new TextEncoder().encode('x'.repeat(64)));
    expect((await status(forged)).status).toBe(401);
  });
});

describe('MCP Streamable HTTP (2026-07-28)', () => {
  it('server/discover lists 2026-07-28 without a token', async () => {
    const res = await mcp(s, modernBody(1, 'server/discover'));
    expect(res.status).toBe(200);
    const body = await rpcResult(res);
    expect(body.result.supportedVersions).toContain(MODERN);
  });

  it('answers a request as application/json or text/event-stream', async () => {
    const res = await mcp(s, modernBody(1, 'tools/list'));
    expect(res.headers.get('content-type')).toMatch(/^(application\/json|text\/event-stream)/);
    const body = await rpcResult(res);
    expect(Array.isArray(body.result.tools)).toBe(true);
  });

  it('a protected tool behind lazy auth answers 401, not a 200 tool error', async () => {
    expect((await status(undefined)).status).toBe(401);
  });

  it('an MCP-Protocol-Version header that disagrees with _meta is 400 HeaderMismatch', async () => {
    const res = await mcp(s, modernBody(1, 'tools/list'), {
      headers: { 'mcp-protocol-version': LEGACY },
    });
    expect(res.status).toBe(400);
    expect((await rpcResult(res)).error.code).toBe(-32020);
  });

  it('an Mcp-Method header that disagrees with the body is rejected', async () => {
    const res = await mcp(s, modernBody(1, 'tools/call', { name: 'run_setup_checks' }), {
      headers: { 'mcp-method': 'tools/list' },
    });
    await res.body?.cancel();
    expect(res.status).toBeGreaterThanOrEqual(400);
    expect(res.status).toBeLessThan(500);
  });

  it('an unsupported protocol version is 400', async () => {
    const body = modernBody(1, 'tools/list');
    body.params._meta['io.modelcontextprotocol/protocolVersion'] = '1999-01-01';
    const res = await mcp(s, body, { headers: { 'mcp-protocol-version': '1999-01-01' } });
    await res.body?.cancel();
    expect(res.status).toBe(400);
  });

  it('GET and DELETE on the endpoint are 405', async () => {
    for (const method of ['GET', 'DELETE']) {
      const res = await fetch(`${s.base}/mcp`, {
        method,
        headers: { authorization: `Bearer ${accessToken}`, accept: 'text/event-stream' },
      });
      await res.body?.cancel();
      expect(res.status, method).toBe(405);
    }
  });

  it('Security §1: an Origin that is not allowed gets 403', async () => {
    const res = await mcp(s, modernBody(1, 'tools/list'), {
      headers: { origin: 'https://evil.example' },
    });
    await res.body?.cancel();
    expect(res.status).toBe(403);
  });

  it('the issuer origin is an allowed Origin', async () => {
    const res = await mcp(s, modernBody(1, 'tools/list'), {
      headers: { origin: new URL(s.base).origin },
    });
    await res.body?.cancel();
    expect(res.status).toBe(200);
  });

  it('a Host header that is not allowed is refused (DNS rebinding)', async () => {
    const { request } = await import('node:http');
    const port = new URL(s.base).port;
    const code = await new Promise<number>((resolve, reject) => {
      const req = request(
        {
          host: '127.0.0.1',
          port,
          path: '/mcp',
          method: 'POST',
          headers: {
            host: `rebind.example:${port}`,
            'content-type': 'application/json',
            accept: 'application/json, text/event-stream',
            'mcp-protocol-version': MODERN,
            'mcp-method': 'tools/list',
          },
        },
        (res) => {
          res.resume();
          resolve(res.statusCode ?? 0);
        }
      );
      req.on('error', reject);
      req.end(JSON.stringify(modernBody(1, 'tools/list')));
    });
    expect(code).toBe(403);
  });
});

describe('MCP Streamable HTTP (2025-11-25 handshake)', () => {
  it('initialize negotiates a 2025-era version without a token', async () => {
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion: LEGACY,
          capabilities: {},
          clientInfo: { name: 'rfc-test', version: '1.0' },
        },
      }),
    });
    expect(res.status).toBe(200);
    expect((await rpcResult(res)).result.protocolVersion).toBe(LEGACY);
  });

  it('a notification is answered 202 with no body', async () => {
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
        'mcp-protocol-version': LEGACY,
      },
      body: JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }),
    });
    expect(res.status).toBe(202);
    expect(await res.text()).toBe('');
  });
});
