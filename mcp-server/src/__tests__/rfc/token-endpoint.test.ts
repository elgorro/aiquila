// SPDX-License-Identifier: MIT

// The token endpoint: RFC 6749 §4.1.3, §5 and §6, PKCE verification
// (RFC 7636 §4.6), resource indicators (RFC 8707), refresh token rotation
// and reuse detection (RFC 9700 §4.14.2), and the OAuth 2.1 profile
// (draft-ietf-oauth-v2-1-16).

import { describe, it, expect, beforeAll, afterAll, vi } from 'vitest';
import {
  startStack,
  register,
  pkce,
  form,
  authorizeAndLogin,
  obtainTokens,
  mcp,
  modernBody,
  REDIRECT_URI,
  type Stack,
} from './harness.js';

let s: Stack;
let clientId: string;

// A stack per describe block: the login form allows 10 attempts per IP and
// 15 minutes, and every block here signs in more often than that in total.
function useStack() {
  beforeAll(async () => {
    s = await startStack();
    clientId = (await register(s)).client_id;
  });
  afterAll(() => s.close());
}

async function newCode(
  client = clientId,
  extra: { scope?: string } = {}
): Promise<{ code: string; verifier: string }> {
  const { verifier, challenge } = pkce();
  const login = await authorizeAndLogin(s, { clientId: client, challenge, ...extra });
  const code = new URL(login.headers.get('location')!).searchParams.get('code')!;
  return { code, verifier };
}

function token(body: Record<string, string | undefined>, headers = {}) {
  return fetch(`${s.base}/token`, form(body, headers));
}

/** True when the access token is accepted at the resource server. */
async function accessWorks(accessToken: string): Promise<boolean> {
  const res = await mcp(s, modernBody(1, 'tools/call', { name: 'run_setup_checks' }), {
    token: accessToken,
  });
  await res.body?.cancel();
  return res.status !== 401;
}

describe('RFC 6749 §5.1 — successful response', () => {
  useStack();

  it('is JSON with access_token, token_type, expires_in and refresh_token', async () => {
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    const body = await res.json();
    expect(typeof body.access_token).toBe('string');
    expect(body.token_type.toLowerCase()).toBe('bearer');
    expect(body.expires_in).toBeGreaterThan(0);
    expect(typeof body.refresh_token).toBe('string');
  });

  it('MUST carry Cache-Control: no-store and Pragma: no-cache', async () => {
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    });
    expect(res.headers.get('cache-control')).toBe('no-store');
    expect(res.headers.get('pragma')).toBe('no-cache');
  });

  it('§3.3: returns the granted scope', async () => {
    const { code, verifier } = await newCode(clientId, { scope: 'mcp:tools' });
    const body = await (
      await token({
        grant_type: 'authorization_code',
        code,
        code_verifier: verifier,
        client_id: clientId,
      })
    ).json();
    expect(body.scope).toBe('mcp:tools');
  });
});

