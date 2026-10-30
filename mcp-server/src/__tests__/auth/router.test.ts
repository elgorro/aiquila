// SPDX-License-Identifier: MIT

// The authorization server routes, exercised over real HTTP.

import { describe, it, expect, beforeAll, afterAll, beforeEach, vi } from 'vitest';
import { createHash } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import type { AddressInfo } from 'node:net';
import type { Server } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import express from 'express';

// The routes are under test here, not Nextcloud: Login Flow v2 always starts,
// and every app password checks out as alice's.
vi.mock('../../auth/login-flow.js', () => ({
  startLoginFlow: vi.fn(async () => ({
    loginUrl: 'https://cloud.example.com/login/v2/flow/t',
    pollToken: 't',
    pollPath: '/login/v2/poll',
  })),
  pollLoginFlow: vi.fn(async () => null),
  checkCredential: vi.fn(async () => ({ state: 'valid', userId: 'alice' })),
  deleteAppPassword: vi.fn(async () => true),
}));

import { authRouter, type AuthRouterOptions } from '../../auth/router.js';
import { NextcloudOAuthProvider } from '../../auth/provider.js';
import { ClientsStore } from '../../auth/store.js';
import { ClientIdDocumentResolver } from '../../auth/cimd.js';
import { RedirectUriPolicy } from '../../auth/redirect-uri.js';

const ISSUER = 'https://mcp.example.com/';
const REDIRECT = 'https://client.example.com/callback';
const PUBLIC_CLIENT = 'public-client';
const CIMD_CLIENT = 'https://app.example.org/oauth/client.json';
const VERIFIER = 'a'.repeat(43) + '-._~0123456789';
const CHALLENGE = createHash('sha256').update(VERIFIER).digest('base64url');

let stateDir: string;
const savedEnv = process.env;

beforeAll(() => {
  stateDir = mkdtempSync(join(tmpdir(), 'aiquila-router-'));
  process.env = {
    ...savedEnv,
    MCP_AUTH_SECRET: 'test-secret-min-32-chars-required!!',
    MCP_CREDENTIAL_KEY: '0'.repeat(128),
    MCP_AUTH_ISSUER: ISSUER,
    MCP_AUTH_STATE_DIR: stateDir,
  };
});

afterAll(() => {
  process.env = savedEnv;
  rmSync(stateDir, { recursive: true, force: true });
});

const fetchDocument = vi.fn(async () => ({
  status: 200,
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({
    client_id: CIMD_CLIENT,
    client_name: 'Totally Legit Client',
    redirect_uris: ['https://app.example.org/callback'],
    token_endpoint_auth_method: 'none',
  }),
}));

function makeStore(opts?: { registration?: boolean; cimd?: boolean; policy?: RedirectUriPolicy }) {
  return new ClientsStore({
    preseededClients: [
      {
        client_id: PUBLIC_CLIENT,
        redirect_uris: [REDIRECT, 'http://127.0.0.1:8080/cb'],
        token_endpoint_auth_method: 'none',
        client_name: 'Public',
      },
      {
        client_id: 'single-uri-client',
        redirect_uris: [REDIRECT],
        token_endpoint_auth_method: 'none',
      },
      {
        client_id: 'confidential',
        client_secret: 'shh',
        redirect_uris: [REDIRECT],
      },
    ],
    enableDynamicRegistration: opts?.registration,
    redirectPolicy: opts?.policy,
    clientIdDocuments:
      opts?.cimd === false
        ? undefined
        : new ClientIdDocumentResolver({
            resolveHost: async () => [{ address: '93.184.215.14', family: 4 }],
            fetchDocument,
          }),
  });
}

