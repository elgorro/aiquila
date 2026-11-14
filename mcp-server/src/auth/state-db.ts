// SPDX-License-Identifier: MIT

// The OAuth state database: registered clients, refresh tokens, revocations
// and sealed Nextcloud credentials in one SQLite file (node:sqlite) under the
// state directory. Every change is a row-level write, so a refresh no longer
// rewrites the whole state, and WAL mode lets several server processes share
// the file on a local volume.

import { createHash, randomUUID } from 'node:crypto';
import { closeSync, existsSync, openSync, readFileSync, renameSync } from 'node:fs';
import { join } from 'node:path';
import { DatabaseSync, type SQLInputValue } from 'node:sqlite';
import { logger } from '../logger.js';

export const STATE_DB_FILE = 'state.db';

/** The JSON files earlier versions kept, imported once and then renamed `*.migrated`. */
export const LEGACY_FILES = {
  clients: 'clients.json',
  refreshTokens: 'refresh-tokens.json',
  revocations: 'revoked.json',
  credentials: 'credentials.json',
} as const;

// Each entry upgrades the schema by one version (PRAGMA user_version).
const MIGRATIONS: string[] = [
  `CREATE TABLE clients (
     client_id    TEXT PRIMARY KEY,
     metadata     TEXT NOT NULL,
     issued_at    INTEGER,
     last_used_at INTEGER
   );
   CREATE TABLE refresh_tokens (
     token_hash TEXT PRIMARY KEY,
     grant_id   TEXT NOT NULL,
     user_id    TEXT NOT NULL,
     client_id  TEXT NOT NULL,
     scopes     TEXT NOT NULL,
     spent      INTEGER NOT NULL DEFAULT 0,
     secret_id  TEXT,
     expires_at INTEGER NOT NULL
   );
   CREATE INDEX refresh_tokens_grant ON refresh_tokens (grant_id);
   CREATE INDEX refresh_tokens_expiry ON refresh_tokens (expires_at);
   CREATE TABLE revocations (
     key        TEXT PRIMARY KEY,
     expires_at INTEGER NOT NULL
   );
   CREATE TABLE credentials (
     grant_id   TEXT PRIMARY KEY,
     user_id    TEXT NOT NULL,
     sealed     TEXT NOT NULL,
     expires_at INTEGER NOT NULL
   );
   CREATE INDEX credentials_expiry ON credentials (expires_at);`,
];

type Row = Record<string, SQLInputValue>;

// The loud "state not writable" warning is emitted once per process; later
// failures go to debug so a broken volume does not flood the log.
let warnedUnwritable = false;

/** Marks the warning as already emitted, e.g. by the startup probe. */
export function markStateUnwritableWarned(): void {
  warnedUnwritable = true;
}

/**
 * Operator-facing remediation for an unwritable state directory. The fix is to
 * recreate the (root-owned) Docker named volume so a fresh one inherits the
 * image's node ownership — `docker compose exec ... chown` cannot be used here
 * because the container would otherwise be in a crash loop. See discussion #342.
 */
export function stateUnwritableMessage(dir: string, code?: string): string {
  return (
    `State directory ${dir} is not writable${code ? ` (${code})` : ''} — ` +
    `OAuth tokens will NOT persist; clients must re-authenticate after every restart. ` +
    `To restore persistence, recreate the state volume (this clears existing tokens; ` +
    `clients re-authenticate once):\n` +
    `  docker compose down mcp && docker volume rm <project>_mcp_state && docker compose up -d mcp\n` +
    `See https://github.com/elgorro/aiquila/discussions/342`
  );
}

function warnUnwritable(dir: string, code: string | undefined, err: unknown): void {
  if (warnedUnwritable) {
    logger.debug({ dir, code, err }, '[state] write failed — state not writable');
    return;
  }
  warnedUnwritable = true;
  logger.warn({ dir, code }, stateUnwritableMessage(dir, code));
}

// Primary SQLite result codes that mean the storage failed, not the query:
// BUSY, LOCKED, READONLY, IOERR, FULL, CANTOPEN (sqlite.org/rescode.html).
const STORAGE_ERRORS = new Set([5, 6, 8, 10, 13, 14]);

function isStorageError(err: unknown): boolean {
  const e = err as { code?: string; errcode?: number };
  // Extended result codes carry the primary code in their low byte.
  return e.code === 'ERR_SQLITE_ERROR' && STORAGE_ERRORS.has((e.errcode ?? 0) & 0xff);
}

