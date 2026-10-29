// SPDX-License-Identifier: MIT

// Dynamic client registration (RFC 7591) and token revocation (RFC 7009).

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  form,
  mcp,
  modernBody,
  REDIRECT_URI,
  type Stack,
} from './harness.js';

function post(s: Stack, path: string, body: unknown, headers: Record<string, string> = {}) {
  return fetch(`${s.base}${path}`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', ...headers },
    body: JSON.stringify(body),
  });
}

describe('RFC 7591 — dynamic client registration', () => {
  let s: Stack;
  beforeAll(async () => {
    s = await startStack();
  });
  afterAll(() => s.close());

  it('§3.2.1: answers 201 with client_id and the registered metadata', async () => {
    const res = await post(s, '/register', {
      client_name: 'Example',
      redirect_uris: [REDIRECT_URI],
      token_endpoint_auth_method: 'none',
    });
    expect(res.status).toBe(201);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    expect(res.headers.get('cache-control')).toBe('no-store');
    const body = await res.json();
    expect(typeof body.client_id).toBe('string');
    expect(body.client_id_issued_at).toBeLessThanOrEqual(Math.floor(Date.now() / 1000));
    expect(body.redirect_uris).toEqual([REDIRECT_URI]);
    expect(body.client_name).toBe('Example');
  });

  it('§3.2.1: a public client gets no client_secret', async () => {
    const body = await register(s);
    expect(body.client_secret).toBeUndefined();
  });

  it('§3.2.1: a confidential client gets a secret and client_secret_expires_at', async () => {
    const body = await register(s, { token_endpoint_auth_method: 'client_secret_post' });
    expect(typeof body.client_secret).toBe('string');
    expect((body.client_secret as string).length).toBeGreaterThanOrEqual(32);
    expect(body.client_secret_expires_at).toBeGreaterThan(Math.floor(Date.now() / 1000));
  });

  it('§3.2.2: a disallowed redirect URI is invalid_redirect_uri', async () => {
    for (const uri of [
      'https://attacker.example/cb',
      'http://example.com/cb',
      'javascript:alert(1)',
      `${REDIRECT_URI}#frag`,
    ]) {
      const res = await post(s, '/register', {
        redirect_uris: [uri],
        token_endpoint_auth_method: 'none',
      });
      expect(res.status, uri).toBe(400);
      expect((await res.json()).error, uri).toBe('invalid_redirect_uri');
    }
  });

  it('§3.2.2: malformed metadata is invalid_client_metadata', async () => {
    const res = await post(s, '/register', { redirect_uris: 'not-an-array' });
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_client_metadata');
  });

  it('a registered confidential client must authenticate at the token endpoint', async () => {
    const client = await register(s, { token_endpoint_auth_method: 'client_secret_post' });
    for (const secret of [undefined, 'wrong']) {
      const res = await fetch(
        `${s.base}/token`,
        form({
          grant_type: 'refresh_token',
          refresh_token: 'x',
          client_id: client.client_id,
          client_secret: secret,
        })
      );
      expect((await res.json()).error).toBe('invalid_client');
    }
  });
});

describe('RFC 7591 §3 — registration gated by an initial access token', () => {
  let s: Stack;
  beforeAll(async () => {
    s = await startStack({ MCP_REGISTRATION_TOKEN: 'gate-token' });
  });
  afterAll(() => s.close());

  it('refuses registration without the token', async () => {
    const res = await post(s, '/register', { redirect_uris: [REDIRECT_URI] });
    expect(res.status).toBe(401);
  });

  it('accepts registration with the token as a Bearer credential', async () => {
    const res = await post(
      s,
      '/register',
      { redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: 'none' },
      { authorization: 'Bearer gate-token' }
    );
    expect(res.status).toBe(201);
  });
});

describe('RFC 7009 — token revocation', () => {
  let s: Stack;
  let clientId: string;
  beforeAll(async () => {
    s = await startStack();
    clientId = (await register(s)).client_id;
  });
  afterAll(() => s.close());

  const revoke = (body: Record<string, string | undefined>) =>
    fetch(`${s.base}/revoke`, form(body));

  async function accessWorks(token: string) {
    const res = await mcp(s, modernBody(1, 'tools/call', { name: 'run_setup_checks' }), { token });
    await res.body?.cancel();
    return res.status !== 401;
  }

  const refreshWorks = async (refresh_token: string) =>
    (
      await fetch(
        `${s.base}/token`,
        form({ grant_type: 'refresh_token', refresh_token, client_id: clientId })
      )
    ).status === 200;

  it('§2.2: an unknown token is answered 200', async () => {
    const res = await revoke({ token: 'not-a-token', client_id: clientId });
    expect(res.status).toBe(200);
  });

  it('§2.1: token is REQUIRED', async () => {
    const res = await revoke({ client_id: clientId });
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('§2: only POST is allowed', async () => {
    const res = await fetch(`${s.base}/revoke?token=x`);
    expect(res.status).toBe(405);
  });

  it('§2.1: revoking a refresh token ends it and the access tokens of its grant', async () => {
    const t = await obtainTokens(s, clientId);
    expect((await revoke({ token: t.refresh_token, client_id: clientId })).status).toBe(200);
    expect(await refreshWorks(t.refresh_token)).toBe(false);
    expect(await accessWorks(t.access_token)).toBe(false);
  });

  it('§2.1: revoking an access token ends it, not the refresh token', async () => {
    const t = await obtainTokens(s, clientId);
    expect(await accessWorks(t.access_token)).toBe(true);
    await revoke({ token: t.access_token, client_id: clientId, token_type_hint: 'access_token' });
    expect(await accessWorks(t.access_token)).toBe(false);
    expect(await refreshWorks(t.refresh_token)).toBe(true);
  });

  it('§2.1: a wrong token_type_hint does not stop the revocation', async () => {
    const t = await obtainTokens(s, clientId);
    await revoke({ token: t.refresh_token, client_id: clientId, token_type_hint: 'access_token' });
    expect(await refreshWorks(t.refresh_token)).toBe(false);
  });

  it('§2.1: a client cannot revoke a token issued to another client', async () => {
    const t = await obtainTokens(s, clientId);
    const other = (await register(s)).client_id;
    expect((await revoke({ token: t.refresh_token, client_id: other })).status).toBe(200);
    expect((await revoke({ token: t.access_token, client_id: other })).status).toBe(200);
    expect(await accessWorks(t.access_token)).toBe(true);
    expect(await refreshWorks(t.refresh_token)).toBe(true);
  });
});
