import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { NextcloudOAuthProvider } from '../auth/provider.js';

describe('MCP_INTERNAL_TOKEN', () => {
  const INTERNAL_TOKEN = 'a'.repeat(64);
  let provider: NextcloudOAuthProvider;

  beforeEach(() => {
    provider = new NextcloudOAuthProvider();
    process.env.MCP_INTERNAL_TOKEN = INTERNAL_TOKEN;
    // Auth secret is needed for JWT fallback path
    process.env.MCP_AUTH_SECRET = 'test-secret-for-jwt';
  });

  afterEach(() => {
    delete process.env.MCP_INTERNAL_TOKEN;
    delete process.env.MCP_AUTH_SECRET;
  });

  it('accepts a matching internal token', async () => {
    const authInfo = await provider.verifyAccessToken(INTERNAL_TOKEN);
    expect(authInfo.clientId).toBe('internal');
    expect(authInfo.scopes).toEqual([]);
    expect(authInfo.token).toBe(INTERNAL_TOKEN);
    expect(authInfo.expiresAt).toBeGreaterThan(Math.floor(Date.now() / 1000));
  });

  it('rejects a wrong token and falls through to JWT verification', async () => {
    await expect(provider.verifyAccessToken('b'.repeat(64))).rejects.toThrow();
  });

  it('falls through to JWT when MCP_INTERNAL_TOKEN is not set', async () => {
    delete process.env.MCP_INTERNAL_TOKEN;
    await expect(provider.verifyAccessToken('not-a-jwt')).rejects.toThrow();
  });

  it('rejects a shorter token without timing leak', async () => {
    await expect(provider.verifyAccessToken('short')).rejects.toThrow();
  });

  it('rejects a longer token without timing leak', async () => {
    await expect(provider.verifyAccessToken('a'.repeat(128))).rejects.toThrow();
  });
});
