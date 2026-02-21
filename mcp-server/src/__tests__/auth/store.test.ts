import { describe, it, expect, beforeEach } from 'vitest';
import { ClientsStore, CodeStore, RefreshStore } from '../../auth/store.js';

// ---- ClientsStore ----

describe('ClientsStore', () => {
  let store: ClientsStore;

  beforeEach(() => {
    store = new ClientsStore();
  });

  it('returns undefined for an unknown client', () => {
    expect(store.getClient('unknown')).toBeUndefined();
  });

  it('registers a client and retrieves it by id', () => {
    const client = store.registerClient({
      redirect_uris: [new URL('https://example.com/callback')],
    });
    expect(client.client_id).toBeDefined();
    expect(client.client_id_issued_at).toBeCloseTo(Math.floor(Date.now() / 1000), -2);
    expect(store.getClient(client.client_id)).toEqual(client);
  });

  it('assigns a unique client_id on each registration', () => {
    const a = store.registerClient({ redirect_uris: [new URL('https://a.com/cb')] });
    const b = store.registerClient({ redirect_uris: [new URL('https://b.com/cb')] });
    expect(a.client_id).not.toBe(b.client_id);
  });

  it('preserves all metadata fields', () => {
    const client = store.registerClient({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'My App',
      scope: 'read write',
    });
    expect(store.getClient(client.client_id)?.client_name).toBe('My App');
    expect(store.getClient(client.client_id)?.scope).toBe('read write');
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
