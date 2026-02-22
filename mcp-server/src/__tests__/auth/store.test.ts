import { describe, it, expect, beforeEach, afterEach } from 'vitest';
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

  it('pre-seeds a client when MCP_CLIENT_ID and MCP_CLIENT_SECRET are set', () => {
    process.env.MCP_CLIENT_ID = 'env-client-id';
    process.env.MCP_CLIENT_SECRET = 'env-client-secret';
    const store = ClientsStore.fromEnv();
    const client = store.getClient('env-client-id');
    expect(client).toBeDefined();
    expect(client?.client_id).toBe('env-client-id');
    expect(client?.client_secret).toBe('env-client-secret');
    expect(client?.client_secret_expires_at).toBe(0);
    expect(client?.token_endpoint_auth_method).toBe('client_secret_post');
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

  it('does not pre-seed if only MCP_CLIENT_ID is set (secret missing)', () => {
    process.env.MCP_CLIENT_ID = 'only-id';
    const store = ClientsStore.fromEnv();
    expect(store.getClient('only-id')).toBeUndefined();
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
