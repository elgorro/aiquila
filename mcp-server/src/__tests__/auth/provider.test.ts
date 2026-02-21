import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { NextcloudOAuthProvider, renderLoginForm } from '../../auth/provider.js';

const TEST_SECRET = 'super-secret-32-char-key-for-testing!!';

// Minimal client fixture
const CLIENT = {
  client_id: 'test-client',
  redirect_uris: [new URL('https://example.com/callback')],
  client_name: 'Test App',
} as any;

// ---- renderLoginForm ----

describe('renderLoginForm', () => {
  it('renders a POST form targeting /auth/login', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
    });
    expect(html).toContain('method="POST"');
    expect(html).toContain('action="/auth/login"');
  });

  it('includes all required hidden fields', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
      state: 'st',
      scope: 'read write',
    });
    expect(html).toContain('name="client_id"');
    expect(html).toContain('name="redirect_uri"');
    expect(html).toContain('name="code_challenge"');
    expect(html).toContain('name="state"');
    expect(html).toContain('name="scope"');
  });

  it('includes client name in the page when provided', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
      clientName: 'My App',
    });
    expect(html).toContain('My App');
  });

  it('includes error message when provided', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
      error: 'Bad credentials',
    });
    expect(html).toContain('Bad credentials');
  });

  it('HTML-escapes special characters in clientName', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
      clientName: '<script>xss</script>',
    });
    expect(html).not.toContain('<script>xss</script>');
    expect(html).toContain('&lt;script&gt;');
  });

  it('HTML-escapes special characters in error', () => {
    const html = renderLoginForm({
      clientId: 'c1',
      redirectUri: 'https://r.com',
      codeChallenge: 'cc',
      error: '<b>error</b>',
    });
    expect(html).not.toContain('<b>error</b>');
    expect(html).toContain('&lt;b&gt;');
  });
});

// ---- NextcloudOAuthProvider ----

describe('NextcloudOAuthProvider', () => {
  let provider: NextcloudOAuthProvider;
  const savedEnv = process.env;

  beforeEach(() => {
    process.env = { ...savedEnv, MCP_AUTH_SECRET: TEST_SECRET };
    provider = new NextcloudOAuthProvider();
  });

  afterEach(() => {
    process.env = savedEnv;
  });

  // ---- clientsStore ----

  describe('clientsStore', () => {
    it('exposes an OAuthRegisteredClientsStore', () => {
      expect(provider.clientsStore).toBeDefined();
      expect(typeof provider.clientsStore.getClient).toBe('function');
      expect(typeof provider.clientsStore.registerClient).toBe('function');
    });

    it('registers and retrieves clients', () => {
      const client = provider.clientsStore.registerClient!({
        redirect_uris: [new URL('https://example.com/cb')],
      }) as any;
      expect(client.client_id).toBeDefined();
      expect(provider.clientsStore.getClient(client.client_id)).toEqual(client);
    });
  });

  // ---- authorize ----

  describe('authorize', () => {
    it('sends a 200 HTML login form response', async () => {
      const res = {
        status: vi.fn().mockReturnThis(),
        type: vi.fn().mockReturnThis(),
        send: vi.fn(),
      };
      await provider.authorize(
        CLIENT,
        { codeChallenge: 'ch', redirectUri: 'https://r.com', state: 'st', scopes: ['read'] },
        res as any
      );
      expect(res.status).toHaveBeenCalledWith(200);
      expect(res.type).toHaveBeenCalledWith('html');
      expect(res.send).toHaveBeenCalledWith(expect.stringContaining('action="/auth/login"'));
    });

    it('includes client name in the rendered form', async () => {
      const res = {
        status: vi.fn().mockReturnThis(),
        type: vi.fn().mockReturnThis(),
        send: vi.fn(),
      };
      await provider.authorize(
        CLIENT,
        { codeChallenge: 'ch', redirectUri: 'https://r.com' },
        res as any
      );
      expect(res.send).toHaveBeenCalledWith(expect.stringContaining('Test App'));
    });
  });

  // ---- issueAuthCode + challengeForAuthorizationCode ----

  describe('challengeForAuthorizationCode', () => {
    it('returns the PKCE challenge for a valid code', async () => {
      const code = provider.issueAuthCode({
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
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: 'other-client',
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'u',
      });
      await expect(provider.challengeForAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });
  });

  // ---- exchangeAuthorizationCode ----

  describe('exchangeAuthorizationCode', () => {
    it('returns a valid access token and refresh token', async () => {
      const code = provider.issueAuthCode({
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
      const code = provider.issueAuthCode({
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
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'u',
      });
      await provider.exchangeAuthorizationCode(CLIENT, code);
      await expect(provider.exchangeAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });

    it('throws for an unknown code', async () => {
      await expect(provider.exchangeAuthorizationCode(CLIENT, 'bad-code')).rejects.toThrow();
    });

    it('throws when redirect_uri does not match', async () => {
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'u',
      });
      await expect(
        provider.exchangeAuthorizationCode(CLIENT, code, undefined, 'https://attacker.com')
      ).rejects.toThrow();
    });

    it('throws when client_id does not match', async () => {
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: 'other-client',
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'u',
      });
      await expect(provider.exchangeAuthorizationCode(CLIENT, code)).rejects.toThrow();
    });
  });

  // ---- verifyAccessToken ----

  describe('verifyAccessToken', () => {
    async function issueToken(userId = 'alice', scopes = ['read']): Promise<string> {
      const code = provider.issueAuthCode({
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
  });

  // ---- exchangeRefreshToken ----

  describe('exchangeRefreshToken', () => {
    async function issueRefreshToken(): Promise<string> {
      const code = provider.issueAuthCode({
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
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: ['read', 'write'],
        redirectUri: 'https://r.com',
        userId: 'u',
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
      const code = provider.issueAuthCode({
        pkceChallenge: 'ch',
        clientId: CLIENT.client_id,
        scopes: [],
        redirectUri: 'https://r.com',
        userId: 'u',
      });
      const { refresh_token } = await provider.exchangeAuthorizationCode(CLIENT, code);
      await provider.revokeToken(CLIENT, { token: refresh_token! });
      await expect(provider.exchangeRefreshToken(CLIENT, refresh_token!)).rejects.toThrow();
    });

    it('does not throw when revoking an unknown token', async () => {
      await expect(provider.revokeToken(CLIENT, { token: 'unknown-token' })).resolves.not.toThrow();
    });
  });
});