describe('RFC 6749 §5.2 — error response', () => {
  useStack();

  it('is a 400 JSON body with an error code, and not cached', async () => {
    const res = await token({ grant_type: 'authorization_code', client_id: clientId });
    expect(res.status).toBe(400);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    expect(res.headers.get('cache-control')).toBe('no-store');
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('an unknown grant type is unsupported_grant_type', async () => {
    for (const grant_type of ['password', 'client_credentials', 'implicit', 'urn:x']) {
      const res = await token({ grant_type, client_id: clientId });
      expect((await res.json()).error, grant_type).toBe('unsupported_grant_type');
    }
  });

  it('§3.2: a duplicated parameter is invalid_request', async () => {
    const res = await fetch(`${s.base}/token`, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: `grant_type=authorization_code&code=a&code=b&client_id=${clientId}`,
    });
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('§3.2: only POST is allowed', async () => {
    const res = await fetch(`${s.base}/token?grant_type=authorization_code`);
    expect(res.status).toBe(405);
  });

  it('an unknown client is invalid_client', async () => {
    const res = await token({ grant_type: 'authorization_code', code: 'x', client_id: 'nobody' });
    expect((await res.json()).error).toBe('invalid_client');
  });
});

describe('RFC 6749 §4.1.3 — authorization code grant', () => {
  useStack();

  it('the code is bound to the client it was issued to', async () => {
    const other = (await register(s)).client_id;
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: other,
    });
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('an unknown code is invalid_grant', async () => {
    const { verifier } = pkce();
    const res = await token({
      grant_type: 'authorization_code',
      code: 'not-a-code',
      code_verifier: verifier,
      client_id: clientId,
    });
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('OAuth 2.1 §10.2: redirect_uri may be omitted', async () => {
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    });
    expect(res.status).toBe(200);
  });

  it('when sent, redirect_uri MUST match the authorization request', async () => {
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
      redirect_uri: 'http://127.0.0.1:9999/other',
    });
    expect((await res.json()).error).toBe('invalid_grant');
    const ok = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
      redirect_uri: REDIRECT_URI,
    });
    expect(ok.status).toBe(200);
  });

  it('§4.1.2: a code is valid for at most 10 minutes', async () => {
    const { code, verifier } = await newCode();
    vi.useFakeTimers({ toFake: ['Date'], now: Date.now() + 10 * 60 * 1000 + 1 });
    try {
      const res = await token({
        grant_type: 'authorization_code',
        code,
        code_verifier: verifier,
        client_id: clientId,
      });
      expect((await res.json()).error).toBe('invalid_grant');
    } finally {
      vi.useRealTimers();
    }
  });

  it('§4.1.2: a code used twice is denied', async () => {
    const { code, verifier } = await newCode();
    const body = {
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    };
    expect((await token(body)).status).toBe(200);
    const again = await token(body);
    expect((await again.json()).error).toBe('invalid_grant');
  });

  it('OAuth 2.1 §4.1.3: replaying a redeemed code revokes the tokens issued from it', async () => {
    const { code, verifier } = await newCode();
    const body = {
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    };
    const first = await (await token(body)).json();
    expect(await accessWorks(first.access_token)).toBe(true);

    await token(body);

    expect(await accessWorks(first.access_token)).toBe(false);
    const refresh = await token({
      grant_type: 'refresh_token',
      refresh_token: first.refresh_token,
      client_id: clientId,
    });
    expect((await refresh.json()).error).toBe('invalid_grant');
  });

  it('OAuth 2.1 §7.5.3: a replay with a wrong code_verifier revokes nothing', async () => {
    const { code, verifier } = await newCode();
    const first = await (
      await token({
        grant_type: 'authorization_code',
        code,
        code_verifier: verifier,
        client_id: clientId,
      })
    ).json();

    await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: pkce().verifier,
      client_id: clientId,
    });

    expect(await accessWorks(first.access_token)).toBe(true);
  });
});

describe('RFC 7636 §4.5–4.6 — code_verifier', () => {
  useStack();

  it('a missing code_verifier is invalid_request', async () => {
    const { code } = await newCode();
    const res = await token({ grant_type: 'authorization_code', code, client_id: clientId });
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('a verifier that does not hash to the challenge is invalid_grant', async () => {
    const { code } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: pkce().verifier,
      client_id: clientId,
    });
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('§4.1: a verifier outside 43–128 unreserved characters is invalid_request', async () => {
    const { code } = await newCode();
    for (const v of ['a'.repeat(42), 'a'.repeat(129), 'a'.repeat(42) + '+']) {
      const res = await token({
        grant_type: 'authorization_code',
        code,
        code_verifier: v,
        client_id: clientId,
      });
      expect((await res.json()).error, v).toBe('invalid_request');
    }
  });

  it('a failed verification does not consume the code (§4.6 MUST NOT issue tokens)', async () => {
    const { code, verifier } = await newCode();
    await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: pkce().verifier,
      client_id: clientId,
    });
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
    });
    expect(res.status).toBe(200);
  });
});