async function serve(
  opts?: {
    registration?: boolean;
    cimd?: boolean;
    policy?: RedirectUriPolicy;
  } & Partial<AuthRouterOptions>
) {
  const provider = new NextcloudOAuthProvider(makeStore(opts));
  const app = express();
  app.use(
    authRouter({
      provider,
      issuerUrl: new URL(ISSUER),
      resourceServerUrl: new URL('/mcp', ISSUER),
      scopesSupported: ['mcp:tools'],
      ...opts,
    })
  );
  const server: Server = await new Promise((resolve) => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
  return { provider, base, close: () => new Promise((r) => server.close(r)) };
}

type Ctx = Awaited<ReturnType<typeof serve>>;

function form(body: Record<string, string>): RequestInit {
  return {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(body).toString(),
  };
}

function issueCode(ctx: Ctx, overrides?: Record<string, unknown>) {
  return ctx.provider.issueAuthCode({
    pkceChallenge: CHALLENGE,
    clientId: PUBLIC_CLIENT,
    scopes: ['mcp:tools'],
    redirectUri: REDIRECT,
    userId: 'alice',
    credential: { loginName: 'alice', appPassword: 'app-password' },
    ...overrides,
  });
}

async function tokens(ctx: Ctx) {
  const res = await fetch(
    `${ctx.base}/token`,
    form({
      grant_type: 'authorization_code',
      client_id: PUBLIC_CLIENT,
      code: issueCode(ctx),
      code_verifier: VERIFIER,
      redirect_uri: REDIRECT,
    })
  );
  expect(res.status).toBe(200);
  return (await res.json()) as { access_token: string; refresh_token: string; scope: string };
}

describe('authorization server metadata', () => {
  it('advertises CIMD alongside the "none" auth method (RFC 8414 §2)', async () => {
    const ctx = await serve({ registration: true });
    try {
      const res = await fetch(`${ctx.base}/.well-known/oauth-authorization-server`);
      const meta = await res.json();
      expect(meta).toEqual({
        issuer: ISSUER,
        authorization_endpoint: 'https://mcp.example.com/authorize',
        token_endpoint: 'https://mcp.example.com/token',
        registration_endpoint: 'https://mcp.example.com/register',
        revocation_endpoint: 'https://mcp.example.com/revoke',
        response_types_supported: ['code'],
        code_challenge_methods_supported: ['S256'],
        token_endpoint_auth_methods_supported: ['client_secret_post', 'none'],
        revocation_endpoint_auth_methods_supported: ['client_secret_post', 'none'],
        grant_types_supported: ['authorization_code', 'refresh_token'],
        scopes_supported: ['mcp:tools'],
        authorization_response_iss_parameter_supported: true,
        client_id_metadata_document_supported: true,
      });
    } finally {
      await ctx.close();
    }
  });

  it('omits CIMD and registration when both are off', async () => {
    const ctx = await serve({ cimd: false });
    try {
      const meta = await (await fetch(`${ctx.base}/.well-known/oauth-authorization-server`)).json();
      expect(meta.client_id_metadata_document_supported).toBeUndefined();
      expect(meta.registration_endpoint).toBeUndefined();
      expect((await fetch(`${ctx.base}/register`, { method: 'POST' })).status).toBe(404);
    } finally {
      await ctx.close();
    }
  });

  it('serves the protected resource metadata (RFC 9728)', async () => {
    const ctx = await serve();
    try {
      const prm = await (
        await fetch(`${ctx.base}/.well-known/oauth-protected-resource/mcp`)
      ).json();
      expect(prm).toMatchObject({
        resource: 'https://mcp.example.com/mcp',
        authorization_servers: [ISSUER],
      });
    } finally {
      await ctx.close();
    }
  });
});

describe('/authorize', () => {
  let ctx: Ctx;
  beforeAll(async () => {
    ctx = await serve();
  });
  afterAll(() => ctx.close());
  beforeEach(() => fetchDocument.mockClear());

  const authorize = (q: Record<string, string>) =>
    fetch(`${ctx.base}/authorize?${new URLSearchParams(q)}`, { redirect: 'manual' });

  const valid = {
    response_type: 'code',
    client_id: PUBLIC_CLIENT,
    redirect_uri: REDIRECT,
    code_challenge: CHALLENGE,
    code_challenge_method: 'S256',
    state: 'xyz',
  };

  it('renders the Nextcloud sign-in page for a valid request', async () => {
    const res = await authorize(valid);
    expect(res.status).toBe(200);
    expect(res.headers.get('cache-control')).toBe('no-store');
    expect(await res.text()).toContain('href="https://cloud.example.com/login/v2/flow/t"');
  });

  it('accepts a loopback redirect on another port (RFC 8252 §7.3)', async () => {
    const res = await authorize({ ...valid, redirect_uri: 'http://127.0.0.1:51234/cb' });
    expect(res.status).toBe(200);
  });

  it('answers an unknown client with 400 and does not redirect (RFC 6749 §4.1.2.1)', async () => {
    const res = await authorize({ ...valid, client_id: 'nope' });
    expect(res.status).toBe(400);
    expect(res.headers.get('location')).toBeNull();
    expect((await res.json()).error).toBe('invalid_client');
  });

  it('answers an unregistered redirect_uri with 400 and does not redirect', async () => {
    const res = await authorize({ ...valid, redirect_uri: 'https://attacker.example/cb' });
    expect(res.status).toBe(400);
    expect(res.headers.get('location')).toBeNull();
    expect((await res.json()).error).toBe('invalid_request');
  });

  it('rejects a repeated parameter (RFC 6749 §3.1)', async () => {
    const res = await fetch(
      `${ctx.base}/authorize?${new URLSearchParams(valid)}&client_id=${PUBLIC_CLIENT}`,
      { redirect: 'manual' }
    );
    expect(res.status).toBe(400);
    expect((await res.json()).error_description).toMatch(/more than once/);
  });

  it.each([
    [{ response_type: 'token' }, 'unsupported_response_type'],
    [{ code_challenge: '' }, 'invalid_request'],
    [{ code_challenge_method: 'plain' }, 'invalid_request'],
    [{ code_challenge: 'too-short' }, 'invalid_request'],
    [{ resource: 'not a uri' }, 'invalid_target'],
    [{ resource: 'https://other.example.com/mcp' }, 'invalid_target'],
    [{ resource: 'https://mcp.example.com/other' }, 'invalid_target'],
  ])('redirects %o back with error %s, state and iss (RFC 9207 §2)', async (patch, error) => {
    const res = await authorize({ ...valid, ...patch });
    expect(res.status).toBe(302);
    const location = new URL(res.headers.get('location')!);
    expect(`${location.origin}${location.pathname}`).toBe(REDIRECT);
    expect(location.searchParams.get('error')).toBe(error);
    expect(location.searchParams.get('state')).toBe('xyz');
    expect(location.searchParams.get('iss')).toBe(ISSUER);
  });

  it.each([
    'https://mcp.example.com/mcp',
    'https://mcp.example.com/mcp/',
    'HTTPS://MCP.EXAMPLE.COM/mcp',
    'https://mcp.example.com',
    'https://mcp.example.com/',
  ])('accepts the canonical resource %s (RFC 8707 §2, MCP canonical URI)', async (resource) => {
    const res = await authorize({ ...valid, resource });
    expect(res.status).toBe(200);
  });

  it('uses the only registered redirect_uri when none is given', async () => {
    const { redirect_uri: _omitted, ...rest } = valid;
    const res = await authorize({ ...rest, client_id: 'single-uri-client' });
    expect(res.status).toBe(200);
    expect(await res.text()).toContain(`<code>${REDIRECT}</code>`);
  });

  it('requires redirect_uri when several are registered', async () => {
    const { redirect_uri: _omitted, ...rest } = valid;
    const res = await authorize(rest);
    expect(res.status).toBe(400);
  });

  it('shows the client_id host, not the self-asserted client_name, for a CIMD client', async () => {
    const res = await authorize({
      ...valid,
      client_id: CIMD_CLIENT,
      redirect_uri: 'https://app.example.org/callback',
    });
    expect(res.status).toBe(200);
    const html = await res.text();
    expect(html).toContain('<strong>app.example.org</strong>');
    expect(html).not.toContain('Totally Legit Client');
    expect(fetchDocument).toHaveBeenCalledTimes(1);
  });

  it('refuses a CIMD client_id pointing at cloud metadata, with no outbound request', async () => {
    const store = new ClientsStore({ clientIdDocuments: new ClientIdDocumentResolver() });
    const own = await serve();
    // Swap in a store whose resolver uses the real DNS lookup and fetcher.
    (own.provider as unknown as { _clientsStore: ClientsStore })._clientsStore = store;
    try {
      const res = await fetch(
        `${own.base}/authorize?${new URLSearchParams({
          ...valid,
          client_id: 'https://169.254.169.254/x',
          redirect_uri: 'http://localhost/cb',
        })}`,
        { redirect: 'manual' }
      );
      expect(res.status).toBe(400);
      expect((await res.json()).error).toBe('invalid_client');
    } finally {
      await own.close();
    }
  });

  it('answers other methods with 405 and an Allow header', async () => {
    const res = await fetch(`${ctx.base}/authorize`, { method: 'PUT' });
    expect(res.status).toBe(405);
    expect(res.headers.get('allow')).toBe('GET, POST');
  });
});

describe('/token', () => {
  let ctx: Ctx;
  beforeAll(async () => {
    ctx = await serve();
  });
  afterAll(() => ctx.close());

  it('rejects a token request for another resource with invalid_target (RFC 8707 §2)', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'authorization_code',
        client_id: PUBLIC_CLIENT,
        code: issueCode(ctx),
        code_verifier: VERIFIER,
        redirect_uri: REDIRECT,
        resource: 'https://other.example.com/mcp',
      })
    );
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_target');
  });

  it('accepts a token request for this resource', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'authorization_code',
        client_id: PUBLIC_CLIENT,
        code: issueCode(ctx),
        code_verifier: VERIFIER,
        redirect_uri: REDIRECT,
        resource: 'https://mcp.example.com/mcp',
      })
    );
    expect(res.status).toBe(200);
  });

  it('exchanges a code with a matching verifier, uncacheable (RFC 6749 §5.1)', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'authorization_code',
        client_id: PUBLIC_CLIENT,
        code: issueCode(ctx),
        code_verifier: VERIFIER,
        redirect_uri: REDIRECT,
      })
    );
    expect(res.status).toBe(200);
    expect(res.headers.get('cache-control')).toBe('no-store');
    expect(res.headers.get('pragma')).toBe('no-cache');
    expect(res.headers.get('access-control-allow-origin')).toBe('*');
    const body = await res.json();
    expect(body).toMatchObject({ token_type: 'bearer', scope: 'mcp:tools' });
    expect(body.access_token).toBeTruthy();
  });

  it('rejects a verifier that does not match the challenge (RFC 7636 §4.6)', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'authorization_code',
        client_id: PUBLIC_CLIENT,
        code: issueCode(ctx),
        code_verifier: 'b'.repeat(43),
        redirect_uri: REDIRECT,
      })
    );
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('accepts a token request without redirect_uri (OAuth 2.1 §10.2)', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'authorization_code',
        client_id: PUBLIC_CLIENT,
        code: issueCode(ctx),
        code_verifier: VERIFIER,
      })
    );
    expect(res.status).toBe(200);
  });

  it('rejects an unknown grant type', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({ grant_type: 'password', client_id: PUBLIC_CLIENT })
    );
    expect((await res.json()).error).toBe('unsupported_grant_type');
  });

  it('rejects an unknown client', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({ grant_type: 'refresh_token', client_id: 'nope', refresh_token: 'x' })
    );
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_client');
  });

  it('requires the secret of a confidential client', async () => {
    const res = await fetch(
      `${ctx.base}/token`,
      form({ grant_type: 'refresh_token', client_id: 'confidential', refresh_token: 'x' })
    );
    expect((await res.json()).error).toBe('invalid_client');
    const wrong = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'refresh_token',
        client_id: 'confidential',
        client_secret: 'nope',
        refresh_token: 'x',
      })
    );
    expect((await wrong.json()).error).toBe('invalid_client');
  });

  it('refreshes, and refuses a scope beyond the original grant (RFC 6749 §6)', async () => {
    const { refresh_token } = await tokens(ctx);
    const escalate = await fetch(
      `${ctx.base}/token`,
      form({
        grant_type: 'refresh_token',
        client_id: PUBLIC_CLIENT,
        refresh_token,
        scope: 'mcp:tools mcp:admin',
      })
    );
    expect(escalate.status).toBe(400);
    expect((await escalate.json()).error).toBe('invalid_scope');

    const ok = await fetch(
      `${ctx.base}/token`,
      form({ grant_type: 'refresh_token', client_id: PUBLIC_CLIENT, refresh_token })
    );
    expect(ok.status).toBe(200);
    expect((await ok.json()).scope).toBe('mcp:tools');
  });
});

