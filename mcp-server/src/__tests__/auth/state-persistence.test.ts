// SPDX-License-Identifier: MIT
//
// End-to-end persistence tests that exercise the *real* filesystem (no fs mock),
// proving OAuth state survives a process restart and that an unwritable state
// directory degrades gracefully instead of crashing (discussion #342).

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mkdtempSync, rmSync, chmodSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

vi.mock('../../logger.js', () => ({
  logger: {
    warn: vi.fn(),
    info: vi.fn(),
    error: vi.fn(),
    debug: vi.fn(),
  },
}));

import { logger } from '../../logger.js';
import { ClientsStore, RefreshStore } from '../../auth/store.js';

describe('state persistence (real filesystem)', () => {
  let dir: string;

  beforeEach(() => {
    vi.clearAllMocks();
    dir = mkdtempSync(join(tmpdir(), 'aiquila-persist-'));
  });

  afterEach(() => {
    try {
      chmodSync(dir, 0o700);
    } catch {
      /* ignore */
    }
    rmSync(dir, { recursive: true, force: true });
  });

  it('RefreshStore persists a token to disk and a new instance reloads it', () => {
    const file = join(dir, 'refresh-tokens.json');

    const first = new RefreshStore(file);
    const token = first.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });

    // Simulate a restart: a brand-new store reading the same file.
    const second = new RefreshStore(file);
    const entry = second.get(token);
    expect(entry?.userId).toBe('alice');
    expect(entry?.clientId).toBe('c1');
    expect(entry?.scopes).toEqual(['read']);
  });

  it('RefreshStore writes no usable token to disk, only its SHA-256', () => {
    const file = join(dir, 'refresh-tokens.json');
    const token = new RefreshStore(file).store({
      grantId: 'g',
      userId: 'alice',
      clientId: 'c1',
      scopes: [],
    });
    const raw = readFileSync(file, 'utf8');
    expect(raw).not.toContain(token);
    expect(Object.keys(JSON.parse(raw))).toEqual([
      createHash('sha256').update(token).digest('hex'),
    ]);
  });

  it('RefreshStore hashes plaintext entries from earlier versions on load', () => {
    const file = join(dir, 'refresh-tokens.json');
    const legacy = '3f2b8c1e-7d4a-4e5f-9a6b-0c1d2e3f4a5b';
    writeFileSync(
      file,
      JSON.stringify({
        [legacy]: { userId: 'alice', clientId: 'c1', scopes: [], expiresAt: Date.now() + 60_000 },
      })
    );
    const store = new RefreshStore(file);
    expect(store.get(legacy)?.userId).toBe('alice');
    const raw = readFileSync(file, 'utf8');
    expect(raw).not.toContain(legacy);
    expect(new RefreshStore(file).get(legacy)?.grantId).toEqual(expect.any(String));
  });

  it('RefreshStore drops tokens issued under a previous MCP_AUTH_SECRET', () => {
    const file = join(dir, 'refresh-tokens.json');
    const saved = process.env.MCP_AUTH_SECRET;
    try {
      process.env.MCP_AUTH_SECRET = 'a'.repeat(64);
      const token = new RefreshStore(file).store({
        grantId: 'g',
        userId: 'alice',
        clientId: 'c1',
        scopes: [],
      });
      expect(new RefreshStore(file).get(token)?.userId).toBe('alice');

      process.env.MCP_AUTH_SECRET = 'b'.repeat(64);
      expect(new RefreshStore(file).get(token)).toBeUndefined();
      expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({});
    } finally {
      process.env.MCP_AUTH_SECRET = saved;
    }
  });

  it('RefreshStore adopts entries from before secret binding under the current secret', () => {
    const file = join(dir, 'refresh-tokens.json');
    const token = 'x'.repeat(43);
    writeFileSync(
      file,
      JSON.stringify({
        [createHash('sha256').update(token).digest('hex')]: {
          grantId: 'g',
          userId: 'alice',
          clientId: 'c1',
          scopes: [],
          expiresAt: Date.now() + 60_000,
        },
      })
    );
    expect(new RefreshStore(file).get(token)?.userId).toBe('alice');
    const [entry] = Object.values(JSON.parse(readFileSync(file, 'utf8'))) as {
      secretId?: string;
    }[];
    expect(entry.secretId).toMatch(/^[0-9a-f]{16}$/);
  });

  it('RefreshStore reflects a deletion on disk across instances', () => {
    const file = join(dir, 'refresh-tokens.json');

    const first = new RefreshStore(file);
    const token = first.store({ userId: 'bob', clientId: 'c2', scopes: [] });
    first.delete(token);

    const second = new RefreshStore(file);
    expect(second.get(token)).toBeUndefined();
  });

  it('ClientsStore persists a registered client and a new instance reloads it', async () => {
    const file = join(dir, 'clients.json');

    const first = new ClientsStore({ enableDynamicRegistration: true, stateFile: file });
    const client = first.registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'Round Trip App',
    });

    const second = new ClientsStore({ enableDynamicRegistration: true, stateFile: file });
    expect(await second.getClient(client.client_id)).toMatchObject({
      client_id: client.client_id,
      client_name: 'Round Trip App',
    });
  });

  it('degrades gracefully when the state dir is read-only — no throw, in-memory still works, warns with #342', () => {
    if (process.getuid?.() === 0) {
      // root bypasses permission checks — skip
      return;
    }
    const file = join(dir, 'refresh-tokens.json');
    chmodSync(dir, 0o500); // read + execute, no write

    const store = new RefreshStore(file);
    let token = '';
    expect(() => {
      token = store.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });
    }).not.toThrow();

    // The token is unusable across a restart, but works in-memory for this process.
    expect(store.get(token)?.userId).toBe('alice');

    expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
      expect.objectContaining({ code: expect.stringMatching(/EACCES|EPERM|EROFS/) }),
      expect.stringContaining('discussions/342')
    );
  });
});
