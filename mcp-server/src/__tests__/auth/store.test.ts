// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../logger.js', () => ({
  logger: {
    warn: vi.fn(),
    info: vi.fn(),
    error: vi.fn(),
    debug: vi.fn(),
  },
}));

import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { ClientsStore, CodeStore, RefreshStore, clientTtlMs } from '../../auth/store.js';
import { resetSharedStateDb, StateDb } from '../../auth/state-db.js';

// fromEnv() opens the shared state database in MCP_AUTH_STATE_DIR: give every
// test a fresh directory instead of /app/state.
let stateDirForTest: string;
beforeEach(() => {
  resetSharedStateDb();
  stateDirForTest = mkdtempSync(join(tmpdir(), 'aiquila-store-'));
  process.env.MCP_AUTH_STATE_DIR = stateDirForTest;
});
afterEach(() => {
  resetSharedStateDb();
  delete process.env.MCP_AUTH_STATE_DIR;
  rmSync(stateDirForTest, { recursive: true, force: true });
});

// ---- ClientsStore (dynamic registration enabled) ----

describe('ClientsStore with dynamic registration', () => {
  let store: ClientsStore;

  beforeEach(() => {
    store = new ClientsStore({ enableDynamicRegistration: true });
  });

  it('returns undefined for an unknown client', async () => {
    expect(await store.getClient('unknown')).toBeUndefined();
  });

  it('registers a client and retrieves it by id', async () => {
    const client = store.registerClient!({
      redirect_uris: [new URL('https://example.com/callback')],
    });
    expect(client.client_id).toBeDefined();
    expect(client.client_id_issued_at).toBeCloseTo(Math.floor(Date.now() / 1000), -2);
    // Stored as JSON, so URL objects come back as their string form.
    expect(await store.getClient(client.client_id)).toEqual(JSON.parse(JSON.stringify(client)));
  });

  it('assigns a unique client_id on each registration', async () => {
    const a = store.registerClient!({ redirect_uris: [new URL('https://a.com/cb')] });
    const b = store.registerClient!({ redirect_uris: [new URL('https://b.com/cb')] });
    expect(a.client_id).not.toBe(b.client_id);
  });

  it('preserves all metadata fields', async () => {
    const client = store.registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'My App',
      scope: 'read write',
    });
    expect((await store.getClient(client.client_id))?.client_name).toBe('My App');
    expect((await store.getClient(client.client_id))?.scope).toBe('read write');
  });
});

// ---- ClientsStore constructor options ----

describe('ClientsStore constructor options', () => {
  it('registerClient is undefined when enableDynamicRegistration is false', async () => {
    const store = new ClientsStore({ enableDynamicRegistration: false });
    expect(store.registerClient).toBeUndefined();
  });

  it('registerClient is undefined when no options are provided', async () => {
    const store = new ClientsStore();
    expect(store.registerClient).toBeUndefined();
  });

  it('registerClient is defined when enableDynamicRegistration is true', async () => {
    const store = new ClientsStore({ enableDynamicRegistration: true });
    expect(store.registerClient).toBeTypeOf('function');
  });

  it('pre-seeded clients are returned by getClient() using their exact client_id', async () => {
    const preseeded = {
      client_id: 'my-fixed-client-id',
      client_secret: 'super-secret',
      client_id_issued_at: 1700000000,
      client_secret_expires_at: 0,
      redirect_uris: [],
      token_endpoint_auth_method: 'client_secret_post' as const,
      grant_types: ['authorization_code'] as const,
      response_types: ['code'] as const,
      client_name: 'Pre-seeded client',
    };
    const store = new ClientsStore({ preseededClients: [preseeded] });
    const retrieved = await store.getClient('my-fixed-client-id');
    expect(retrieved).toBeDefined();
    expect(retrieved?.client_id).toBe('my-fixed-client-id');
    expect(retrieved?.client_secret).toBe('super-secret');
  });

  it('client_secret_expires_at: 0 is stored correctly', async () => {
    const preseeded = {
      client_id: 'id-1',
      client_secret: 'secret-1',
      client_id_issued_at: 1700000000,
      client_secret_expires_at: 0,
      redirect_uris: [],
    };
    const store = new ClientsStore({ preseededClients: [preseeded] });
    expect((await store.getClient('id-1'))?.client_secret_expires_at).toBe(0);
  });
});

// ---- ClientsStore.fromEnv() ----

