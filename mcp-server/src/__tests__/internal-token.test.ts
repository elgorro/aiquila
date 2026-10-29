// SPDX-License-Identifier: MIT

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

describe('MCP_INTERNAL_TOKEN network restriction', () => {
  const saved = process.env.MCP_INTERNAL_TOKEN_NETWORKS;
  afterEach(() => {
    if (saved === undefined) delete process.env.MCP_INTERNAL_TOKEN_NETWORKS;
    else process.env.MCP_INTERNAL_TOKEN_NETWORKS = saved;
  });

  const req = (remoteAddress: string, headers: Record<string, string> = {}) => ({
    headers,
    socket: { remoteAddress },
  });

  it('accepts loopback and private addresses by default', async () => {
    const { internalNetworks, internalTokenAllowed } = await import('../auth/internal-token.js');
    const nets = internalNetworks();
    for (const ip of ['127.0.0.1', '10.1.2.3', '172.18.0.5', '192.168.1.1', '::1', 'fd00::1']) {
      expect(internalTokenAllowed(req(ip), nets), ip).toBe(true);
    }
    expect(internalTokenAllowed(req('::ffff:172.18.0.5'), nets)).toBe(true);
  });

  it('rejects public addresses', async () => {
    const { internalNetworks, internalTokenAllowed } = await import('../auth/internal-token.js');
    const nets = internalNetworks();
    for (const ip of ['8.8.8.8', '172.32.0.1', '2001:db8::1', '::ffff:8.8.8.8']) {
      expect(internalTokenAllowed(req(ip), nets), ip).toBe(false);
    }
  });

  it('rejects a request that came through a proxy, even from a private address', async () => {
    const { internalNetworks, internalTokenAllowed } = await import('../auth/internal-token.js');
    const nets = internalNetworks();
    for (const h of ['x-forwarded-for', 'forwarded', 'x-real-ip']) {
      expect(internalTokenAllowed(req('172.18.0.2', { [h]: '172.18.0.9' }), nets), h).toBe(false);
    }
  });

  it('MCP_INTERNAL_TOKEN_NETWORKS replaces the default ranges', async () => {
    process.env.MCP_INTERNAL_TOKEN_NETWORKS = '172.20.0.0/16, 10.9.9.9';
    const { internalNetworks, internalTokenAllowed } = await import('../auth/internal-token.js');
    const nets = internalNetworks();
    expect(internalTokenAllowed(req('172.20.3.4'), nets)).toBe(true);
    expect(internalTokenAllowed(req('10.9.9.9'), nets)).toBe(true);
    expect(internalTokenAllowed(req('10.9.9.8'), nets)).toBe(false);
    expect(internalTokenAllowed(req('127.0.0.1'), nets)).toBe(false);
  });
});
