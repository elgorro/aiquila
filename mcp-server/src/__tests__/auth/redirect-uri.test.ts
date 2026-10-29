// SPDX-License-Identifier: MIT

import { describe, it, expect, afterEach, vi } from 'vitest';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { RedirectUriPolicy } from '../../auth/redirect-uri.js';
import { ClientsStore } from '../../auth/store.js';

vi.mock('../../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), debug: vi.fn(), error: vi.fn() },
}));

describe('RedirectUriPolicy', () => {
  const policy = new RedirectUriPolicy(['cloud.example.com', 'cursor:']);

  it.each([
    'https://claude.ai/api/mcp/auth_callback',
    'https://claude.com/api/mcp/auth_callback',
    'https://cloud.example.com/apps/aiquila/oauth/callback',
    'http://127.0.0.1:51004/cb',
    'http://[::1]:61023/cb',
    'http://localhost:8080/cb',
    'com.example.app:/oauth2redirect',
    'cursor://anysphere.cursor-retrieval/oauth/callback',
  ])('allows %s', (uri) => {
    expect(policy.reject(uri)).toBeUndefined();
  });

  it.each([
    ['https://attacker.example/cb', 'not an allowed redirect host'],
    ['https://claude.ai.attacker.example/cb', 'not an allowed redirect host'],
    ['http://cloud.example.com/cb', 'not https'],
    ['https://claude.ai/cb#frag', 'fragment'],
    ['https://user:pw@claude.ai/cb', 'credentials'],
    ['javascript:alert(1)', 'not a redirect scheme'],
    ['data:text/html,x', 'not a redirect scheme'],
    ['myapp://cb', 'not an allowed redirect scheme'],
    ['not a uri', 'not an absolute URI'],
  ])('rejects %s', (uri, reason) => {
    expect(policy.reject(uri)).toContain(reason);
  });

  it('allows any https host with *', () => {
    expect(new RedirectUriPolicy(['*']).reject('https://anything.example/cb')).toBeUndefined();
    expect(new RedirectUriPolicy(['*']).reject('http://anything.example/cb')).toBeDefined();
  });

  describe('fromEnv', () => {
    const savedEnv = process.env;
    afterEach(() => {
      process.env = savedEnv;
    });

    it('adds MCP_REDIRECT_ALLOWLIST entries and the NEXTCLOUD_URL host', () => {
      process.env = {
        ...savedEnv,
        NEXTCLOUD_URL: 'https://nc.example.net',
        MCP_REDIRECT_ALLOWLIST: ' Extra.Example.org , vscode: ',
      };
      const p = RedirectUriPolicy.fromEnv();
      expect(p.reject('https://nc.example.net/cb')).toBeUndefined();
      expect(p.reject('https://extra.example.org/cb')).toBeUndefined();
      expect(p.reject('vscode://cb')).toBeUndefined();
      expect(p.reject('https://other.example/cb')).toBeDefined();
    });
  });
});

describe('ClientsStore with a redirect policy', () => {
  it('drops persisted registrations whose redirect URI is no longer allowed', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'aiquila-policy-'));
    try {
      const file = join(dir, 'clients.json');
      writeFileSync(
        file,
        JSON.stringify([
          { client_id: 'good', redirect_uris: ['https://claude.ai/api/mcp/auth_callback'] },
          { client_id: 'evil', redirect_uris: ['https://attacker.example/cb'] },
        ])
      );
      const store = new ClientsStore({
        enableDynamicRegistration: true,
        stateFile: file,
        redirectPolicy: new RedirectUriPolicy(),
      });
      expect(await store.getClient('good')).toBeDefined();
      expect(await store.getClient('evil')).toBeUndefined();
      expect(readFileSync(file, 'utf8')).not.toContain('evil');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it('leaves pre-seeded clients alone', async () => {
    const store = new ClientsStore({
      preseededClients: [{ client_id: 'op', redirect_uris: ['https://intranet.example/cb'] }],
      redirectPolicy: new RedirectUriPolicy(),
    });
    expect(await store.getClient('op')).toBeDefined();
  });
});
