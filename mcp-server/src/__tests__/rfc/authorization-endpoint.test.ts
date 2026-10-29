// SPDX-License-Identifier: MIT

// The authorization endpoint and the login form behind it: RFC 6749 §3.1 and
// §4.1, PKCE (RFC 7636), the iss response parameter (RFC 9207), native-app
// redirects (RFC 8252), and the OAuth 2.1 profile (draft-ietf-oauth-v2-1-16).

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  pkce,
  form,
  hiddenFields,
  cookieHeader,
  authorizeAndLogin,
  REDIRECT_URI,
  NC_PASSWORD,
  type Stack,
} from './harness.js';

let s: Stack;
let clientId: string;
beforeAll(async () => {
  s = await startStack();
  clientId = (await register(s)).client_id;
});
afterAll(() => s.close());

function authorizeUrl(params: Record<string, string | undefined>): string {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined) q.append(k, v);
  return `${s.base}/authorize?${q}`;
}

function validParams(overrides: Record<string, string | undefined> = {}) {
  return {
    response_type: 'code',
    client_id: clientId,
    redirect_uri: REDIRECT_URI,
    code_challenge: pkce().challenge,
    code_challenge_method: 'S256',
    state: 'af0ifjsldkj',
    ...overrides,
  };
}

/** The error response the client received at its redirect URI. */
async function redirectError(params: Record<string, string | undefined>) {
  const res = await fetch(authorizeUrl(params), { redirect: 'manual' });
  expect(res.status).toBe(302);
  const location = new URL(res.headers.get('location')!);
  expect(`${location.origin}${location.pathname}`).toBe(REDIRECT_URI);
  return location.searchParams;
}

