import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('node:fs');
vi.mock('../../logger.js', () => ({
  logger: {
    warn: vi.fn(),
    info: vi.fn(),
    error: vi.fn(),
    debug: vi.fn(),
  },
}));

import * as fs from 'node:fs';
import { logger } from '../../logger.js';
import { ClientsStore, CodeStore, RefreshStore } from '../../auth/store.js';

// ---- ClientsStore (dynamic registration enabled) ----

describe('ClientsStore with dynamic registration', () => {
  let store: ClientsStore;

  beforeEach(() => {
    store = new ClientsStore({ enableDynamicRegistration: true });
  });

  it('returns undefined for an unknown client', () => {
    expect(store.getClient('unknown')).toBeUndefined();
  });

  it('registers a client and retrieves it by id', () => {
    const client = store.registerClient!({
      redirect_uris: [new URL('https://example.com/callback')],
    });
    expect(client.client_id).toBeDefined();
    expect(client.client_id_issued_at).toBeCloseTo(Math.floor(Date.now() / 1000), -2);
    expect(store.getClient(client.client_id)).toEqual(client);
  });

  it('assigns a unique client_id on each registration', () => {
    const a = store.registerClient!({ redirect_uris: [new URL('https://a.com/cb')] });
    const b = store.registerClient!({ redirect_uris: [new URL('https://b.com/cb')] });
    expect(a.client_id).not.toBe(b.client_id);
  });

  it('preserves all metadata fields', () => {
    const client = store.registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'My App',
      scope: 'read write',
    });
    expect(store.getClient(client.client_id)?.client_name).toBe('My App');
    expect(store.getClient(client.client_id)?.scope).toBe('read write');
  });
});

// ---- ClientsStore constructor options ----