describe('/revoke', () => {
  let ctx: Ctx;
  beforeAll(async () => {
    ctx = await serve();
  });
  afterAll(() => ctx.close());

  const refresh = (refresh_token: string) =>
    fetch(
      `${ctx.base}/token`,
      form({ grant_type: 'refresh_token', client_id: PUBLIC_CLIENT, refresh_token })
    );

  it('revokes the client’s own refresh token', async () => {
    const { refresh_token } = await tokens(ctx);
    const res = await fetch(
      `${ctx.base}/revoke`,
      form({ client_id: PUBLIC_CLIENT, token: refresh_token })
    );
    expect(res.status).toBe(200);
    expect((await refresh(refresh_token)).status).toBe(400);
  });

  it('leaves another client’s token alone, still answering 200 (RFC 7009 §2.1, §2.2)', async () => {
    const { refresh_token } = await tokens(ctx);
    const res = await fetch(
      `${ctx.base}/revoke`,
      form({ client_id: 'single-uri-client', token: refresh_token })
    );
    expect(res.status).toBe(200);
    expect((await refresh(refresh_token)).status).toBe(200);
  });

  it('answers 200 for an unknown token', async () => {
    const res = await fetch(`${ctx.base}/revoke`, form({ client_id: PUBLIC_CLIENT, token: 'x' }));
    expect(res.status).toBe(200);
  });
});