function errorCode(err: unknown): string | undefined {
  const e = err as { code?: string; errcode?: number; errstr?: string };
  return e.code === 'ERR_SQLITE_ERROR' ? (e.errstr ?? e.code) : e.code;
}

export class StateDb {
  private constructor(
    private readonly db: DatabaseSync,
    /** The directory of the file, or undefined for an in-memory database. */
    readonly dir: string | undefined
  ) {}

  /** True when the state survives a restart. */
  get persistent(): boolean {
    return this.dir !== undefined;
  }

  /** A database that lives as long as the process: tests, and the fallback. */
  static memory(): StateDb {
    const db = new DatabaseSync(':memory:');
    migrate(db);
    return new StateDb(db, undefined);
  }

  /**
   * Opens `<dir>/state.db`, creating it (mode 0600: it holds sealed app
   * passwords) and importing the JSON files of earlier versions. When the
   * directory cannot be written, the server keeps running on an in-memory
   * database and warns how to fix the volume, as it always has.
   */
  static open(dir: string): StateDb {
    const file = join(dir, STATE_DB_FILE);
    let db: DatabaseSync | undefined;
    try {
      if (!existsSync(file)) closeSync(openSync(file, 'a', 0o600));
      db = new DatabaseSync(file);
      // WAL lets readers and one writer work at once, across processes too;
      // NORMAL is durable at every checkpoint, which is enough for this state.
      db.exec(
        'PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA busy_timeout = 5000;'
      );
      migrate(db);
      importLegacyFiles(db, dir);
      return new StateDb(db, dir);
    } catch (err) {
      try {
        db?.close();
      } catch {
        /* ignore */
      }
      warnUnwritable(dir, errorCode(err), err);
      return StateDb.memory();
    }
  }

  all<T>(sql: string, ...params: SQLInputValue[]): T[] {
    return this.db.prepare(sql).all(...params) as T[];
  }

  get<T>(sql: string, ...params: SQLInputValue[]): T | undefined {
    return this.db.prepare(sql).get(...params) as T | undefined;
  }

  /**
   * Runs a write and returns the number of rows it changed. A storage
   * failure (disk full, volume turned read-only) is logged and reported as 0
   * rather than failing the request: the change is lost, as it would have
   * been in memory at the next restart. Any other error is a bug and throws.
   */
  run(sql: string, ...params: SQLInputValue[]): number {
    try {
      return Number(this.db.prepare(sql).run(...params).changes);
    } catch (err) {
      if (!isStorageError(err)) throw err;
      warnUnwritable(this.dir ?? ':memory:', errorCode(err), err);
      return 0;
    }
  }

  /** Runs `fn` in one transaction, so a multi-row change is all or nothing. */
  transaction<T>(fn: () => T): T {
    this.db.exec('BEGIN IMMEDIATE');
    try {
      const out = fn();
      this.db.exec('COMMIT');
      return out;
    } catch (err) {
      this.db.exec('ROLLBACK');
      throw err;
    }
  }

  close(): void {
    this.db.close();
  }
}

function migrate(db: DatabaseSync): void {
  const { user_version: version } = db.prepare('PRAGMA user_version').get() as {
    user_version: number;
  };
  for (let v = version; v < MIGRATIONS.length; v++) {
    db.exec('BEGIN IMMEDIATE');
    try {
      db.exec(MIGRATIONS[v]);
      db.exec(`PRAGMA user_version = ${v + 1}`);
      db.exec('COMMIT');
    } catch (err) {
      db.exec('ROLLBACK');
      throw err;
    }
  }
}

function readLegacy<T>(dir: string, name: string): T | undefined {
  const path = join(dir, name);
  if (!existsSync(path)) return undefined;
  try {
    return JSON.parse(readFileSync(path, 'utf8')) as T;
  } catch (err) {
    logger.warn({ file: path, err }, '[state] Could not read a legacy state file — skipping it');
    return undefined;
  }
}

const HASHED_KEY = /^[0-9a-f]{64}$/;

function insert(db: DatabaseSync, table: string, row: Row): void {
  const cols = Object.keys(row);
  db.prepare(
    `INSERT OR REPLACE INTO ${table} (${cols.join(', ')}) VALUES (${cols.map(() => '?').join(', ')})`
  ).run(...Object.values(row));
}