describe('ClientsStore constructor options', () => {
  it('registerClient is undefined when enableDynamicRegistration is false', () => {
    const store = new ClientsStore({ enableDynamicRegistration: false });
    expect(store.registerClient).toBeUndefined();
  });

  it('registerClient is undefined when no options are provided', () => {
    const store = new ClientsStore();
    expect(store.registerClient).toBeUndefined();
  });

  it('registerClient is defined when enableDynamicRegistration is true', () => {
    const store = new ClientsStore({ enableDynamicRegistration: true });
    expect(store.registerClient).toBeTypeOf('function');
  });

  it('pre-seeded clients are returned by getClient() using their exact client_id', () => {
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
    const retrieved = store.getClient('my-fixed-client-id');
    expect(retrieved).toBeDefined();
    expect(retrieved?.client_id).toBe('my-fixed-client-id');
    expect(retrieved?.client_secret).toBe('super-secret');
  });

  it('client_secret_expires_at: 0 is stored correctly', () => {
    const preseeded = {
      client_id: 'id-1',
      client_secret: 'secret-1',
      client_id_issued_at: 1700000000,
      client_secret_expires_at: 0,
      redirect_uris: [],
    };
    const store = new ClientsStore({ preseededClients: [preseeded] });
    expect(store.getClient('id-1')?.client_secret_expires_at).toBe(0);
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

  it('returns empty store with no registerClient when no env vars are set', () => {
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeUndefined();
    expect(store.getClient('anything')).toBeUndefined();
  });

  it('pre-seeds a public PKCE client when MCP_CLIENT_ID is set', () => {
    process.env.MCP_CLIENT_ID = 'env-client-id';
    const store = ClientsStore.fromEnv();
    const client = store.getClient('env-client-id');
    expect(client).toBeDefined();
    expect(client?.client_id).toBe('env-client-id');
    expect(client?.client_secret).toBeUndefined();
    expect(client?.token_endpoint_auth_method).toBe('none');
  });

  it('enables registerClient when MCP_REGISTRATION_ENABLED=true', () => {
    process.env.MCP_REGISTRATION_ENABLED = 'true';
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeTypeOf('function');
  });

  it('does not enable registerClient when MCP_REGISTRATION_ENABLED=false', () => {
    process.env.MCP_REGISTRATION_ENABLED = 'false';
    const store = ClientsStore.fromEnv();
    expect(store.registerClient).toBeUndefined();
  });

  it('pre-seeds client with MCP_CLIENT_ID alone (no secret needed)', () => {
    process.env.MCP_CLIENT_ID = 'only-id';
    const store = ClientsStore.fromEnv();
    expect(store.getClient('only-id')).toBeDefined();
  });
});

// ---- CodeStore ----

describe('CodeStore', () => {
  let store: CodeStore;

  beforeEach(() => {
    store = new CodeStore();
  });

  it('stores and retrieves an auth code entry', () => {
    const code = store.store({
      pkceChallenge: 'my-challenge',
      clientId: 'client-1',
      scopes: ['read', 'write'],
      redirectUri: 'https://example.com/callback',
      userId: 'alice',
      state: 'state-xyz',
    });
    expect(typeof code).toBe('string');
    const entry = store.get(code);
    expect(entry).toBeDefined();
    expect(entry?.userId).toBe('alice');
    expect(entry?.pkceChallenge).toBe('my-challenge');
    expect(entry?.scopes).toEqual(['read', 'write']);
    expect(entry?.state).toBe('state-xyz');
  });

  it('returns undefined for an unknown code', () => {
    expect(store.get('does-not-exist')).toBeUndefined();
  });

  it('deletes a code so it cannot be retrieved again', () => {
    const code = store.store({
      pkceChallenge: 'ch',
      clientId: 'c',
      scopes: [],
      redirectUri: 'https://r.com',
      userId: 'u',
    });
    store.delete(code);
    expect(store.get(code)).toBeUndefined();
  });

  it('stores each code with a unique ID', () => {
    const params = {
      pkceChallenge: 'ch',
      clientId: 'c',
      scopes: [],
      redirectUri: 'https://r.com',
      userId: 'u',
    };
    const a = store.store(params);
    const b = store.store(params);
    expect(a).not.toBe(b);
  });
});

// ---- RefreshStore ----

describe('RefreshStore', () => {
  let store: RefreshStore;

  beforeEach(() => {
    store = new RefreshStore();
  });

  it('stores and retrieves a refresh token entry', () => {
    const token = store.store({ userId: 'bob', clientId: 'c1', scopes: ['read'] });
    expect(typeof token).toBe('string');
    const entry = store.get(token);
    expect(entry?.userId).toBe('bob');
    expect(entry?.clientId).toBe('c1');
    expect(entry?.scopes).toEqual(['read']);
  });

  it('returns undefined for an unknown token', () => {
    expect(store.get('bad-token')).toBeUndefined();
  });

  it('deletes a token so it cannot be retrieved again', () => {
    const token = store.store({ userId: 'u', clientId: 'c', scopes: [] });
    store.delete(token);
    expect(store.get(token)).toBeUndefined();
  });

  it('stores each token with a unique ID', () => {
    const params = { userId: 'u', clientId: 'c', scopes: [] };
    const a = store.store(params);
    const b = store.store(params);
    expect(a).not.toBe(b);
  });
});

// ---- Persistence helpers setup ----

const mockReadFileSync = vi.mocked(fs.readFileSync);
const mockWriteFile = vi.mocked(fs.writeFile);
const mockMkdirSync = vi.mocked(fs.mkdirSync);

function setupFsMocks() {
  vi.resetAllMocks();
  mockMkdirSync.mockReturnValue(undefined as unknown as string);
  // Default: file not found
  mockReadFileSync.mockImplementation(() => {
    const err = new Error('ENOENT') as NodeJS.ErrnoException;
    err.code = 'ENOENT';
    throw err;
  });
  // Default: writeFile succeeds (calls cb with null)
  mockWriteFile.mockImplementation((_path: unknown, _data: unknown, _enc: unknown, cb: unknown) => {
    (cb as (err: null) => void)(null);
  });
}

// ---- ClientsStore — persistence ----

describe('ClientsStore — persistence', () => {
  const TEST_STATE_DIR = '/tmp/test-state';

  beforeEach(() => {
    setupFsMocks();
    process.env.MCP_AUTH_STATE_DIR = TEST_STATE_DIR;
  });

  afterEach(() => {
    delete process.env.MCP_AUTH_STATE_DIR;
    delete process.env.MCP_REGISTRATION_ENABLED;
    delete process.env.MCP_CLIENT_ID;
  });

  it('loads clients from file on construction', () => {
    const persistedClient = {
      client_id: 'persisted-id',
      client_id_issued_at: 1700000000,
      redirect_uris: ['https://example.com/cb'],
    };
    mockReadFileSync.mockReturnValue(JSON.stringify([persistedClient]));

    const store = new ClientsStore({ stateFile: `${TEST_STATE_DIR}/clients.json` });
    expect(store.getClient('persisted-id')).toMatchObject({ client_id: 'persisted-id' });
  });

  it('is silent on ENOENT — no warning logged, no writeFile called', () => {
    // readFileSync already throws ENOENT by default in setupFsMocks
    const store = new ClientsStore({ stateFile: `${TEST_STATE_DIR}/clients.json` });
    expect(store.getClient('anything')).toBeUndefined();
    expect(mockWriteFile).not.toHaveBeenCalled();
  });

  it('warns and starts fresh on corrupt JSON', () => {
    mockReadFileSync.mockReturnValue('not-valid-json{{');

    const store = new ClientsStore({ stateFile: `${TEST_STATE_DIR}/clients.json` });
    expect(store.getClient('anything')).toBeUndefined();
    expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
      expect.objectContaining({ file: `${TEST_STATE_DIR}/clients.json` }),
      expect.stringContaining('[state]')
    );
  });

  it('pre-seeded client overrides persisted client with same id', () => {
    const persisted = {
      client_id: 'same-id',
      client_id_issued_at: 1000,
      redirect_uris: ['https://old.example.com/cb'],
      client_name: 'Old Name',
    };
    mockReadFileSync.mockReturnValue(JSON.stringify([persisted]));

    const preseeded = {
      client_id: 'same-id',
      client_id_issued_at: 9999,
      redirect_uris: ['https://new.example.com/cb'],
      client_name: 'New Name',
    };
    const store = new ClientsStore({
      preseededClients: [preseeded],
      stateFile: `${TEST_STATE_DIR}/clients.json`,
    });
    expect(store.getClient('same-id')?.client_name).toBe('New Name');
  });

  it('writes file after dynamic registration with correct path and content', () => {
    const stateFile = `${TEST_STATE_DIR}/clients.json`;
    const store = new ClientsStore({
      enableDynamicRegistration: true,
      stateFile,
    });

    const client = store.registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'Test App',
    });

    expect(mockWriteFile).toHaveBeenCalledWith(
      stateFile,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );

    const written = JSON.parse(
      (mockWriteFile.mock.calls[0] as [string, string, string, () => void])[1]
    ) as unknown[];
    expect(written).toHaveLength(1);
    expect((written[0] as { client_id: string }).client_id).toBe(client.client_id);
  });

  it('does NOT write file when stateFile is not set', () => {
    const store = new ClientsStore({ enableDynamicRegistration: true });
    store.registerClient!({ redirect_uris: [new URL('https://example.com/cb')] });
    expect(mockWriteFile).not.toHaveBeenCalled();
  });

  it('fromEnv() sets stateFile when MCP_REGISTRATION_ENABLED=true', () => {
    process.env.MCP_REGISTRATION_ENABLED = 'true';

    const store = ClientsStore.fromEnv();
    store.registerClient!({ redirect_uris: [new URL('https://example.com/cb')] });

    expect(mockWriteFile).toHaveBeenCalledWith(
      `${TEST_STATE_DIR}/clients.json`,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );
  });
});