describe('ClientsStore.fromEnv()', () => {
  const savedEnv: Record<string, string | undefined> = {};

  beforeEach(() => {
    savedEnv.MCP_CLIENT_ID = process.env.MCP_CLIENT_ID;
    savedEnv.MCP_CLIENT_SECRET = process.env.MCP_CLIENT_SECRET;
    savedEnv.MCP_REGISTRATION_ENABLED = process.env.MCP_REGISTRATION_ENABLED;
    delete process.env.MCP_CLIENT_ID;
    delete process.env.MCP_CLIENT_SECRET;
    delete process.env.MCP_REGISTRATION_ENABLED;
  });

  afterEach(() => {
    for (const [key, val] of Object.entries(savedEnv)) {
      if (val === undefined) delete process.env[key];
      else process.env[key] = val;
    }
  });

  it('returns empty store with no registerClient when no env vars are set', async () => {
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeUndefined();
    expect(await store.getClient('anything')).toBeUndefined();
  });

  it('pre-seeds a public PKCE client when MCP_CLIENT_ID is set', async () => {
    process.env.MCP_CLIENT_ID = 'env-client-id';
    const store = ClientsStore.fromEnv();
    const client = await store.getClient('env-client-id');
    expect(client).toBeDefined();
    expect(client?.client_id).toBe('env-client-id');
    expect(client?.client_secret).toBeUndefined();
    expect(client?.token_endpoint_auth_method).toBe('none');
  });

  it('enables registerClient when MCP_REGISTRATION_ENABLED=true', async () => {
    process.env.MCP_REGISTRATION_ENABLED = 'true';
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeTypeOf('function');
  });

  it('does not enable registerClient when MCP_REGISTRATION_ENABLED=false', async () => {
    process.env.MCP_REGISTRATION_ENABLED = 'false';
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeUndefined();
  });

  it('pre-seeds client with MCP_CLIENT_ID alone (no secret needed)', async () => {
    process.env.MCP_CLIENT_ID = 'only-id';
    const store = ClientsStore.fromEnv();
    expect(await store.getClient('only-id')).toBeDefined();
  });
});

// ---- CodeStore ----

describe('CodeStore', () => {
  let store: CodeStore;

  beforeEach(() => {
    store = new CodeStore();
  });

  it('stores and retrieves an auth code entry', async () => {
    const code = store.store({
      pkceChallenge: 'my-challenge',
      clientId: 'client-1',
      scopes: ['read', 'write'],
      redirectUri: 'https://example.com/callback',
      userId: 'alice',
      state: 'state-xyz',
      grantId: 'g1',
    });
    expect(typeof code).toBe('string');
    const entry = store.get(code);
    expect(entry).toBeDefined();
    expect(entry?.userId).toBe('alice');
    expect(entry?.pkceChallenge).toBe('my-challenge');
    expect(entry?.scopes).toEqual(['read', 'write']);
    expect(entry?.state).toBe('state-xyz');
  });

  it('returns undefined for an unknown code', async () => {
    expect(store.get('does-not-exist')).toBeUndefined();
  });

  it('deletes a code so it cannot be retrieved again', async () => {
    const code = store.store({
      pkceChallenge: 'ch',
      clientId: 'c',
      scopes: [],
      redirectUri: 'https://r.com',
      userId: 'u',
      grantId: 'g1',
    });
    store.delete(code);
    expect(store.get(code)).toBeUndefined();
  });

  it('stores each code with a unique ID', async () => {
    const params = {
      pkceChallenge: 'ch',
      clientId: 'c',
      scopes: [],
      redirectUri: 'https://r.com',
      userId: 'u',
      grantId: 'g1',
    };
    const a = store.store(params);
    const b = store.store(params);
    expect(a).not.toBe(b);
  });

  it('removes expired codes without them being looked up again', () => {
    const params = {
      pkceChallenge: 'ch',
      clientId: 'c',
      scopes: [],
      redirectUri: 'https://r.com',
      userId: 'u',
      grantId: 'g1',
    };
    const stale = store.store(params);
    const later = Date.now() + 6 * 60 * 1000;
    const fresh = store.store(params);
    // Only the code issued before the cut-off has expired by then.
    (store as unknown as { codes: Map<string, { expiresAt: number }> }).codes.get(
      fresh
    )!.expiresAt = later + 60 * 1000;
    expect(store.takeExpired(later).map((e) => e.grantId)).toEqual(['g1']);
    const codes = (store as unknown as { codes: Map<string, unknown> }).codes;
    expect(codes.has(stale)).toBe(false);
    expect(codes.has(fresh)).toBe(true);
    expect(store.takeExpired(later)).toEqual([]);
  });
});

// ---- RefreshStore ----

