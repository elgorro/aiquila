// SPDX-License-Identifier: MIT
//
// End-to-end persistence tests on the *real* filesystem (no fs mock): OAuth
// state lives in <state dir>/state.db, survives a restart, imports the JSON
// files of earlier versions once, and an unwritable state directory degrades
// gracefully instead of crashing (discussion #342).

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  existsSync,
  mkdtempSync,
  rmSync,
  chmodSync,
  readFileSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

vi.mock('../../logger.js', () => ({
  logger: {
    warn: vi.fn(),
    info: vi.fn(),
    error: vi.fn(),
    debug: vi.fn(),
  },
}));

import { logger } from '../../logger.js';
import { ClientsStore, RefreshStore, RevocationList } from '../../auth/store.js';
import { CredentialStore, LocalCipher } from '../../auth/credential-store.js';
import { StateDb, STATE_DB_FILE } from '../../auth/state-db.js';

const sha256 = (s: string) => createHash('sha256').update(s).digest('hex');

describe('state persistence (real filesystem)', () => {
  let dir: string;
  const open: StateDb[] = [];

  /** Opens the state database the way a (re)starting server does. */
  function boot(): StateDb {
    const db = StateDb.open(dir);
    open.push(db);
    return db;
  }

  beforeEach(() => {
    vi.clearAllMocks();
    dir = mkdtempSync(join(tmpdir(), 'aiquila-persist-'));
  });

  afterEach(() => {
    for (const db of open.splice(0)) {
      try {
        db.close();
      } catch {
        /* already closed */
      }
    }
    try {
      chmodSync(dir, 0o700);
    } catch {
      /* ignore */
    }
    rmSync(dir, { recursive: true, force: true });
  });

  it('RefreshStore keeps a token across a restart', () => {
    const token = new RefreshStore(boot()).store({
      grantId: 'g',
      userId: 'alice',
      clientId: 'c1',
      scopes: ['read'],
    });
    const entry = new RefreshStore(boot()).get(token);
    expect(entry).toMatchObject({
      grantId: 'g',
      userId: 'alice',
      clientId: 'c1',
      scopes: ['read'],
    });
  });

  it('stores no usable token, only its SHA-256, in a file only its owner can read', () => {
    const token = new RefreshStore(boot()).store({
      grantId: 'g',
      userId: 'alice',
      clientId: 'c1',
      scopes: [],
    });
    const file = join(dir, STATE_DB_FILE);
    expect(statSync(file).mode & 0o777).toBe(0o600);
    const raw = new DatabaseSync(file, { readOnly: true });
    const rows = raw.prepare('SELECT token_hash FROM refresh_tokens').all();
    raw.close();
    expect(rows).toEqual([{ token_hash: sha256(token) }]);
    expect(readFileSync(file).includes(Buffer.from(token))).toBe(false);
  });

  it('a deletion is seen by the next instance', () => {
    const first = new RefreshStore(boot());
    const token = first.store({ grantId: 'g', userId: 'bob', clientId: 'c2', scopes: [] });
    first.delete(token);
    expect(new RefreshStore(boot()).get(token)).toBeUndefined();
  });

  it('drops tokens issued under a previous MCP_AUTH_SECRET', () => {
    const saved = process.env.MCP_AUTH_SECRET;
    try {
      process.env.MCP_AUTH_SECRET = 'a'.repeat(64);
      const token = new RefreshStore(boot()).store({
        grantId: 'g',
        userId: 'alice',
        clientId: 'c1',
        scopes: [],
      });
      expect(new RefreshStore(boot()).get(token)?.userId).toBe('alice');

      process.env.MCP_AUTH_SECRET = 'b'.repeat(64);
      expect(new RefreshStore(boot()).get(token)).toBeUndefined();
    } finally {
      process.env.MCP_AUTH_SECRET = saved;
    }
  });

  it('ClientsStore keeps a registered client across a restart', async () => {
    const client = new ClientsStore({ enableDynamicRegistration: true, db: boot() })
      .registerClient!({
      redirect_uris: [new URL('https://example.com/cb')],
      client_name: 'Round Trip App',
    });
    const second = new ClientsStore({ enableDynamicRegistration: true, db: boot() });
    expect(await second.getClient(client.client_id)).toMatchObject({
      client_id: client.client_id,
      client_name: 'Round Trip App',
      redirect_uris: ['https://example.com/cb'],
    });
  });

  it('RevocationList keeps a revocation across a restart until it expires', () => {
    new RevocationList(boot()).revokeGrant('g', Date.now() + 60_000);
    new RevocationList(boot()).revokeAccessToken('old', Date.now() - 1);
    const reloaded = new RevocationList(boot());
    expect(reloaded.isGrantRevoked('g')).toBe(true);
    expect(reloaded.isAccessTokenRevoked('old')).toBe(false);
  });

  it('two instances on one file see each other’s writes, and only one can spend a token', () => {
    // Two server processes sharing the state volume.
    const a = new RefreshStore(boot());
    const b = new RefreshStore(boot());
    const token = a.store({ grantId: 'g', userId: 'alice', clientId: 'c1', scopes: [] });
    expect(b.get(token)?.userId).toBe('alice');
    expect(a.markSpent(token)).toBe(true);
    expect(b.markSpent(token)).toBe(false);
    expect(b.get(token)?.spent).toBe(true);
  });

  describe('import of the JSON files of earlier versions', () => {
    it('imports every file once, applies the load rules, and renames them *.migrated', async () => {
      const legacyToken = '3f2b8c1e-7d4a-4e5f-9a6b-0c1d2e3f4a5b';
      const hashedToken = 'x'.repeat(43);
      writeFileSync(
        join(dir, 'refresh-tokens.json'),
        JSON.stringify({
          // Keyed by the token itself, from before hashing; no grant, no secret.
          [legacyToken]: {
            userId: 'alice',
            clientId: 'c1',
            scopes: [],
            expiresAt: Date.now() + 60_000,
          },
          [sha256(hashedToken)]: {
            grantId: 'g2',
            userId: 'bob',
            clientId: 'c1',
            scopes: ['read'],
            expiresAt: Date.now() + 60_000,
          },
          [sha256('gone')]: {
            grantId: 'g3',
            userId: 'x',
            clientId: 'c1',
            scopes: [],
            expiresAt: 1,
          },
        })
      );
      writeFileSync(
        join(dir, 'clients.json'),
        JSON.stringify([
          {
            client_id: 'reg-1',
            client_id_issued_at: 1,
            redirect_uris: ['https://claude.ai/cb'],
            client_name: 'Old App',
            last_used_at: Math.floor(Date.now() / 1000),
          },
        ])
      );
      writeFileSync(
        join(dir, 'revoked.json'),
        JSON.stringify({ 'grant:old': Date.now() + 60_000 })
      );
      const cipher = new LocalCipher(Buffer.alloc(64, 7));
      // What 0.5.x wrote: the sealed value, as the old file kept it.
      const sealed = await cipher.encrypt(
        Buffer.from(JSON.stringify({ loginName: 'bob', appPassword: 'pw' })),
        Buffer.from(JSON.stringify(['aiquila-credential-v1', 'g2', 'bob']))
      );
      writeFileSync(
        join(dir, 'credentials.json'),
        JSON.stringify({ g2: { userId: 'bob', sealed, expiresAt: Date.now() + 60_000 } })
      );

      const db = boot();
      const refresh = new RefreshStore(db);
      expect(refresh.get(legacyToken)).toMatchObject({
        userId: 'alice',
        grantId: expect.any(String),
      });
      expect(refresh.get(legacyToken)?.secretId).toMatch(/^[0-9a-f]{16}$/);
      expect(refresh.get(hashedToken)).toMatchObject({ grantId: 'g2', scopes: ['read'] });
      const clients = new ClientsStore({ enableDynamicRegistration: true, db });
      expect(await clients.getClient('reg-1')).toMatchObject({ client_name: 'Old App' });
      expect(await clients.getClient('reg-1')).not.toHaveProperty('last_used_at');
      // It was in use, so it is not expired as never used.
      expect(
        new ClientsStore({ db, unusedTtlMs: 1000 }).expireClients(new Set(), Date.now())
      ).toEqual([]);
      expect(new RevocationList(db).isGrantRevoked('old')).toBe(true);
      expect(await new CredentialStore(cipher, db).get('g2', 'bob')).toMatchObject({
        appPassword: 'pw',
      });

      for (const f of ['refresh-tokens.json', 'clients.json', 'revoked.json', 'credentials.json']) {
        expect(existsSync(join(dir, f))).toBe(false);
        expect(existsSync(join(dir, `${f}.migrated`))).toBe(true);
      }
      expect(vi.mocked(logger.info)).toHaveBeenCalledWith(
        expect.objectContaining({
          counts: { clients: 1, refreshTokens: 3, revocations: 1, credentials: 1 },
        }),
        expect.stringContaining('Imported JSON state')
      );

      // A second start does not import again.
      vi.mocked(logger.info).mockClear();
      boot();
      expect(vi.mocked(logger.info)).not.toHaveBeenCalledWith(
        expect.anything(),
        expect.stringContaining('Imported JSON state')
      );
    });

    it('skips an unreadable file and still imports the rest', () => {
      writeFileSync(join(dir, 'clients.json'), '{not json');
      writeFileSync(join(dir, 'revoked.json'), JSON.stringify({ 'jti:a': Date.now() + 60_000 }));
      const db = boot();
      expect(new RevocationList(db).isAccessTokenRevoked('a')).toBe(true);
      expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
        expect.objectContaining({ file: join(dir, 'clients.json') }),
        expect.stringContaining('legacy state file')
      );
    });
  });

  it('degrades gracefully when the state dir is read-only — no throw, in-memory still works, warns with #342', () => {
    if (process.getuid?.() === 0) {
      // root bypasses permission checks — skip
      return;
    }
    chmodSync(dir, 0o500); // read + execute, no write

    const db = boot();
    expect(db.persistent).toBe(false);
    const store = new RefreshStore(db);
    let token = '';
    expect(() => {
      token = store.store({ grantId: 'g', userId: 'alice', clientId: 'c1', scopes: ['read'] });
    }).not.toThrow();

    // The token is unusable across a restart, but works in-memory for this process.
    expect(store.get(token)?.userId).toBe('alice');

    expect(vi.mocked(logger.warn)).toHaveBeenCalledWith(
      expect.objectContaining({ code: expect.stringMatching(/EACCES|EPERM|EROFS/) }),
      expect.stringContaining('discussions/342')
    );
  });
});