/**
 * Imports the JSON state files of earlier versions in one transaction, then
 * renames each to `<name>.migrated` so it is not read again. The stores apply
 * their load-time rules (expiry, MCP_AUTH_SECRET rotation, redirect policy)
 * to the imported rows like to any other.
 */
function importLegacyFiles(db: DatabaseSync, dir: string): void {
  const present = Object.values(LEGACY_FILES).filter((f) => existsSync(join(dir, f)));
  if (present.length === 0) return;

  const counts: Record<string, number> = {};
  db.exec('BEGIN IMMEDIATE');
  try {
    const clients = readLegacy<(Row & { client_id?: string; last_used_at?: number })[]>(
      dir,
      LEGACY_FILES.clients
    );
    for (const c of Array.isArray(clients) ? clients : []) {
      if (typeof c.client_id !== 'string') continue;
      const { last_used_at: lastUsed, ...metadata } = c;
      insert(db, 'clients', {
        client_id: c.client_id,
        metadata: JSON.stringify(metadata),
        issued_at:
          typeof metadata.client_id_issued_at === 'number' ? metadata.client_id_issued_at : null,
        last_used_at: typeof lastUsed === 'number' ? lastUsed : null,
      });
      counts.clients = (counts.clients ?? 0) + 1;
    }

    const tokens = readLegacy<Record<string, Record<string, unknown>>>(
      dir,
      LEGACY_FILES.refreshTokens
    );
    for (const [key, e] of Object.entries(tokens ?? {})) {
      if (!e || typeof e.expiresAt !== 'number' || typeof e.userId !== 'string') continue;
      insert(db, 'refresh_tokens', {
        // Files from before hashing keyed entries by the token itself.
        token_hash: HASHED_KEY.test(key) ? key : createHash('sha256').update(key).digest('hex'),
        // Entries from before grants existed get one of their own.
        grant_id: typeof e.grantId === 'string' ? e.grantId : randomUUID(),
        user_id: e.userId,
        client_id: String(e.clientId ?? ''),
        scopes: JSON.stringify(Array.isArray(e.scopes) ? e.scopes : []),
        spent: e.spent ? 1 : 0,
        secret_id: typeof e.secretId === 'string' ? e.secretId : null,
        expires_at: e.expiresAt,
      });
      counts.refreshTokens = (counts.refreshTokens ?? 0) + 1;
    }

    const revoked = readLegacy<Record<string, unknown>>(dir, LEGACY_FILES.revocations);
    for (const [key, expiresAt] of Object.entries(revoked ?? {})) {
      if (typeof expiresAt !== 'number') continue;
      insert(db, 'revocations', { key, expires_at: expiresAt });
      counts.revocations = (counts.revocations ?? 0) + 1;
    }

    const creds = readLegacy<Record<string, Record<string, unknown>>>(
      dir,
      LEGACY_FILES.credentials
    );
    for (const [grantId, e] of Object.entries(creds ?? {})) {
      if (
        !e ||
        typeof e.userId !== 'string' ||
        typeof e.sealed !== 'string' ||
        typeof e.expiresAt !== 'number'
      ) {
        continue;
      }
      insert(db, 'credentials', {
        grant_id: grantId,
        user_id: e.userId,
        sealed: e.sealed,
        expires_at: e.expiresAt,
      });
      counts.credentials = (counts.credentials ?? 0) + 1;
    }
    db.exec('COMMIT');
  } catch (err) {
    db.exec('ROLLBACK');
    throw err;
  }

  for (const f of present) renameSync(join(dir, f), join(dir, `${f}.migrated`));
  logger.info(
    { counts, files: present },
    '[state] Imported JSON state into state.db; the old files were renamed *.migrated'
  );
}

let shared: StateDb | undefined;
let sharedDir: string | undefined;

/**
 * The process-wide state database for `dir`. The stores' fromEnv() share it,
 * so the server holds one connection.
 */
export function sharedStateDb(dir: string): StateDb {
  if (!shared || sharedDir !== dir) {
    shared = StateDb.open(dir);
    sharedDir = dir;
  }
  return shared;
}

/** Forgets the shared database; tests use it between cases. */
export function resetSharedStateDb(): void {
  try {
    shared?.close();
  } catch {
    /* ignore */
  }
  shared = undefined;
  sharedDir = undefined;
}