describe('RefreshStore', () => {
  let store: RefreshStore;

  beforeEach(() => {
    store = new RefreshStore();
  });

  it('stores and retrieves a refresh token entry', async () => {
    const token = store.store({ grantId: 'g', userId: 'bob', clientId: 'c1', scopes: ['read'] });
    expect(typeof token).toBe('string');
    const entry = store.get(token);
    expect(entry?.userId).toBe('bob');
    expect(entry?.clientId).toBe('c1');
    expect(entry?.scopes).toEqual(['read']);
  });

  it('returns undefined for an unknown token', async () => {
    expect(store.get('bad-token')).toBeUndefined();
  });

  it('deletes a token so it cannot be retrieved again', async () => {
    const token = store.store({ grantId: 'g', userId: 'u', clientId: 'c', scopes: [] });
    store.delete(token);
    expect(store.get(token)).toBeUndefined();
  });

  it('stores each token with a unique ID', async () => {
    const params = { grantId: 'g', userId: 'u', clientId: 'c', scopes: [] };
    const a = store.store(params);
    const b = store.store(params);
    expect(a).not.toBe(b);
  });
});

// ---- ClientsStore — expiry of unused clients ----

describe('ClientsStore — expiry', () => {
  const HOUR = 60 * 60 * 1000;
  const DAY = 24 * HOUR;
  afterEach(() => {
    delete process.env.MCP_CLIENT_UNUSED_TTL;
    delete process.env.MCP_CLIENT_IDLE_TTL;
  });

  function register(store: ClientsStore): string {
    return store.registerClient!({ redirect_uris: [new URL('https://example.com/cb')] }).client_id;
  }

  it('removes a client that never obtained a token once the unused TTL has passed', async () => {
    const store = new ClientsStore({ enableDynamicRegistration: true, unusedTtlMs: DAY });
    const id = register(store);

    expect(store.expireClients(new Set(), Date.now() + DAY - HOUR)).toEqual([]);
    expect(store.expireClients(new Set(), Date.now() + DAY + HOUR)).toEqual([id]);
    expect(await store.getClient(id)).toBeUndefined();
  });

  it('keeps a client that obtained a token', async () => {
    const store = new ClientsStore({ enableDynamicRegistration: true, unusedTtlMs: DAY });
    const id = register(store);
    store.markUsed(id);

    expect(store.expireClients(new Set(), Date.now() + 365 * DAY)).toEqual([]);
    expect(await store.getClient(id)).toBeDefined();
  });

  it('keeps a client holding a live refresh token and records it as used', async () => {
    const store = new ClientsStore({
      enableDynamicRegistration: true,
      unusedTtlMs: DAY,
      idleTtlMs: 7 * DAY,
    });
    const id = register(store);
    const later = Date.now() + 2 * DAY;

    expect(store.expireClients(new Set([id]), later)).toEqual([]);
    // Its refresh tokens are gone now; idleness counts from when it was seen active.
    expect(store.expireClients(new Set(), later + 6 * DAY)).toEqual([]);
    expect(store.expireClients(new Set(), later + 8 * DAY)).toEqual([id]);
  });

  it('removes an idle client only when an idle TTL is set', async () => {
    const without = new ClientsStore({ enableDynamicRegistration: true, unusedTtlMs: DAY });
    const a = register(without);
    without.markUsed(a);
    expect(without.expireClients(new Set(), Date.now() + 1000 * DAY)).toEqual([]);

    const withIdle = new ClientsStore({
      enableDynamicRegistration: true,
      unusedTtlMs: DAY,
      idleTtlMs: 30 * DAY,
    });
    const b = register(withIdle);
    withIdle.markUsed(b);
    expect(withIdle.expireClients(new Set(), Date.now() + 29 * DAY)).toEqual([]);
    expect(withIdle.expireClients(new Set(), Date.now() + 31 * DAY)).toEqual([b]);
  });

  it('never removes a pre-seeded client', async () => {
    const store = new ClientsStore({
      preseededClients: [{ client_id: 'fixed', client_id_issued_at: 0, redirect_uris: [] }],
      unusedTtlMs: 1,
      idleTtlMs: 1,
    });
    store.markUsed('fixed');
    expect(store.expireClients(new Set(), Date.now() + 1000 * DAY)).toEqual([]);
    expect(await store.getClient('fixed')).toBeDefined();
  });

  it('keeps nothing when no TTL is configured', async () => {
    const store = new ClientsStore({ enableDynamicRegistration: true });
    register(store);
    expect(store.expireClients(new Set(), Date.now() + 1000 * DAY)).toEqual([]);
  });

  it('keeps last use across instances, without storing the pre-seeded client', async () => {
    const db = StateDb.memory();
    const store = new ClientsStore({
      enableDynamicRegistration: true,
      preseededClients: [{ client_id: 'fixed', client_id_issued_at: 0, redirect_uris: [] }],
      db,
      unusedTtlMs: DAY,
    });
    const id = register(store);
    store.markUsed(id);
    store.markUsed('fixed');

    const reloaded = new ClientsStore({ db, unusedTtlMs: DAY });
    expect(reloaded.expireClients(new Set(), Date.now() + 10 * DAY)).toEqual([]);
    expect(await reloaded.getClient('fixed')).toBeUndefined();
    // The usage field stays out of the client metadata.
    expect(await reloaded.getClient(id)).not.toHaveProperty('last_used_at');
  });

  it('records a repeated use at most once an hour', () => {
    const db = StateDb.memory();
    const store = new ClientsStore({ enableDynamicRegistration: true, db, idleTtlMs: HOUR });
    const id = register(store);
    const now = Date.now();
    store.markUsed(id, now);
    store.markUsed(id, now + 10 * 60 * 1000);
    // Still counted from the first use: idle an hour after it, not after the second.
    expect(store.expireClients(new Set(), now + HOUR + 1000)).toEqual([id]);
    const id2 = register(store);
    store.markUsed(id2, now);
    store.markUsed(id2, now + 2 * HOUR);
    expect(store.expireClients(new Set(), now + 2 * HOUR + 1000)).toEqual([]);
  });

  it('fromEnv() reads MCP_CLIENT_UNUSED_TTL and MCP_CLIENT_IDLE_TTL', async () => {
    expect(clientTtlMs('MCP_CLIENT_UNUSED_TTL', 86400)).toBe(DAY);
    process.env.MCP_CLIENT_UNUSED_TTL = '0';
    expect(clientTtlMs('MCP_CLIENT_UNUSED_TTL', 86400)).toBeUndefined();
    process.env.MCP_CLIENT_IDLE_TTL = '3600';
    expect(clientTtlMs('MCP_CLIENT_IDLE_TTL', 0)).toBe(HOUR);
    process.env.MCP_CLIENT_IDLE_TTL = 'soon';
    expect(clientTtlMs('MCP_CLIENT_IDLE_TTL', 0)).toBeUndefined();
  });
});