describe('RFC 6749 §3.1 — authorization endpoint', () => {
  it('MUST support GET', async () => {
    const res = await fetch(authorizeUrl(validParams()));
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^text\/html/);
  });

  it('MAY support POST — and does', async () => {
    const res = await fetch(`${s.base}/authorize`, form(validParams()));
    expect(res.status).toBe(200);
  });

  it('parameters sent without a value are treated as omitted', async () => {
    const res = await fetch(authorizeUrl(validParams({ client_id: '' })));
    expect(res.status).toBe(400);
  });

  it('request parameters MUST NOT be included more than once', async () => {
    const url = authorizeUrl(validParams()) + '&state=second';
    const res = await fetch(url, { redirect: 'manual' });
    // The duplicate is detected before the redirect URI is trusted.
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('answers methods other than GET and POST with 405', async () => {
    const res = await fetch(authorizeUrl(validParams()), { method: 'PUT' });
    expect(res.status).toBe(405);
    expect(res.headers.get('allow')).toBe('GET, POST');
  });
});

describe('RFC 6749 §4.1.2.1 — errors before the redirect URI is verified', () => {
  it('an unknown client_id is reported to the user agent, never redirected', async () => {
    const res = await fetch(authorizeUrl(validParams({ client_id: 'nobody' })), {
      redirect: 'manual',
    });
    expect(res.status).toBe(400);
    expect(res.headers.get('location')).toBeNull();
  });

  it('an unregistered redirect_uri is never redirected to', async () => {
    const res = await fetch(
      authorizeUrl(validParams({ redirect_uri: 'https://attacker.example/cb' })),
      { redirect: 'manual' }
    );
    expect(res.status).toBe(400);
    expect(res.headers.get('location')).toBeNull();
  });

  it('OAuth 2.1 §4.1.1: redirect_uri is compared by exact string match', async () => {
    for (const variant of [
      `${REDIRECT_URI}/`,
      `${REDIRECT_URI}?x=1`,
      REDIRECT_URI.toUpperCase(),
      REDIRECT_URI.replace('/callback', '/CALLBACK'),
    ]) {
      const res = await fetch(authorizeUrl(validParams({ redirect_uri: variant })), {
        redirect: 'manual',
      });
      expect(res.status, variant).toBe(400);
    }
  });
});

describe('RFC 6749 §4.1.2.1 — errors returned through the redirect URI', () => {
  it('an unsupported response_type is unsupported_response_type, with state', async () => {
    const q = await redirectError(validParams({ response_type: 'token' }));
    expect(q.get('error')).toBe('unsupported_response_type');
    expect(q.get('state')).toBe('af0ifjsldkj');
  });

  it('a missing response_type is an error too', async () => {
    const q = await redirectError(validParams({ response_type: undefined }));
    expect(q.get('error')).toBe('unsupported_response_type');
  });

  it('RFC 9207 §2: every error response carries iss equal to the metadata issuer', async () => {
    const q = await redirectError(validParams({ response_type: 'token' }));
    expect(q.get('iss')).toBe(s.issuer);
  });

  it('state is omitted from the error when the request had none', async () => {
    const q = await redirectError(validParams({ response_type: 'token', state: undefined }));
    expect(q.has('state')).toBe(false);
  });
});

describe('RFC 7636 — PKCE', () => {
  it('§4.4.1 / OAuth 2.1: a request without code_challenge is invalid_request', async () => {
    const q = await redirectError(validParams({ code_challenge: undefined }));
    expect(q.get('error')).toBe('invalid_request');
  });

  it('§4.3: the plain method is refused', async () => {
    const { verifier } = pkce();
    const q = await redirectError(
      validParams({ code_challenge: verifier, code_challenge_method: 'plain' })
    );
    expect(q.get('error')).toBe('invalid_request');
  });

  it('§4.3: an omitted method means plain, so it is refused', async () => {
    const q = await redirectError(validParams({ code_challenge_method: undefined }));
    expect(q.get('error')).toBe('invalid_request');
  });

  it('§4.2: an S256 challenge that is not 43 base64url characters is refused', async () => {
    for (const bad of ['short', 'a'.repeat(44), 'a'.repeat(42) + '=', 'a'.repeat(42) + '+']) {
      const q = await redirectError(validParams({ code_challenge: bad }));
      expect(q.get('error'), bad).toBe('invalid_request');
    }
  });
});

describe('RFC 8707 — resource indicators at the authorization endpoint', () => {
  it('§2: a resource this server does not serve is invalid_target', async () => {
    const q = await redirectError(validParams({ resource: 'https://other.example/mcp' }));
    expect(q.get('error')).toBe('invalid_target');
  });

  it('§2: a resource with a fragment is invalid_target', async () => {
    const q = await redirectError(validParams({ resource: `${s.resource}#frag` }));
    expect(q.get('error')).toBe('invalid_target');
  });

  it('§2: a relative resource is invalid_target', async () => {
    const q = await redirectError(validParams({ resource: '/mcp' }));
    expect(q.get('error')).toBe('invalid_target');
  });

  it('accepts the MCP endpoint itself as the resource', async () => {
    const res = await fetch(authorizeUrl(validParams({ resource: s.resource })));
    expect(res.status).toBe(200);
  });
});

describe('RFC 6749 §4.1.2 — authorization response', () => {
  it('returns code and the exact state, plus iss (RFC 9207 §2)', async () => {
    const res = await authorizeAndLogin(s, {
      clientId,
      challenge: pkce().challenge,
      state: 'a b&c=d',
    });
    expect(res.status).toBe(302);
    const location = new URL(res.headers.get('location')!);
    expect(`${location.origin}${location.pathname}`).toBe(REDIRECT_URI);
    expect(location.searchParams.get('code')).toMatch(/.{16,}/);
    expect(location.searchParams.get('state')).toBe('a b&c=d');
    expect(location.searchParams.get('iss')).toBe(s.issuer);
  });

  it('keeps the query component of a registered redirect URI (§3.1.2)', async () => {
    const withQuery = 'http://127.0.0.1:9999/cb?tenant=a';
    const { client_id } = await register(s, { redirect_uris: [withQuery] });
    const res = await authorizeAndLogin(s, {
      clientId: client_id,
      challenge: pkce().challenge,
      redirectUri: withQuery,
    });
    const location = new URL(res.headers.get('location')!);
    expect(location.searchParams.get('tenant')).toBe('a');
    expect(location.searchParams.get('code')).toBeTruthy();
  });

  it('wrong credentials issue no code', async () => {
    const res = await authorizeAndLogin(s, {
      clientId,
      challenge: pkce().challenge,
      password: 'wrong',
    });
    expect(res.status).toBe(200);
    expect(res.headers.get('location')).toBeNull();
  });
});

describe('RFC 8252 §7.3 — loopback redirect URIs', () => {
  it('MUST allow any port for a registered loopback redirect', async () => {
    const res = await authorizeAndLogin(s, {
      clientId,
      challenge: pkce().challenge,
      redirectUri: 'http://127.0.0.1:51004/callback',
    });
    expect(new URL(res.headers.get('location')!).port).toBe('51004');
  });

  it('the port wildcard does not extend to the path or host', async () => {
    for (const uri of ['http://127.0.0.1:51004/other', 'http://127.0.0.2:9999/callback']) {
      const res = await fetch(authorizeUrl(validParams({ redirect_uri: uri })), {
        redirect: 'manual',
      });
      expect(res.status, uri).toBe(400);
    }
  });
});

describe('the login form', () => {
  async function loginPage() {
    const res = await fetch(authorizeUrl(validParams()));
    return { res, html: await res.text() };
  }

  it('RFC 6749 §10.13: forbids framing (clickjacking)', async () => {
    const { res } = await loginPage();
    expect(res.headers.get('x-frame-options')).toBe('DENY');
    expect(res.headers.get('content-security-policy')).toContain("frame-ancestors 'none'");
  });

  it('is not cached and leaks no referrer', async () => {
    const { res } = await loginPage();
    expect(res.headers.get('cache-control')).toBe('no-store');
    expect(res.headers.get('referrer-policy')).toBe('no-referrer');
  });

  it('escapes request values it renders', async () => {
    const res = await fetch(authorizeUrl(validParams({ state: '"><script>alert(1)</script>' })));
    const html = await res.text();
    expect(html).not.toContain('<script>alert(1)</script>');
  });

  it('a POST whose hidden fields were changed is refused (no code issued)', async () => {
    const { res, html } = await loginPage();
    const fields = hiddenFields(html);
    for (const [k, v] of Object.entries({
      code_challenge: pkce().challenge,
      redirect_uri: 'http://127.0.0.1:9999/other',
      scope: 'mcp:tools mcp:admin',
      state: 'forged',
    })) {
      const login = await fetch(
        `${s.base}/auth/login`,
        form(
          { ...fields, [k]: v, username: 'alice', password: NC_PASSWORD },
          { cookie: cookieHeader(res) }
        )
      );
      expect(login.status, k).toBe(400);
      expect(login.headers.get('location'), k).toBeNull();
    }
  });

  it('a POST that never went through /authorize is refused', async () => {
    const login = await fetch(
      `${s.base}/auth/login`,
      form({
        client_id: clientId,
        redirect_uri: REDIRECT_URI,
        code_challenge: pkce().challenge,
        username: 'alice',
        password: NC_PASSWORD,
      })
    );
    expect(login.status).toBe(400);
    expect(login.headers.get('location')).toBeNull();
  });
});