describe('RFC 8707 — resource indicators at the token endpoint', () => {
  useStack();

  it('§2.2: a resource this server does not serve is invalid_target', async () => {
    const { code, verifier } = await newCode();
    const res = await token({
      grant_type: 'authorization_code',
      code,
      code_verifier: verifier,
      client_id: clientId,
      resource: 'https://other.example/',
    });
    expect((await res.json()).error).toBe('invalid_target');
  });

  it('the access token is audience-restricted to the MCP endpoint', async () => {
    const tokens = await obtainTokens(s, clientId);
    const [, payload] = tokens.access_token.split('.');
    const claims = JSON.parse(Buffer.from(payload, 'base64url').toString());
    expect(claims.aud).toBe(s.resource);
    expect(claims.iss).toBe(s.base);
  });
});

describe('RFC 6749 §6 — refreshing an access token', () => {
  useStack();

  it('issues a new access token and rotates the refresh token (OAuth 2.1 §4.3.1)', async () => {
    const first = await obtainTokens(s, clientId);
    const res = await token({
      grant_type: 'refresh_token',
      refresh_token: first.refresh_token,
      client_id: clientId,
    });
    expect(res.status).toBe(200);
    expect(res.headers.get('cache-control')).toBe('no-store');
    const next = await res.json();
    expect(next.access_token).not.toBe(first.access_token);
    expect(next.refresh_token).not.toBe(first.refresh_token);
  });

  it('the refresh token is bound to its client', async () => {
    const first = await obtainTokens(s, clientId);
    const other = (await register(s)).client_id;
    const res = await token({
      grant_type: 'refresh_token',
      refresh_token: first.refresh_token,
      client_id: other,
    });
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('the requested scope MUST NOT exceed the original grant', async () => {
    const first = await obtainTokens(s, clientId, { scope: 'mcp:tools' });
    const res = await token({
      grant_type: 'refresh_token',
      refresh_token: first.refresh_token,
      client_id: clientId,
      scope: 'mcp:tools mcp:prompts',
    });
    expect((await res.json()).error).toBe('invalid_scope');
  });

  it('an omitted scope means the originally granted scope', async () => {
    const first = await obtainTokens(s, clientId, { scope: 'mcp:tools mcp:prompts' });
    const next = await (
      await token({
        grant_type: 'refresh_token',
        refresh_token: first.refresh_token,
        client_id: clientId,
      })
    ).json();
    expect(next.scope).toBe('mcp:tools mcp:prompts');
  });

  it('a narrower scope may be requested', async () => {
    const first = await obtainTokens(s, clientId, { scope: 'mcp:tools mcp:prompts' });
    const next = await (
      await token({
        grant_type: 'refresh_token',
        refresh_token: first.refresh_token,
        client_id: clientId,
        scope: 'mcp:tools',
      })
    ).json();
    expect(next.scope).toBe('mcp:tools');
  });

  it('RFC 9700 §4.14.2: a rotated token presented again ends the whole grant', async () => {
    const first = await obtainTokens(s, clientId);
    const second = await (
      await token({
        grant_type: 'refresh_token',
        refresh_token: first.refresh_token,
        client_id: clientId,
      })
    ).json();

    const replay = await token({
      grant_type: 'refresh_token',
      refresh_token: first.refresh_token,
      client_id: clientId,
    });
    expect((await replay.json()).error).toBe('invalid_grant');

    // The legitimate client's current tokens die with the grant.
    const current = await token({
      grant_type: 'refresh_token',
      refresh_token: second.refresh_token,
      client_id: clientId,
    });
    expect((await current.json()).error).toBe('invalid_grant');
    expect(await accessWorks(second.access_token)).toBe(false);
  });

  it('a user disabled in Nextcloud loses the grant at the next refresh', async () => {
    const first = await obtainTokens(s, clientId, { username: 'bob' });
    s.nc.users.get('bob')!.enabled = false;
    try {
      const res = await token({
        grant_type: 'refresh_token',
        refresh_token: first.refresh_token,
        client_id: clientId,
      });
      expect((await res.json()).error).toBe('invalid_grant');
    } finally {
      s.nc.users.get('bob')!.enabled = true;
    }
  });
});
