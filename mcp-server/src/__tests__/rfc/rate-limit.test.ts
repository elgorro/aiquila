// SPDX-License-Identifier: MIT

// Rate limiting at the MCP endpoint. MCP 2026-07-28, Tools › Security
// Considerations: servers MUST "rate limit tool invocations". The refusal is
// RFC 6585 §4 (429 Too Many Requests) with RFC 9110 §10.2.3 Retry-After.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  LEGACY,
  type Stack,
} from './harness.js';
import { RATE_LIMIT_ERROR_CODE } from '../../transports/rate-limit.js';

const call = (id: number) =>
  modernBody(id, 'tools/call', { name: 'get_local_time', arguments: {} });

async function send(s: Stack, body: Parameters<typeof mcp>[1], token?: string) {
  const res = await mcp(s, body, { token });
  const text = await res.text();
  return { res, text };
}

describe('MCP 2026-07-28 Tools › Security: tool invocations are rate limited', () => {
  let s: Stack;
  let alice: string;
  let bob: string;
  beforeAll(async () => {
    s = await startStack({ MCP_RATE_LIMIT: '3', MCP_RATE_LIMIT_ANONYMOUS: '2' });
    const clientId = (await register(s)).client_id;
    alice = (await obtainTokens(s, clientId)).access_token;
    bob = (await obtainTokens(s, clientId, { username: 'bob' })).access_token;
  });
  afterAll(() => s.close());

  it('RFC 6585 §4: a tools/call over the limit is answered 429 with Retry-After', async () => {
    for (let i = 1; i <= 3; i++) {
      expect((await send(s, call(i), alice)).res.status).not.toBe(429);
    }
    const { res, text } = await send(s, call(4), alice);
    expect(res.status).toBe(429);
    // RFC 9110 §10.2.3: delay-seconds = 1*DIGIT; the window is one minute.
    const retryAfter = res.headers.get('retry-after') ?? '';
    expect(retryAfter).toMatch(/^\d+$/);
    expect(Number(retryAfter)).toBeGreaterThanOrEqual(1);
    expect(Number(retryAfter)).toBeLessThanOrEqual(60);
    // RFC 6585 §4: a 429 MUST NOT be stored by a cache.
    expect(res.headers.get('cache-control')).toBe('no-store');
    // §4: the representation SHOULD explain the condition. A JSON-RPC error
    // with no id, as Streamable HTTP allows for its other HTTP-level refusals.
    const body = JSON.parse(text);
    expect(body.jsonrpc).toBe('2.0');
    expect(body.id).toBeUndefined();
    expect(body.error.code).toBe(RATE_LIMIT_ERROR_CODE);
    expect(body.error.message).toMatch(/rate limit/i);
  });

  it('other users are unaffected: the key is the signed-in user', async () => {
    expect((await send(s, call(1), bob)).res.status).not.toBe(429);
  });

  it('methods other than tools/call do not count against the tool budget', async () => {
    expect((await send(s, modernBody(5, 'tools/list'), alice)).res.status).toBe(200);
  });

  it('each tools/call in a legacy JSON-RPC batch counts', async () => {
    // bob has used 1 of 3; a batch of 3 more exceeds the limit as a whole.
    const batch = [1, 2, 3].map((id) => ({
      jsonrpc: '2.0',
      id,
      method: 'tools/call',
      params: { name: 'get_local_time', arguments: {} },
    }));
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
        'mcp-protocol-version': LEGACY,
        authorization: `Bearer ${bob}`,
      },
      body: JSON.stringify(batch),
    });
    await res.body?.cancel();
    expect(res.status).toBe(429);
  });

  it('unauthenticated (lazy-auth) requests are limited per client address', async () => {
    for (let i = 1; i <= 2; i++) {
      expect((await send(s, modernBody(i, 'tools/list'))).res.status).toBe(200);
    }
    const { res } = await send(s, modernBody(3, 'tools/list'));
    expect(res.status).toBe(429);
    expect(res.headers.get('retry-after')).toMatch(/^\d+$/);
  });

  it('a signed-in user is not limited by the anonymous budget of the same address', async () => {
    expect((await send(s, modernBody(6, 'tools/list'), bob)).res.status).toBe(200);
  });
});

describe('rate limits can be disabled', () => {
  let s: Stack;
  let alice: string;
  beforeAll(async () => {
    s = await startStack({ MCP_RATE_LIMIT: '0', MCP_RATE_LIMIT_ANONYMOUS: '0' });
    alice = (await obtainTokens(s, (await register(s)).client_id)).access_token;
  });
  afterAll(() => s.close());

  it('MCP_RATE_LIMIT=0 and MCP_RATE_LIMIT_ANONYMOUS=0 turn the limits off', async () => {
    for (let i = 1; i <= 70; i++) {
      expect((await send(s, call(i), alice)).res.status).not.toBe(429);
      expect((await send(s, modernBody(i, 'tools/list'))).res.status).not.toBe(429);
    }
  });
});

describe('without OAuth, tool calls are limited per client address', () => {
  let s: Stack;
  beforeAll(async () => {
    s = await startStack({
      MCP_AUTH_ENABLED: 'false',
      MCP_ALLOW_UNAUTHENTICATED: 'true',
      MCP_RATE_LIMIT: '2',
    });
  });
  afterAll(() => s.close());

  it('the third tools/call within the window is answered 429', async () => {
    expect((await send(s, call(1))).res.status).not.toBe(429);
    expect((await send(s, call(2))).res.status).not.toBe(429);
    expect((await send(s, call(3))).res.status).toBe(429);
  });
});