describe('/register', () => {
  const metadata = { redirect_uris: [REDIRECT], client_name: 'New App' };
  const register = (base: string, body: unknown, headers?: Record<string, string>) =>
    fetch(`${base}/register`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', ...headers },
      body: JSON.stringify(body),
    });

  it('registers a public client without a secret (RFC 7591 §3.2.1)', async () => {
    const ctx = await serve({ registration: true });
    try {
      const res = await register(ctx.base, { ...metadata, token_endpoint_auth_method: 'none' });
      expect(res.status).toBe(201);
      const client = await res.json();
      expect(client.client_id).toBeTruthy();
      expect(client.client_secret).toBeUndefined();
      expect(await ctx.provider.clientsStore.getClient(client.client_id)).toBeDefined();
    } finally {
      await ctx.close();
    }
  });

  it('issues a secret to a confidential client', async () => {
    const ctx = await serve({ registration: true });
    try {
      const client = await (await register(ctx.base, metadata)).json();
      expect(client.client_secret).toMatch(/^[0-9a-f]{64}$/);
      expect(client.client_secret_expires_at).toBeGreaterThan(Date.now() / 1000);
    } finally {
      await ctx.close();
    }
  });

  it('rejects a redirect URI outside the allowlist with invalid_redirect_uri (RFC 7591 §3.2.2)', async () => {
    const ctx = await serve({ registration: true, policy: new RedirectUriPolicy() });
    try {
      const res = await register(ctx.base, {
        redirect_uris: ['https://attacker.example/cb'],
        token_endpoint_auth_method: 'none',
      });
      expect(res.status).toBe(400);
      expect((await res.json()).error).toBe('invalid_redirect_uri');
      const ok = await register(ctx.base, {
        redirect_uris: ['https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:5555/cb'],
        token_endpoint_auth_method: 'none',
      });
      expect(ok.status).toBe(201);
    } finally {
      await ctx.close();
    }
  });

  it('rejects invalid metadata with invalid_client_metadata (RFC 7591 §3.2.2)', async () => {
    const ctx = await serve({ registration: true });
    try {
      const res = await register(ctx.base, { client_name: 'no redirects' });
      expect(res.status).toBe(400);
      expect((await res.json()).error).toBe('invalid_client_metadata');
    } finally {
      await ctx.close();
    }
  });

  it('requires the registration token when one is configured', async () => {
    const ctx = await serve({ registration: true, registrationToken: 'tok' });
    try {
      expect((await register(ctx.base, metadata)).status).toBe(401);
      expect((await register(ctx.base, metadata, { authorization: 'Bearer wrong' })).status).toBe(
        401
      );
      expect((await register(ctx.base, metadata, { authorization: 'Bearer tok' })).status).toBe(
        201
      );
    } finally {
      await ctx.close();
    }
  });
});
