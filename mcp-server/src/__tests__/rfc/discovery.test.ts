// SPDX-License-Identifier: MIT

// Discovery documents: OAuth 2.0 Authorization Server Metadata (RFC 8414) and
// OAuth 2.0 Protected Resource Metadata (RFC 9728), as the MCP authorization
// specification (2026-07-28) profiles them.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { startStack, type Stack } from './harness.js';

let s: Stack;
beforeAll(async () => {
  s = await startStack();
});
afterAll(() => s.close());

async function getJson(path: string) {
  const res = await fetch(`${s.base}${path}`);
  return { res, body: (await res.json()) as Record<string, any> };
}

describe('RFC 8414 — authorization server metadata', () => {
  it('§3: is served at /.well-known/oauth-authorization-server as application/json', async () => {
    const { res } = await getJson('/.well-known/oauth-authorization-server');
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
  });

  it('§3.3: issuer is identical to the issuer the metadata was fetched for', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.issuer).toBe(s.issuer);
  });

  it('§2: issuer has no query or fragment component', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    const url = new URL(body.issuer);
    expect(url.search).toBe('');
    expect(url.hash).toBe('');
  });

  it('§2: carries every REQUIRED member', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.authorization_endpoint).toBe(new URL('/authorize', s.base).href);
    // REQUIRED unless only the implicit grant is supported
    expect(body.token_endpoint).toBe(new URL('/token', s.base).href);
    expect(body.response_types_supported).toEqual(['code']);
  });

  it('§2: endpoints share the issuer origin', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    for (const key of ['authorization_endpoint', 'token_endpoint', 'revocation_endpoint']) {
      expect(new URL(body[key]).origin).toBe(new URL(s.issuer).origin);
    }
  });

  it('OAuth 2.1: advertises no implicit or password grant', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.grant_types_supported).toEqual(['authorization_code', 'refresh_token']);
    expect(body.response_types_supported).not.toContain('token');
  });

  it('RFC 7636 §4.3 / OAuth 2.1: advertises S256 and never plain', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.code_challenge_methods_supported).toEqual(['S256']);
  });

  it('RFC 7591: advertises registration_endpoint when registration is on', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.registration_endpoint).toBe(new URL('/register', s.base).href);
  });

  it('RFC 7009 §4: advertises revocation_endpoint', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.revocation_endpoint).toBe(new URL('/revoke', s.base).href);
  });

  it('RFC 9207 §3: advertises authorization_response_iss_parameter_supported', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    expect(body.authorization_response_iss_parameter_supported).toBe(true);
  });

  it('only advertises client authentication methods the token endpoint implements', async () => {
    const { body } = await getJson('/.well-known/oauth-authorization-server');
    for (const m of body.token_endpoint_auth_methods_supported) {
      expect(['none', 'client_secret_post', 'client_secret_basic']).toContain(m);
    }
  });
});

describe('RFC 9728 — protected resource metadata', () => {
  it('§3.1: is served at the path-suffixed well-known URI of /mcp', async () => {
    const { res, body } = await getJson('/.well-known/oauth-protected-resource/mcp');
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    // §3.3: resource MUST be identical to the resource identifier
    expect(body.resource).toBe(s.resource);
  });

  it('§2: authorization_servers names this issuer', async () => {
    const { body } = await getJson('/.well-known/oauth-protected-resource/mcp');
    expect(body.authorization_servers.map((u: string) => new URL(u).href)).toContain(s.issuer);
  });

  it('the root well-known URI describes the same resource', async () => {
    const root = await getJson('/.well-known/oauth-protected-resource');
    const scoped = await getJson('/.well-known/oauth-protected-resource/mcp');
    expect(root.body.resource).toBe(scoped.body.resource);
  });

  it('§5.1: a 401 from /mcp points at the metadata with resource_metadata', async () => {
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
      },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'x' } }),
    });
    expect(res.status).toBe(401);
    const challenge = res.headers.get('www-authenticate') ?? '';
    expect(challenge).toMatch(/^Bearer /);
    expect(challenge).toContain(
      `resource_metadata="${new URL('/.well-known/oauth-protected-resource/mcp', s.base).href}"`
    );
  });
});