// ---- RefreshStore — lifetime ----

describe('RefreshStore — lifetime', () => {
  afterEach(() => {
    delete process.env.MCP_REFRESH_TOKEN_TTL;
  });

  it('defaults to 30 days', async () => {
    const { RefreshStore } = await import('../../auth/store.js');
    const store = new RefreshStore();
    const before = Date.now();
    const entry = store.get(store.store({ grantId: 'g', userId: 'u', clientId: 'c', scopes: [] }))!;
    expect(entry.expiresAt - before).toBeGreaterThanOrEqual(30 * 24 * 3600 * 1000);
    expect(entry.expiresAt - before).toBeLessThan(30 * 24 * 3600 * 1000 + 5000);
  });

  it('MCP_REFRESH_TOKEN_TTL sets it in seconds', async () => {
    process.env.MCP_REFRESH_TOKEN_TTL = '3600';
    const { refreshTtlMs } = await import('../../auth/store.js');
    expect(refreshTtlMs()).toBe(3600 * 1000);
  });

  it('an invalid MCP_REFRESH_TOKEN_TTL falls back to the default', async () => {
    const { refreshTtlMs } = await import('../../auth/store.js');
    for (const v of ['0', '-5', 'abc', '1.5']) {
      process.env.MCP_REFRESH_TOKEN_TTL = v;
      expect(refreshTtlMs(), v).toBe(30 * 24 * 3600 * 1000);
    }
  });

  it('keeps at most 10 spent tokens per grant, dropping the oldest', async () => {
    const { RefreshStore } = await import('../../auth/store.js');
    const store = new RefreshStore();
    const tokens: string[] = [];
    let current = store.store({ grantId: 'g', userId: 'u', clientId: 'c', scopes: [] });
    for (let i = 0; i < 15; i++) {
      tokens.push(current);
      store.markSpent(current);
      current = store.store({ grantId: 'g', userId: 'u', clientId: 'c', scopes: [] });
    }
    const kept = tokens.filter((t) => store.get(t)?.spent);
    expect(kept).toHaveLength(10);
    expect(kept).toEqual(tokens.slice(5));
    expect(store.get(current)?.spent).toBeUndefined();
  });
});
