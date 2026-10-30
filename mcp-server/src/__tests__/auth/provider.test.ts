// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';

const flow = vi.hoisted(() => ({
  startLoginFlow: vi.fn(),
  pollLoginFlow: vi.fn(),
  checkCredential: vi.fn(),
  deleteAppPassword: vi.fn(),
}));
vi.mock('../../auth/login-flow.js', () => flow);

import { NextcloudOAuthProvider, renderFlowPage, LOGIN_PAGE_CSP } from '../../auth/provider.js';
import {
  CredentialBackendUnavailableError,
  CredentialStore,
  LocalCipher,
  type CredentialCipher,
} from '../../auth/credential-store.js';
import { LOGIN_STYLESHEET_PATH } from '../../auth/login-page-css.js';

const TEST_SECRET = 'super-secret-32-char-key-for-testing!!';
const LOGIN_URL = 'https://cloud.example.com/login/v2/flow/abc';
const CRED = { loginName: 'alice', appPassword: 'app-password-1' };

// Minimal client fixture
const CLIENT = {
  client_id: 'test-client',
  redirect_uris: [new URL('https://example.com/callback')],
  client_name: 'Test App',
} as any;

function mockRes() {
  return {
    set: vi.fn().mockReturnThis(),
    status: vi.fn().mockReturnThis(),
    type: vi.fn().mockReturnThis(),
    send: vi.fn(),
    redirect: vi.fn(),
  };
}

// ---- renderFlowPage ----

describe('renderFlowPage', () => {
  const waiting = {
    kind: 'waiting' as const,
    redirectUri: 'https://r.com',
    loginUrl: LOGIN_URL,
    flowId: 'f'.repeat(43),
  };

  it('links to the Nextcloud sign-in and reloads itself while waiting', () => {
    const html = renderFlowPage(waiting);
    expect(html).toContain(`href="${LOGIN_URL}"`);
    expect(html).toContain('rel="noopener noreferrer"');
    expect(html).toContain(`url=/auth/flow/${'f'.repeat(43)}`);
  });

  it('asks for no credentials', () => {
    const html = renderFlowPage(waiting);
    expect(html).not.toContain('<form');
    expect(html).not.toContain('type="password"');
  });

  it('links the external stylesheet and inlines no styles or scripts', () => {
    const html = renderFlowPage(waiting);
    expect(html).toContain(`<link rel="stylesheet" href="${LOGIN_STYLESHEET_PATH}">`);
    expect(html).not.toContain('<style');
    expect(html).not.toContain('<script');
    expect(html).not.toContain('style="');
  });

  it('HTML-escapes the client name, the redirect URI and an error', () => {
    expect(renderFlowPage({ ...waiting, clientName: '<b>x</b>' })).toContain(
      '&lt;b&gt;x&lt;/b&gt;'
    );
    expect(renderFlowPage({ ...waiting, redirectUri: 'https://r.com/"x' })).toContain('&quot;x');
    const err = renderFlowPage({ kind: 'error', message: '<script>' });
    expect(err).toContain('&lt;script&gt;');
    expect(err).not.toContain('http-equiv="refresh"');
  });
});

// ---- NextcloudOAuthProvider ----

