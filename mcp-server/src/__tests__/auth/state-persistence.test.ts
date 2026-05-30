// SPDX-License-Identifier: MIT
//
// End-to-end persistence tests that exercise the *real* filesystem (no fs mock),
// proving OAuth state survives a process restart and that an unwritable state
// directory degrades gracefully instead of crashing (discussion #342).

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mkdtempSync, rmSync, chmodSync } from 'node:fs';
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

  it('RefreshStore reflects a deletion on disk across instances', () => {
    const file = join(dir, 'refresh-tokens.json');

    const first = new RefreshStore(file);
    const token = first.store({ userId: 'bob', clientId: 'c2', scopes: [] });
    first.delete(token);

    const second = new RefreshStore(file);
    expect(second.get(token)).toBeUndefined();
  });

  it('ClientsStore persists a registered client and a new instance reloads it', () => {
    const file = join(dir, 'clients.json');

    const first = new ClientsStore({ enableDynamicRegistration: true, stateFile: file });
    const client = first.registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'Round Trip App',
    });

    const second = new ClientsStore({ enableDynamicRegistration: true, stateFile: file });
    expect(second.getClient(client.client_id)).toMatchObject({
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