// ---- RefreshStore — persistence ----

describe('RefreshStore — persistence', () => {
  const TEST_STATE_DIR = '/tmp/test-state';
  const REFRESH_TTL_MS = 24 * 60 * 60 * 1000;

  beforeEach(() => {
    setupFsMocks();
    process.env.MCP_AUTH_STATE_DIR = TEST_STATE_DIR;
  });

  afterEach(() => {
    delete process.env.MCP_AUTH_STATE_DIR;
    vi.useRealTimers();
  });

  it('loads valid tokens from file on construction', () => {
    const futureExpiry = Date.now() + REFRESH_TTL_MS;
    const persistedTokens = {
      'token-abc': { userId: 'alice', clientId: 'c1', scopes: ['read'], expiresAt: futureExpiry },
    };
    mockReadFileSync.mockReturnValue(JSON.stringify(persistedTokens));

    const store = new RefreshStore(`${TEST_STATE_DIR}/refresh-tokens.json`);
    const entry = store.get('token-abc');
    expect(entry?.userId).toBe('alice');
    expect(entry?.clientId).toBe('c1');
  });

  it('prunes expired tokens on load', () => {
    const pastExpiry = Date.now() - 1000;
    const persistedTokens = {
      'expired-token': {
        userId: 'bob',
        clientId: 'c2',
        scopes: [],
        expiresAt: pastExpiry,
      },
    };
    mockReadFileSync.mockReturnValue(JSON.stringify(persistedTokens));

    const store = new RefreshStore(`${TEST_STATE_DIR}/refresh-tokens.json`);
    expect(store.get('expired-token')).toBeUndefined();
  });

  it('writes file after store()', () => {
    const stateFile = `${TEST_STATE_DIR}/refresh-tokens.json`;
    const store = new RefreshStore(stateFile);

    store.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });

    expect(mockWriteFile).toHaveBeenCalledWith(
      stateFile,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );
  });

  it('writes file after delete() — deleted token absent from written content', () => {
    const stateFile = `${TEST_STATE_DIR}/refresh-tokens.json`;
    const store = new RefreshStore(stateFile);

    const token = store.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });
    vi.mocked(mockWriteFile).mockClear();

    store.delete(token);

    expect(mockWriteFile).toHaveBeenCalledWith(
      stateFile,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );

    const written = JSON.parse(
      (mockWriteFile.mock.calls[0] as [string, string, string, () => void])[1]
    ) as Record<string, unknown>;
    expect(written[token]).toBeUndefined();
  });

  it('writes file on lazy expiry in get()', () => {
    vi.useFakeTimers();
    const stateFile = `${TEST_STATE_DIR}/refresh-tokens.json`;
    const store = new RefreshStore(stateFile);

    const token = store.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });
    vi.mocked(mockWriteFile).mockClear();

    // Advance time past 24h TTL
    vi.advanceTimersByTime(25 * 60 * 60 * 1000);

    const result = store.get(token);
    expect(result).toBeUndefined();
    expect(mockWriteFile).toHaveBeenCalledWith(
      stateFile,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );
  });

  it('does NOT write file when stateFile is not set', () => {
    const store = new RefreshStore(); // no stateFile
    store.store({ userId: 'alice', clientId: 'c1', scopes: ['read'] });
    expect(mockWriteFile).not.toHaveBeenCalled();
  });

  it('handles writeFile error gracefully — logger.warn called, no throw', () => {
    const stateFile = `${TEST_STATE_DIR}/refresh-tokens.json`;
    mockWriteFile.mockImplementation(
      (_path: unknown, _data: unknown, _enc: unknown, cb: unknown) => {
        (cb as (err: Error) => void)(new Error('disk full'));
      }
    );

    const store = new RefreshStore(stateFile);
    expect(() => store.store({ userId: 'alice', clientId: 'c1', scopes: [] })).not.toThrow();
    expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
      expect.objectContaining({ file: stateFile }),
      expect.stringContaining('[state]')
    );
  });

  it('fromEnv() wires up the state file', () => {
    const store = RefreshStore.fromEnv();
    store.store({ userId: 'alice', clientId: 'c1', scopes: [] });

    expect(mockWriteFile).toHaveBeenCalledWith(
      `${TEST_STATE_DIR}/refresh-tokens.json`,
      expect.any(String),
      'utf8',
      expect.any(Function)
    );
  });
});