describe('NextcloudOAuthProvider', () => {
  let provider: NextcloudOAuthProvider;
  let stateDir: string;
  const savedEnv = process.env;

  beforeEach(() => {
    stateDir = mkdtempSync(join(tmpdir(), 'aiquila-provider-'));
    process.env = {
      ...savedEnv,
      MCP_AUTH_SECRET: TEST_SECRET,
      MCP_AUTH_ISSUER: 'https://test.example.com',
      MCP_AUTH_STATE_DIR: stateDir,
      MCP_CREDENTIAL_KEY: randomBytes(64).toString('hex'),
      NEXTCLOUD_URL: 'https://cloud.example.com',
    };
    flow.startLoginFlow.mockReset().mockResolvedValue({
      loginUrl: LOGIN_URL,
      pollToken: 'poll-token',
      pollPath: '/login/v2/poll',
    });
    flow.pollLoginFlow.mockReset().mockResolvedValue(null);
    flow.checkCredential.mockReset().mockResolvedValue({ state: 'valid', userId: 'alice' });
    flow.deleteAppPassword.mockReset().mockResolvedValue(true);
    provider = new NextcloudOAuthProvider();
  });

  afterEach(() => {
    process.env = savedEnv;
    rmSync(stateDir, { recursive: true, force: true });
  });

  // ---- clientsStore ----

  describe('clientsStore', () => {
    it('exposes an OAuthRegisteredClientsStore with getClient', () => {
      expect(provider.clientsStore).toBeDefined();
      expect(typeof provider.clientsStore.getClient).toBe('function');
    });

    it('registerClient is undefined by default (no env vars set)', () => {
      // Dynamic registration is disabled unless MCP_REGISTRATION_ENABLED=true
      expect(provider.clientsStore.registerClient).toBeUndefined();
    });
  });

  // ---- authorize + continueFlow ----

  describe('authorize', () => {
    const binding = 'b'.repeat(43);
    const params = {
      codeChallenge: 'ch',
      redirectUri: 'https://example.com/callback',
      state: 'st',
      scopes: ['mcp:tools'],
      browserBinding: binding,
    };

    async function start() {
      const res = mockRes();
      await provider.authorize(CLIENT, params, res as any);
      const html = res.send.mock.calls[0][0] as string;
      return { res, flowId: html.match(/url=\/auth\/flow\/([A-Za-z0-9_-]+)"/)![1] };
    }

    it('starts a Login Flow v2 named after the client and shows the sign-in page', async () => {
      const { res } = await start();
      expect(flow.startLoginFlow).toHaveBeenCalledWith('AIquila MCP (Test App)');
      expect(res.set).toHaveBeenCalledWith('X-Frame-Options', 'DENY');
      expect(res.set).toHaveBeenCalledWith('X-Content-Type-Options', 'nosniff');
      expect(res.set).toHaveBeenCalledWith('Referrer-Policy', 'no-referrer');
      expect(res.set).toHaveBeenCalledWith('Content-Security-Policy', LOGIN_PAGE_CSP);
      expect(LOGIN_PAGE_CSP).not.toContain("'unsafe-inline'");
      expect(res.status).toHaveBeenCalledWith(200);
      expect(res.send).toHaveBeenCalledWith(expect.stringContaining(LOGIN_URL));
    });

    it('never puts the browser binding in the page', async () => {
      const { res } = await start();
      expect(res.send.mock.calls[0][0]).not.toContain(binding);
    });

    it('reports a Nextcloud that cannot start the flow as server_error', async () => {
      flow.startLoginFlow.mockRejectedValue(new Error('down'));
      await expect(provider.authorize(CLIENT, params, mockRes() as any)).rejects.toMatchObject({
        code: 'server_error',
      });
    });

    it('keeps waiting while the user has not granted access', async () => {
      const { flowId } = await start();
      const res = mockRes();
      await provider.continueFlow(flowId, binding, res);
      expect(res.redirect).not.toHaveBeenCalled();
      expect(res.send).toHaveBeenCalledWith(expect.stringContaining('http-equiv="refresh"'));
    });

    it('redirects with a code once Nextcloud returns the app password', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValue(CRED);
      const res = mockRes();
      await provider.continueFlow(flowId, binding, res);
      expect(flow.checkCredential).toHaveBeenCalledWith('alice', 'app-password-1');
      const location = new URL(res.redirect.mock.calls[0][1]);
      expect(location.searchParams.get('code')).toBeTruthy();
      expect(location.searchParams.get('state')).toBe('st');
      expect(location.searchParams.get('iss')).toBe('https://test.example.com/');
    });

    it('completes only in the browser that started it', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValue(CRED);
      for (const other of [undefined, 'c'.repeat(43)]) {
        const res = mockRes();
        await provider.continueFlow(flowId, other, res);
        expect(res.status).toHaveBeenCalledWith(400);
        expect(res.redirect).not.toHaveBeenCalled();
      }
      expect(flow.pollLoginFlow).not.toHaveBeenCalled();
    });

    it('issues no code for an app password Nextcloud rejects', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValue(CRED);
      flow.checkCredential.mockResolvedValue({ state: 'rejected' });
      const res = mockRes();
      await provider.continueFlow(flowId, binding, res);
      expect(res.redirect).not.toHaveBeenCalled();
      expect(res.send).toHaveBeenCalledWith(expect.stringContaining('did not confirm'));
    });

    it('polls Nextcloud at most once per second', async () => {
      const { flowId } = await start();
      await provider.continueFlow(flowId, binding, mockRes());
      await provider.continueFlow(flowId, binding, mockRes());
      expect(flow.pollLoginFlow).toHaveBeenCalledTimes(1);
    });

    it('two reloads racing on one poll issue a single code', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValue(CRED);
      const a = mockRes();
      const b = mockRes();
      await Promise.all([
        provider.continueFlow(flowId, binding, a),
        provider.continueFlow(flowId, binding, b),
      ]);
      const redirects = [a, b].filter((r) => r.redirect.mock.calls.length > 0);
      expect(redirects).toHaveLength(1);
    });

    it('retries an app password Nextcloud could not confirm, instead of deleting it', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValueOnce(CRED).mockResolvedValue(null);
      flow.checkCredential.mockResolvedValueOnce({ state: 'unknown' });
      const first = mockRes();
      await provider.continueFlow(flowId, binding, first);
      expect(first.redirect).not.toHaveBeenCalled();
      expect(flow.deleteAppPassword).not.toHaveBeenCalled();
      await new Promise((r) => setTimeout(r, 1100));
      const second = mockRes();
      await provider.continueFlow(flowId, binding, second);
      // Nextcloud returns the credentials once; the kept ones are confirmed now.
      expect(flow.pollLoginFlow).toHaveBeenCalledTimes(1);
      expect(second.redirect).toHaveBeenCalled();
    });

    it('refuses more sign-ins from one address than its share', async () => {
      const from = (clientAddress: string) => ({ ...params, clientAddress });
      for (let i = 0; i < 20; i++)
        await provider.authorize(CLIENT, from('192.0.2.1'), mockRes() as any);
      await expect(
        provider.authorize(CLIENT, from('192.0.2.1'), mockRes() as any)
      ).rejects.toMatchObject({ code: 'temporarily_unavailable' });
      await expect(
        provider.authorize(CLIENT, from('192.0.2.2'), mockRes() as any)
      ).resolves.toBeUndefined();
    });

    it('seals the credential as soon as Nextcloud hands it over', async () => {
      const { flowId } = await start();
      flow.pollLoginFlow.mockResolvedValue(CRED);
      await provider.sweep();
      // The page was not reloaded yet, but the credential is already on disk.
      const { readFileSync } = await import('node:fs');
      const file = readFileSync(join(stateDir, 'credentials.json'), 'utf8');
      expect(Object.keys(JSON.parse(file))).toHaveLength(1);
      expect(file).not.toContain('app-password-1');
      const res = mockRes();
      await provider.continueFlow(flowId, binding, res);
      expect(res.redirect).toHaveBeenCalled();
    });

    it('refuses new sign-ins once too many are in progress', async () => {
      for (let i = 0; i < 500; i++) await provider.authorize(CLIENT, params, mockRes() as any);
      await expect(provider.authorize(CLIENT, params, mockRes() as any)).rejects.toMatchObject({
        code: 'temporarily_unavailable',
      });
    });

    it('shows the requested scopes', async () => {
      const { res } = await start();
      expect(res.send.mock.calls[0][0]).toContain('<code>mcp:tools</code>');
    });
  });

  // ---- issueAuthCode + challengeForAuthorizationCode ----

  describe('challengeForAuthorizationCode', () => {
    it('returns the PKCE challenge for a valid code', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'the-challenge',
        clientId: CLIENT.client_id,
        scopes: ['read'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const challenge = await provider.challengeForAuthorizationCode(CLIENT, code);
      expect(challenge).toBe('the-challenge');
    });

    it('throws for an unknown code', async () => {
      await expect(provider.challengeForAuthorizationCode(CLIENT, 'nonexistent')).rejects.toThrow();
    });

    it('throws when client_id does not match', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: 'other-client',
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      await expect(provider.challengeForAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });
  });

  // ---- exchangeAuthorizationCode ----

  describe('exchangeAuthorizationCode', () => {
    it('returns a valid access token and refresh token', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const tokens = await provider.exchangeAuthorizationCode(CLIENT, code);
      expect(tokens.access_token).toBeDefined();
      expect(tokens.refresh_token).toBeDefined();
      expect(tokens.token_type).toBe('bearer');
      expect(tokens.expires_in).toBe(3600);
      expect(tokens.scope).toBe('read');
    });

    it('includes correct scopes in the token', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read', 'write'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const { access_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      const info = await provider.verifyAccessToken(access_token);
      expect(info.scopes).toEqual(['read', 'write']);
    });

    it('invalidates the code after use (prevents double-spend)', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      await provider.exchangeAuthorizationCode(CLIENT, code);
      await expect(provider.exchangeAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });

    it('a replay racing the first exchange leaves neither with a refresh token', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const results = await Promise.allSettled([
        provider.exchangeAuthorizationCode(CLIENT, code),
        provider.exchangeAuthorizationCode(CLIENT, code),
      ]);
      expect(results.map((r) => r.status)).toEqual(['rejected', 'rejected']);
      await vi.waitFor(() =>
        expect(flow.deleteAppPassword).toHaveBeenCalledWith('alice', 'app-password-1')
      );
    });

    it('throws for an unknown code', async () => {
      await expect(provider.exchangeAuthorizationCode(CLIENT, 'bad-code')).rejects.toThrow();
    });

    it('throws when redirect_uri does not match', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      await expect(
        provider.exchangeAuthorizationCode(CLIENT, code, 'https://attacker.com')
      ).rejects.toThrow();
    });

    it('throws when client_id does not match', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: 'other-client',
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      await expect(provider.exchangeAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });
  });

  // ---- verifyAccessToken ----

  describe('verifyAccessToken', () => {
    async function issueToken(userId = 'alice', scopes = ['read']): Promise<string> {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes,
        redirectUri: 'https://r.com',
        userId,
      });
      const { access_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      return access_token;
    }

    it('verifies a freshly issued token and returns correct AuthInfo', async () => {
      const token = await issueToken('alice', ['read', 'write']);
      const info = await provider.verifyAccessToken(token);
      expect(info.token).toBe(token);
      expect(info.clientId).toBe(CLIENT.client_id);
      expect(info.scopes).toEqual(['read', 'write']);
      expect(info.expiresAt).toBeGreaterThan(Math.floor(Date.now() / 1000));
    });

    it('throws for a tampered token signature', async () => {
      const token = await issueToken();
      const [h, p] = token.split('.');
      await expect(provider.verifyAccessToken(`${h}.${p}.invalidsignature`)).rejects.toThrow();
    });

    it('throws for a completely invalid token', async () => {
      await expect(provider.verifyAccessToken('not.a.jwt')).rejects.toThrow();
    });

    it('throws when MCP_AUTH_SECRET is missing', async () => {
      delete process.env.MCP_AUTH_SECRET;
      await expect(provider.verifyAccessToken('any.token.here')).rejects.toThrow('MCP_AUTH_SECRET');
    });

    it('throws for a token signed with a different secret', async () => {
      // Issue token with one secret, then change secret
      const token = await issueToken();
      process.env.MCP_AUTH_SECRET = 'completely-different-secret-value!!';
      await expect(provider.verifyAccessToken(token)).rejects.toThrow();
    });

    it('embeds iss and aud claims in the issued token', async () => {
      const token = await issueToken();
      const payload = JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString());
      expect(payload.iss).toBe('https://test.example.com');
      expect(payload.aud).toBe('https://test.example.com/mcp');
    });

    it('throws when verified against a different issuer', async () => {
      const token = await issueToken();
      process.env.MCP_AUTH_ISSUER = 'https://other.example.com';
      await expect(provider.verifyAccessToken(token)).rejects.toThrow(
        'Invalid or expired access token'
      );
    });

    // A bare Error would surface as a 500, which clients read as a server fault
    // rather than a cue to re-authenticate. It must be an OAuthError with the
    // invalid_token code so requireBearerAuth answers 401 with a
    // WWW-Authenticate challenge.
    it('rejects an invalid token with OAuthError(invalid_token) (→ 401, not 500)', async () => {
      const { OAuthError, OAuthErrorCode } = await import('@modelcontextprotocol/server');
      const err = await provider.verifyAccessToken('not-a-jwt').catch((e: unknown) => e);
      expect(err).toBeInstanceOf(OAuthError);
      expect((err as InstanceType<typeof OAuthError>).code).toBe(OAuthErrorCode.InvalidToken);
    });

    it('embeds correct aud claim (resource server URL)', async () => {
      const token = await issueToken();
      const payload = JSON.parse(Buffer.from(token.split('.')[1], 'base64url').toString());
      expect(payload.aud).toBe('https://test.example.com/mcp');
    });
  });

  // ---- exchangeRefreshToken ----

  describe('exchangeRefreshToken', () => {
    async function issueRefreshToken(): Promise<string> {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const { refresh_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      return refresh_token!;
    }

    it('issues new access and refresh tokens', async () => {
      const refreshToken = await issueRefreshToken();
      const tokens = await provider.exchangeRefreshToken(CLIENT, refreshToken);
      expect(tokens.access_token).toBeDefined();
      expect(tokens.refresh_token).toBeDefined();
      expect(tokens.token_type).toBe('bearer');
      expect(tokens.expires_in).toBe(3600);
    });

    it('rotates the refresh token (old token is invalidated)', async () => {
      const refreshToken = await issueRefreshToken();
      await provider.exchangeRefreshToken(CLIENT, refreshToken);
      await expect(provider.exchangeRefreshToken(CLIENT, refreshToken)).rejects.toThrow();
    });

    it('new access token is valid and verifiable', async () => {
      const refreshToken = await issueRefreshToken();
      const { access_token } = await provider.exchangeRefreshToken(CLIENT, refreshToken);
      const info = await provider.verifyAccessToken(access_token);
      expect(info.clientId).toBe(CLIENT.client_id);
    });

    it('restricts scopes when requested scopes are a subset', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read', 'write'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const { refresh_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      const tokens = await provider.exchangeRefreshToken(CLIENT, refresh_token!, ['read']);
      expect(tokens.scope).toBe('read');
    });

    it('throws for an unknown refresh token', async () => {
      await expect(provider.exchangeRefreshToken(CLIENT, 'bad-refresh-token')).rejects.toThrow();
    });

    it('throws when client_id does not match', async () => {
      const refreshToken = await issueRefreshToken();
      const otherClient = { ...CLIENT, client_id: 'other-client' };
      await expect(provider.exchangeRefreshToken(otherClient, refreshToken)).rejects.toThrow();
    });
  });

  // ---- revokeToken ----

  describe('revokeToken', () => {
    it('revokes a refresh token so it cannot be used again', async () => {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      const { refresh_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      await provider.revokeToken(CLIENT, { token: refresh_token! });
      await expect(provider.exchangeRefreshToken(CLIENT, refresh_token!)).rejects.toThrow();
    });

    it('does not throw when revoking an unknown token', async () => {
      await expect(provider.revokeToken(CLIENT, { token: 'unknown-token' })).resolves.not.toThrow();
    });
  });

  // ---- grants: revocation and the Nextcloud user check ----

  describe('grant revocation', () => {
    async function login(p = provider) {
      const code = await p.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read'],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      return p.exchangeAuthorizationCode(CLIENT, code);
    }

    it('revoking a refresh token also rejects access tokens of the grant (RFC 7009 §2.1)', async () => {
      const first = await login();
      const second = await provider.exchangeRefreshToken(CLIENT, first.refresh_token!);
      await provider.revokeToken(CLIENT, { token: second.refresh_token! });
      await expect(provider.verifyAccessToken(first.access_token)).rejects.toThrow();
      await expect(provider.verifyAccessToken(second.access_token)).rejects.toThrow();
    });

    it('leaves other grants untouched', async () => {
      const a = await login();
      const b = await login();
      await provider.revokeToken(CLIENT, { token: a.refresh_token! });
      await expect(provider.verifyAccessToken(b.access_token)).resolves.toBeDefined();
      await expect(provider.exchangeRefreshToken(CLIENT, b.refresh_token!)).resolves.toBeDefined();
    });

    it('revokes an access token on its own', async () => {
      const tokens = await login();
      await provider.revokeToken(CLIENT, { token: tokens.access_token });
      await expect(provider.verifyAccessToken(tokens.access_token)).rejects.toThrow();
      await expect(
        provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!)
      ).resolves.toBeDefined();
    });

    it('ignores revocation of another client’s access token', async () => {
      const tokens = await login();
      await provider.revokeToken({ ...CLIENT, client_id: 'other' }, { token: tokens.access_token });
      await expect(provider.verifyAccessToken(tokens.access_token)).resolves.toBeDefined();
    });

    it('keeps a revocation across a restart', async () => {
      const tokens = await login();
      await provider.revokeToken(CLIENT, { token: tokens.refresh_token! });
      const restarted = new NextcloudOAuthProvider();
      await expect(restarted.verifyAccessToken(tokens.access_token)).rejects.toThrow();
    });

    it('rejects a signed token without a grant id', async () => {
      const { SignJWT } = await import('jose');
      const token = await new SignJWT({ sub: 'alice', client_id: CLIENT.client_id, scopes: [] })
        .setProtectedHeader({ alg: 'HS256' })
        .setIssuer('https://test.example.com')
        .setAudience('https://test.example.com/mcp')
        .setExpirationTime('1h')
        .sign(new TextEncoder().encode(TEST_SECRET));
      await expect(provider.verifyAccessToken(token)).rejects.toThrow();
    });

    for (const [why, check] of [
      ['the device was removed or the user disabled', { state: 'rejected' }],
      ['the app password belongs to someone else', { state: 'valid', userId: 'mallory' }],
    ] as const) {
      it(`refuses refresh and ends the grant when ${why}`, async () => {
        const tokens = await login();
        flow.checkCredential.mockResolvedValue(check);
        await expect(
          provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!)
        ).rejects.toMatchObject({ code: 'invalid_grant' });
        expect(flow.checkCredential).toHaveBeenCalledWith('alice', 'app-password-1');
        await expect(provider.verifyAccessToken(tokens.access_token)).rejects.toThrow();
        await vi.waitFor(() =>
          expect(flow.deleteAppPassword).toHaveBeenCalledWith('alice', 'app-password-1')
        );
        flow.checkCredential.mockResolvedValue({ state: 'valid', userId: 'alice' });
        await expect(
          provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!)
        ).rejects.toThrow();
      });
    }

    it('redeems a refresh token once even when two requests race', async () => {
      const tokens = await login();
      const results = await Promise.allSettled([
        provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!),
        provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!),
      ]);
      expect(results.map((r) => r.status).sort()).toEqual(['fulfilled', 'rejected']);
    });

    it('reuse of a rotated refresh token ends the grant (RFC 9700 §4.14.2)', async () => {
      const first = await login();
      const second = await provider.exchangeRefreshToken(CLIENT, first.refresh_token!);
      await expect(
        provider.exchangeRefreshToken(CLIENT, first.refresh_token!)
      ).rejects.toMatchObject({ code: 'invalid_grant' });
      // The token the legitimate client (or the attacker) holds now is dead too…
      await expect(provider.exchangeRefreshToken(CLIENT, second.refresh_token!)).rejects.toThrow();
      // …and so are the access tokens of the grant.
      await expect(provider.verifyAccessToken(second.access_token)).rejects.toThrow();
    });

    it('logs reuse at warn with client and user', async () => {
      const { logger } = await import('../../logger.js');
      const warn = vi.spyOn(logger, 'warn');
      const first = await login();
      await provider.exchangeRefreshToken(CLIENT, first.refresh_token!);
      await provider.exchangeRefreshToken(CLIENT, first.refresh_token!).catch(() => {});
      expect(warn).toHaveBeenCalledWith(
        { user: 'alice', client: CLIENT.client_id },
        expect.stringContaining('reuse detected')
      );
      warn.mockRestore();
    });

    it('detects reuse across a restart', async () => {
      const first = await login();
      const second = await provider.exchangeRefreshToken(CLIENT, first.refresh_token!);
      const restarted = new NextcloudOAuthProvider();
      await expect(restarted.exchangeRefreshToken(CLIENT, first.refresh_token!)).rejects.toThrow();
      await expect(restarted.exchangeRefreshToken(CLIENT, second.refresh_token!)).rejects.toThrow();
    });

    it('a reused token from another client does not end the grant', async () => {
      const first = await login();
      const second = await provider.exchangeRefreshToken(CLIENT, first.refresh_token!);
      await expect(
        provider.exchangeRefreshToken({ ...CLIENT, client_id: 'other' }, first.refresh_token!)
      ).rejects.toThrow();
      await expect(
        provider.exchangeRefreshToken(CLIENT, second.refresh_token!)
      ).resolves.toBeDefined();
    });

    it('refreshes when Nextcloud cannot tell (unknown)', async () => {
      const tokens = await login();
      flow.checkCredential.mockResolvedValue({ state: 'unknown' });
      await expect(
        provider.exchangeRefreshToken(CLIENT, tokens.refresh_token!)
      ).resolves.toBeDefined();
    });

    it('revoking the grant deletes its app password in Nextcloud (RFC 7009 §2.1)', async () => {
      const tokens = await login();
      await provider.revokeToken(CLIENT, { token: tokens.refresh_token! });
      await vi.waitFor(() =>
        expect(flow.deleteAppPassword).toHaveBeenCalledWith('alice', 'app-password-1')
      );
    });
  });

  // ---- credentialFor ----

  describe('credentialFor', () => {
    async function login() {
      const code = await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      return provider.exchangeAuthorizationCode(CLIENT, code);
    }

    it("returns the grant's app password for its access token", async () => {
      const { access_token } = await login();
      const auth = await provider.verifyAccessToken(access_token);
      await expect(provider.credentialFor(auth)).resolves.toMatchObject({
        userId: 'alice',
        ...CRED,
      });
    });

    it('survives a restart: the credential is read back from the encrypted store', async () => {
      const { access_token } = await login();
      const restarted = new NextcloudOAuthProvider();
      const auth = await restarted.verifyAccessToken(access_token);
      await expect(restarted.credentialFor(auth)).resolves.toMatchObject(CRED);
    });

    it('while Nextcloud cannot be asked, requests share one check and back off', async () => {
      const { access_token } = await login();
      const auth = await provider.verifyAccessToken(access_token);
      vi.useFakeTimers({ now: Date.now() + 6 * 60 * 1000, toFake: ['Date'] });
      try {
        flow.checkCredential.mockReset().mockResolvedValue({ state: 'unknown' });
        await Promise.all([provider.credentialFor(auth), provider.credentialFor(auth)]);
        await provider.credentialFor(auth);
        expect(flow.checkCredential).toHaveBeenCalledTimes(1);
        vi.setSystemTime(Date.now() + 31 * 1000);
        await expect(provider.credentialFor(auth)).resolves.toMatchObject(CRED);
        expect(flow.checkCredential).toHaveBeenCalledTimes(2);
      } finally {
        vi.useRealTimers();
      }
    });

    it('a token without user or grant has no credential', async () => {
      await expect(
        provider.credentialFor({ token: 't', clientId: 'c', scopes: [], extra: {} })
      ).resolves.toBeUndefined();
    });

    it('internal tokens carry the service marker, not a user', async () => {
      process.env.MCP_INTERNAL_TOKEN = 'i'.repeat(40);
      const auth = await provider.verifyAccessToken('i'.repeat(40));
      expect(auth.extra).toEqual({ service: true });
    });
  });

  // ---- the key service being unavailable ----

  describe('when the key service is unavailable', () => {
    /** A cipher whose backend can be switched off, as OpenBao going down. */
    class FlakyCipher implements CredentialCipher {
      readonly name = 'flaky';
      down = false;
      private readonly inner = new LocalCipher(randomBytes(64));
      encrypt(p: Buffer, aad: Buffer) {
        if (this.down) return Promise.reject(new CredentialBackendUnavailableError('down'));
        return this.inner.encrypt(p, aad);
      }
      decrypt(s: string, aad: Buffer) {
        if (this.down) return Promise.reject(new CredentialBackendUnavailableError('down'));
        return this.inner.decrypt(s, aad);
      }
    }
    let cipher: FlakyCipher;
    let p: NextcloudOAuthProvider;
    beforeEach(() => {
      cipher = new FlakyCipher();
      p = new NextcloudOAuthProvider(undefined, {
        credentials: new CredentialStore(cipher, join(stateDir, 'credentials.json')),
      });
    });
    async function login() {
      const code = await p.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      return p.exchangeAuthorizationCode(CLIENT, code);
    }

    it('a request fails, but the grant survives the outage', async () => {
      const { access_token } = await login();
      const auth = await p.verifyAccessToken(access_token);
      cipher.down = true;
      await expect(p.credentialFor(auth)).rejects.toBeInstanceOf(CredentialBackendUnavailableError);
      cipher.down = false;
      await expect(p.credentialFor(auth)).resolves.toMatchObject(CRED);
      expect(flow.deleteAppPassword).not.toHaveBeenCalled();
    });

    it('refresh still works during the outage', async () => {
      const { refresh_token } = await login();
      cipher.down = true;
      await expect(p.exchangeRefreshToken(CLIENT, refresh_token!)).resolves.toBeDefined();
    });

    it('a grant revoked during the outage keeps its sealed value until it can be deleted', async () => {
      const { refresh_token } = await login();
      cipher.down = true;
      await p.revokeToken(CLIENT, { token: refresh_token! });
      await new Promise((r) => setTimeout(r, 10));
      expect(flow.deleteAppPassword).not.toHaveBeenCalled();
      cipher.down = false;
      await p.sweep();
      await vi.waitFor(() =>
        expect(flow.deleteAppPassword).toHaveBeenCalledWith('alice', 'app-password-1')
      );
    });

    it('a grant arriving during the outage is kept until it can be sealed', async () => {
      const binding = 'b'.repeat(43);
      const res = mockRes();
      await p.authorize(
        CLIENT,
        { codeChallenge: 'ch', redirectUri: 'https://r.com', scopes: [], browserBinding: binding },
        res as any
      );
      const flowId = (res.send.mock.calls[0][0] as string).match(/url=\/auth\/flow\/([\w-]+)"/)![1];
      flow.pollLoginFlow.mockResolvedValueOnce(CRED).mockResolvedValue(null);
      cipher.down = true;
      const first = mockRes();
      await p.continueFlow(flowId, binding, first);
      expect(first.redirect).not.toHaveBeenCalled();
      expect(flow.deleteAppPassword).not.toHaveBeenCalled();
      cipher.down = false;
      await new Promise((r) => setTimeout(r, 1100));
      const second = mockRes();
      await p.continueFlow(flowId, binding, second);
      expect(flow.pollLoginFlow).toHaveBeenCalledTimes(1);
      expect(second.redirect).toHaveBeenCalled();
    });
  });

  // ---- a restart before the code is exchanged ----

  describe('a restart before the code is exchanged', () => {
    afterEach(() => vi.useRealTimers());

    it("still deletes the unredeemed code's app password in Nextcloud", async () => {
      await provider.issueAuthCode({
        credential: CRED,
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'alice',
      });
      // The code itself lived in memory and is gone.
      const restarted = new NextcloudOAuthProvider();
      vi.useFakeTimers({ now: Date.now() + 6 * 60 * 1000, toFake: ['Date'] });
      await restarted.sweep();
      await vi.waitFor(() =>
        expect(flow.deleteAppPassword).toHaveBeenCalledWith('alice', 'app-password-1')
      );
    });
  });
});
