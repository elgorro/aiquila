// SPDX-License-Identifier: MIT

import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { writeFileSync, mkdirSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import {
  OAuthError,
  OAuthErrorCode,
  type OAuthClientInformationFull,
} from '@modelcontextprotocol/server';
import { ClientIdDocumentError, ClientIdDocumentResolver, looksLikeClientIdUrl } from './cimd.js';
import { RedirectUriPolicy } from './redirect-uri.js';
import { logger } from '../logger.js';
import { sharedStateDb, StateDb } from './state-db.js';

export interface CodeEntry {
  pkceChallenge: string;
  clientId: string;
  scopes: string[];
  redirectUri: string;
  userId: string;
  state?: string;
  // The grant the code starts. Its Nextcloud credential is already sealed in
  // the credential store under this id, so an app password outlives neither
  // the code nor a restart: the store's expiry sweep deletes it.
  grantId: string;
  // Set once the code has been exchanged. The entry is kept until it expires
  // so a replay can be recognised and the grant ended.
  redeemed?: boolean;
  expiresAt: number;
}

export interface RefreshEntry {
  // Identifies the authorization grant: every refresh token rotated from one
  // login, and every access token issued along the way, carries the same id.
  grantId: string;
  userId: string;
  clientId: string;
  scopes: string[];
  // Set once the token has been exchanged. A spent token is kept until it
  // would have expired, so presenting it again is recognised as reuse.
  spent?: boolean;
  // Fingerprint of the MCP_AUTH_SECRET the token was issued under. Rotating
  // the secret must end every session, and refresh tokens are not signed
  // with it, so this is what ties them to it.
  secretId?: string;
  expiresAt: number;
}

/** A short, one-way fingerprint of MCP_AUTH_SECRET. */
function authSecretId(): string {
  return createHash('sha256')
    .update(`aiquila-refresh-secret:${process.env.MCP_AUTH_SECRET ?? ''}`)
    .digest('hex')
    .slice(0, 16);
}

export const CODE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const DEFAULT_REFRESH_TTL_SECS = 30 * 24 * 60 * 60; // 30 days

// Spent refresh tokens kept per grant for reuse detection. Each rotation adds
// one; the oldest beyond this are dropped, so a long-lived grant does not
// grow the state file without bound. A dropped token still fails to redeem,
// it just no longer ends the grant when replayed.
const SPENT_TOKENS_PER_GRANT = 10;

/**
 * Refresh token lifetime in ms: MCP_REFRESH_TOKEN_TTL seconds, default 30
 * days. Sliding: every rotation issues a token with the full lifetime, so a
 * client that is used at least once per period stays signed in.
 */
export function refreshTtlMs(): number {
  const raw = process.env.MCP_REFRESH_TOKEN_TTL;
  if (raw !== undefined && raw !== '') {
    const secs = Number(raw);
    if (Number.isSafeInteger(secs) && secs > 0) return secs * 1000;
    logger.warn({ value: raw }, '[config] Ignoring invalid MCP_REFRESH_TOKEN_TTL');
  }
  return DEFAULT_REFRESH_TTL_SECS * 1000;
}

const DEFAULT_STATE_DIR = '/app/state';

export function stateDir(): string {
  return (process.env.MCP_AUTH_STATE_DIR ?? DEFAULT_STATE_DIR).replace(/\/+$/, '');
}

export { markStateUnwritableWarned, stateUnwritableMessage } from './state-db.js';

export function ensureDir(dir: string): void {
  try {
    mkdirSync(dir, { recursive: true });
  } catch (err) {
    // Best-effort: probeStateDir() (called from startup) is the authoritative
    // check. Logging here would spam non-HTTP transports that never persist.
    logger.debug({ dir, err }, '[state] mkdir failed (probe will report fatal if writes break)');
  }
}

/** The shared state database in the state directory, created on first use. */
function envStateDb(): StateDb {
  const dir = stateDir();
  ensureDir(dir);
  return sharedStateDb(dir);
}

export class StateDirNotWritableError extends Error {
  constructor(
    public readonly dir: string,
    public readonly cause: NodeJS.ErrnoException
  ) {
    super(
      `State directory ${dir} is not writable (${cause.code}). ` +
        `Fix volume ownership, then restart. For Docker named volumes:\n` +
        `  docker compose exec -u 0 mcp chown -R node:node ${dir}\n` +
        `  docker compose restart mcp`
    );
    this.name = 'StateDirNotWritableError';
  }
}

/**
 * Verifies the state directory is usable before the server starts accepting
 * requests. Writes and removes a sentinel file. Throws StateDirNotWritableError
 * on any filesystem permission / read-only / out-of-space failure so the
 * caller can log a fatal-level remediation message and exit.
 */
export function probeStateDir(dir: string = stateDir()): void {
  try {
    mkdirSync(dir, { recursive: true });
    const probe = join(dir, '.write-probe');
    writeFileSync(probe, String(process.pid), 'utf8');
    unlinkSync(probe);
  } catch (err) {
    const errno = err as NodeJS.ErrnoException;
    if (
      errno.code === 'EACCES' ||
      errno.code === 'EPERM' ||
      errno.code === 'EROFS' ||
      errno.code === 'ENOSPC'
    ) {
      throw new StateDirNotWritableError(dir, errno);
    }
    throw err;
  }
}

interface ClientsStoreOptions {
  preseededClients?: OAuthClientInformationFull[];
  // How long a registered client may go without obtaining a token, and how
  // long one that did may sit idle, before it is removed. Omitted → never.
  unusedTtlMs?: number;
  idleTtlMs?: number;
  enableDynamicRegistration?: boolean;
  // Where registered clients are kept. Omitted → an in-memory database.
  db?: StateDb;
  // Resolves https:// client_ids as Client ID Metadata Documents. Omitted →
  // such client_ids are unknown unless registered.
  clientIdDocuments?: ClientIdDocumentResolver;
  // Redirect URIs a registered client may use. Omitted → any.
  redirectPolicy?: RedirectUriPolicy;
}

/** The first redirect URI of `client` the policy rejects, with the reason. */
function rejectedRedirect(
  client: Pick<OAuthClientInformationFull, 'redirect_uris'>,
  policy: RedirectUriPolicy | undefined
): string | undefined {
  if (!policy) return undefined;
  for (const uri of client.redirect_uris ?? []) {
    const reason = policy.reject(String(uri));
    if (reason) return reason;
  }
  return undefined;
}

const DEFAULT_CLIENT_UNUSED_TTL_SECS = 24 * 60 * 60; // 1 day

/**
 * A client expiry from `name` in seconds, as ms. `0` turns it off (undefined);
 * an unset or invalid value falls back to `defaultSecs`.
 */
export function clientTtlMs(name: string, defaultSecs: number): number | undefined {
  const raw = process.env[name];
  let secs = defaultSecs;
  if (raw !== undefined && raw !== '') {
    const parsed = Number(raw);
    if (Number.isSafeInteger(parsed) && parsed >= 0) secs = parsed;
    else logger.warn({ name, value: raw }, '[config] Ignoring invalid client expiry');
  }
  return secs > 0 ? secs * 1000 : undefined;
}

// A client's last use is recorded to the hour, so a busy client does not
// write on every refresh.
const LAST_USED_GRANULARITY_SECS = 60 * 60;

interface ClientRow {
  client_id: string;
  metadata: string;
  issued_at: number | null;
  last_used_at: number | null;
}

export class ClientsStore {
  // Pre-seeded clients live in memory only: they come from the environment on
  // every start, and win over a registered client with the same id.
  private readonly preseeded = new Map<string, OAuthClientInformationFull>();
  private readonly db: StateDb;
  private readonly clientIdDocuments?: ClientIdDocumentResolver;
  private readonly unusedTtlMs?: number;
  private readonly idleTtlMs?: number;

  // Optional: POST /register is only mounted when this is defined.
  readonly registerClient?: (
    client: Omit<OAuthClientInformationFull, 'client_id' | 'client_id_issued_at'>
  ) => OAuthClientInformationFull;

  constructor(options?: ClientsStoreOptions) {
    this.db = options?.db ?? StateDb.memory();
    this.clientIdDocuments = options?.clientIdDocuments;
    this.unusedTtlMs = options?.unusedTtlMs;
    this.idleTtlMs = options?.idleTtlMs;
    const policy = options?.redirectPolicy;

    // The redirect policy applies to stored registrations too, so ones from
    // before it existed, or before it was narrowed, are dropped.
    if (policy) {
      for (const row of this.db.all<ClientRow>('SELECT * FROM clients')) {
        const reason = rejectedRedirect(
          JSON.parse(row.metadata) as OAuthClientInformationFull,
          policy
        );
        if (!reason) continue;
        logger.warn(
          { clientId: row.client_id, reason },
          '[auth] Dropping registered client: redirect URI not allowed'
        );
        this.db.run('DELETE FROM clients WHERE client_id = ?', row.client_id);
      }
    }

    for (const client of options?.preseededClients ?? []) {
      this.preseeded.set(client.client_id, client);
    }

    if (options?.enableDynamicRegistration) {
      this.registerClient = (client) => {
        const reason = rejectedRedirect(client, policy);
        if (reason) {
          // RFC 7591 §3.2.2
          throw new OAuthError(OAuthErrorCode.InvalidRedirectUri, reason);
        }
        const full: OAuthClientInformationFull = {
          ...client,
          client_id: randomUUID(),
          client_id_issued_at: Math.floor(Date.now() / 1000),
        };
        this.db.run(
          'INSERT INTO clients (client_id, metadata, issued_at) VALUES (?, ?, ?)',
          full.client_id,
          JSON.stringify(full),
          full.client_id_issued_at ?? null
        );
        return full;
      };
    }
  }

  /** True when https:// client_ids are resolved as Client ID Metadata Documents. */
  get clientIdMetadataDocumentsEnabled(): boolean {
    return this.clientIdDocuments !== undefined;
  }

  private registered(clientId: string): OAuthClientInformationFull | undefined {
    const row = this.db.get<ClientRow>(
      'SELECT metadata FROM clients WHERE client_id = ?',
      clientId
    );
    return row ? (JSON.parse(row.metadata) as OAuthClientInformationFull) : undefined;
  }

  /**
   * Looks up a pre-seeded or registered client, then — for an https:// client_id
   * — fetches its Client ID Metadata Document. Returns undefined for an unknown
   * client or a document that fails validation.
   */
  async getClient(clientId: string): Promise<OAuthClientInformationFull | undefined> {
    const known = this.preseeded.get(clientId) ?? this.registered(clientId);
    if (known) return known;
    if (!this.clientIdDocuments || !looksLikeClientIdUrl(clientId)) return undefined;
    try {
      return await this.clientIdDocuments.resolve(clientId);
    } catch (err) {
      if (!(err instanceof ClientIdDocumentError)) throw err;
      logger.warn({ clientId, reason: err.message }, '[auth] Client ID metadata document rejected');
      return undefined;
    }
  }

  /**
   * Records that a registered client obtained a token. Pre-seeded and
   * metadata-document clients are not stored, so they are left alone. The
   * time is kept to the hour, so a busy client does not write on every refresh.
   */
  markUsed(clientId: string, now = Date.now()): void {
    if (this.preseeded.has(clientId)) return;
    const nowSecs = Math.floor(now / 1000);
    this.db.run(
      `UPDATE clients SET last_used_at = ?
         WHERE client_id = ? AND (last_used_at IS NULL OR ? - last_used_at >= ?)`,
      nowSecs,
      clientId,
      nowSecs,
      LAST_USED_GRANULARITY_SECS
    );
  }

  /**
   * Removes registered clients that are of no further use (RFC 7591 leaves a
   * client's lifetime to the server, §3.2.1 `client_secret_expires_at` aside):
   * one that never obtained a token within `unusedTtlMs` of registering, and
   * one whose last token is older than `idleTtlMs`. A client that still holds
   * a live refresh token is kept either way, and counts as used: state from
   * before usage was tracked carries no record of it.
   *
   * @param active client_ids that hold a live refresh token
   * @returns the client_ids removed
   */
  expireClients(active: ReadonlySet<string>, now = Date.now()): string[] {
    const removed: string[] = [];
    const nowSecs = Math.floor(now / 1000);
    for (const row of this.db.all<ClientRow>(
      'SELECT client_id, issued_at, last_used_at FROM clients'
    )) {
      const clientId = row.client_id;
      if (this.preseeded.has(clientId)) continue;
      if (active.has(clientId)) {
        if (row.last_used_at === null) {
          this.db.run('UPDATE clients SET last_used_at = ? WHERE client_id = ?', nowSecs, clientId);
        }
        continue;
      }
      const expired =
        row.last_used_at === null
          ? this.unusedTtlMs !== undefined && (row.issued_at ?? 0) * 1000 + this.unusedTtlMs <= now
          : this.idleTtlMs !== undefined && row.last_used_at * 1000 + this.idleTtlMs <= now;
      if (expired && this.db.run('DELETE FROM clients WHERE client_id = ?', clientId) > 0) {
        removed.push(clientId);
      }
    }
    return removed;
  }

  /**
   * Creates a ClientsStore configured from environment variables:
   *   MCP_CLIENT_ID              → pre-seeded static public PKCE client (any MCP client)
   *   MCP_CLIENT_REDIRECT_URIS   → comma-separated redirect URIs for the pre-seeded client
   *   MCP_REGISTRATION_ENABLED=true → enable dynamic POST /register
   *   MCP_REDIRECT_ALLOWLIST     → extra redirect hosts / schemes registered clients may use
   *   MCP_CIMD_ENABLED           → resolve https:// client_ids as metadata documents;
   *                                defaults to on, off when MCP_REGISTRATION_TOKEN gates
   *                                registration (see cimdEnabledFromEnv)
   *   MCP_CLIENT_UNUSED_TTL      → seconds a registered client may go without a token
   *                                before it is removed (default 86400; 0 = never)
   *   MCP_CLIENT_IDLE_TTL        → seconds after its last token a registered client
   *                                without a live refresh token is removed (default never)
   *
   * Note: no client_secret is stored for the pre-seeded client — client
   * authentication requires the caller to send client_secret whenever
   * client.client_secret is set, which public OAuth clients never do. Security
   * is enforced via PKCE instead.
   */
  static fromEnv(): ClientsStore {
    const preseeded: OAuthClientInformationFull[] = [];
    const id = process.env.MCP_CLIENT_ID;

    if (id) {
      const rawUris = process.env.MCP_CLIENT_REDIRECT_URIS;
      const redirectUris = rawUris
        ? rawUris
            .split(',')
            .map((u) => u.trim())
            .filter(Boolean)
        : [];

      if (redirectUris.length === 0) {
        logger.warn(
          { clientId: id },
          '[config] MCP_CLIENT_ID is set but MCP_CLIENT_REDIRECT_URIS is empty — ' +
            'the pre-seeded client will not be able to complete the OAuth flow. ' +
            "Set MCP_CLIENT_REDIRECT_URIS to your client's callback URL, or enable " +
            'dynamic registration with MCP_REGISTRATION_ENABLED=true.'
        );
      }

      preseeded.push({
        client_id: id,
        client_id_issued_at: Math.floor(Date.now() / 1000),
        redirect_uris: redirectUris,
        token_endpoint_auth_method: 'none',
        grant_types: ['authorization_code', 'refresh_token'],
        response_types: ['code'],
        client_name: 'Pre-seeded client',
      });
    }

    // Registrations are only kept, and only read back, while registration is on.
    const registrationEnabled = process.env.MCP_REGISTRATION_ENABLED === 'true';

    return new ClientsStore({
      preseededClients: preseeded,
      enableDynamicRegistration: registrationEnabled,
      redirectPolicy: registrationEnabled ? RedirectUriPolicy.fromEnv() : undefined,
      db: registrationEnabled ? envStateDb() : undefined,
      clientIdDocuments: cimdEnabledFromEnv() ? new ClientIdDocumentResolver() : undefined,
      unusedTtlMs: clientTtlMs('MCP_CLIENT_UNUSED_TTL', DEFAULT_CLIENT_UNUSED_TTL_SECS),
      idleTtlMs: clientTtlMs('MCP_CLIENT_IDLE_TTL', 0),
    });
  }
}

/**
 * Whether Client ID Metadata Documents are accepted. MCP_CIMD_ENABLED decides
 * when set. Otherwise they are on — unless MCP_REGISTRATION_TOKEN is set: an
 * operator who gates registration has chosen who may be a client, and CIMD
 * would let any client in without the token.
 */
export function cimdEnabledFromEnv(): boolean {
  const flag = process.env.MCP_CIMD_ENABLED;
  if (flag === 'true') return true;
  if (flag === 'false') return false;
  return !process.env.MCP_REGISTRATION_TOKEN;
}

export class CodeStore {
  private readonly codes = new Map<string, CodeEntry>();

  store(entry: Omit<CodeEntry, 'expiresAt'>): string {
    const code = randomUUID();
    this.codes.set(code, { ...entry, expiresAt: Date.now() + CODE_TTL_MS });
    return code;
  }

  get(code: string): CodeEntry | undefined {
    const entry = this.codes.get(code);
    // An expired code stays until takeExpired() hands it over for clean-up.
    if (!entry || Date.now() > entry.expiresAt) return undefined;
    return entry;
  }

  /** Removes and returns every expired code. */
  takeExpired(now = Date.now()): CodeEntry[] {
    const out: CodeEntry[] = [];
    for (const [code, entry] of this.codes) {
      if (now > entry.expiresAt) {
        this.codes.delete(code);
        out.push(entry);
      }
    }
    return out;
  }

  delete(code: string): void {
    this.codes.delete(code);
  }

  /** Marks a code as exchanged. It no longer redeems, but is still recognised. */
  markRedeemed(code: string): void {
    const entry = this.codes.get(code);
    if (entry) entry.redeemed = true;
  }
}

/** The key a refresh token is stored under: its SHA-256, hex-encoded. */
function refreshTokenKey(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}

interface RefreshRow {
  token_hash: string;
  grant_id: string;
  user_id: string;
  client_id: string;
  scopes: string;
  spent: number;
  secret_id: string | null;
  expires_at: number;
}

function toEntry(row: RefreshRow): RefreshEntry {
  return {
    grantId: row.grant_id,
    userId: row.user_id,
    clientId: row.client_id,
    scopes: JSON.parse(row.scopes) as string[],
    ...(row.spent ? { spent: true } : {}),
    ...(row.secret_id !== null ? { secretId: row.secret_id } : {}),
    expiresAt: row.expires_at,
  };
}

/**
 * Refresh tokens, keyed by their SHA-256 hash so the database holds nothing a
 * reader could present as a token. The tokens are 256 random bits, so a plain
 * hash needs no salt or stretching.
 */
export class RefreshStore {
  private readonly db: StateDb;

  constructor(db: StateDb = StateDb.memory()) {
    this.db = db;
    const now = Date.now();
    const secretId = authSecretId();
    this.db.run('DELETE FROM refresh_tokens WHERE expires_at <= ?', now);
    // Issued under a previous MCP_AUTH_SECRET: rotating the secret ends the session.
    const dropped = this.db.run(
      'DELETE FROM refresh_tokens WHERE secret_id IS NOT NULL AND secret_id <> ?',
      secretId
    );
    // Imported from a version that did not record the secret yet.
    const upgraded = this.db.run(
      'UPDATE refresh_tokens SET secret_id = ? WHERE secret_id IS NULL',
      secretId
    );
    const loaded = this.db.get<{ n: number }>('SELECT COUNT(*) AS n FROM refresh_tokens')?.n ?? 0;
    if (loaded > 0 && this.db.persistent) {
      logger.info({ count: loaded }, '[state] Loaded refresh tokens');
    }
    if (dropped > 0) {
      logger.info(
        { count: dropped },
        '[state] Dropped refresh tokens issued under a previous MCP_AUTH_SECRET'
      );
    }
    if (upgraded > 0) logger.info({ count: upgraded }, '[state] Upgraded refresh token entries');
  }

  store(entry: Omit<RefreshEntry, 'expiresAt'>): string {
    const token = randomBytes(32).toString('base64url');
    this.db.run(
      `INSERT INTO refresh_tokens
         (token_hash, grant_id, user_id, client_id, scopes, spent, secret_id, expires_at)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?)`,
      refreshTokenKey(token),
      entry.grantId,
      entry.userId,
      entry.clientId,
      JSON.stringify(entry.scopes),
      authSecretId(),
      Date.now() + refreshTtlMs()
    );
    this.pruneSpent(entry.grantId);
    return token;
  }

  get(token: string): RefreshEntry | undefined {
    const key = refreshTokenKey(token);
    const row = this.db.get<RefreshRow>('SELECT * FROM refresh_tokens WHERE token_hash = ?', key);
    if (!row) return undefined;
    if (Date.now() > row.expires_at) {
      this.db.run('DELETE FROM refresh_tokens WHERE token_hash = ?', key);
      return undefined;
    }
    return toEntry(row);
  }

  delete(token: string): void {
    this.db.run('DELETE FROM refresh_tokens WHERE token_hash = ?', refreshTokenKey(token));
  }

  /**
   * Marks a token as exchanged. It no longer redeems, but is still recognised.
   * Returns true only for the call that spent it: of two requests racing to
   * redeem one token, in this process or another sharing the database, one
   * gets false and must treat it as reuse. A storage failure throws
   * StateWriteError instead, so it never reads as reuse.
   */
  markSpent(token: string): boolean {
    return (
      this.db.runOrThrow(
        'UPDATE refresh_tokens SET spent = 1 WHERE token_hash = ? AND spent = 0',
        refreshTokenKey(token)
      ) > 0
    );
  }

  /** Keeps only the newest SPENT_TOKENS_PER_GRANT spent tokens of a grant. */
  private pruneSpent(grantId: string): void {
    this.db.run(
      `DELETE FROM refresh_tokens WHERE token_hash IN (
         SELECT token_hash FROM refresh_tokens WHERE grant_id = ? AND spent = 1
         ORDER BY expires_at DESC, rowid DESC LIMIT -1 OFFSET ?)`,
      grantId,
      SPENT_TOKENS_PER_GRANT
    );
  }

  /** client_ids that hold at least one refresh token that has not expired. */
  activeClientIds(now = Date.now()): Set<string> {
    const rows = this.db.all<{ client_id: string }>(
      'SELECT DISTINCT client_id FROM refresh_tokens WHERE expires_at > ?',
      now
    );
    return new Set(rows.map((r) => r.client_id));
  }

  /** Removes every refresh token of a grant. */
  deleteGrant(grantId: string): void {
    this.db.run('DELETE FROM refresh_tokens WHERE grant_id = ?', grantId);
  }

  static fromEnv(): RefreshStore {
    return new RefreshStore(envStateDb());
  }
}

/**
 * Revoked grants and access tokens, each kept until the last access token it
 * could cover has expired. Access tokens are self-contained JWTs, so this list
 * is what makes them revocable before they expire (RFC 7009 §2).
 */
export class RevocationList {
  private readonly db: StateDb;

  constructor(db: StateDb = StateDb.memory()) {
    this.db = db;
    this.db.run('DELETE FROM revocations WHERE expires_at <= ?', Date.now());
  }

  revokeGrant(grantId: string, untilMs: number): void {
    this.add(`grant:${grantId}`, untilMs);
  }

  revokeAccessToken(jti: string, untilMs: number): void {
    this.add(`jti:${jti}`, untilMs);
  }

  isGrantRevoked(grantId: string): boolean {
    return this.has(`grant:${grantId}`);
  }

  isAccessTokenRevoked(jti: string): boolean {
    return this.has(`jti:${jti}`);
  }

  private add(key: string, untilMs: number): void {
    this.db.run('DELETE FROM revocations WHERE expires_at <= ?', Date.now());
    this.db.run(
      `INSERT INTO revocations (key, expires_at) VALUES (?, ?)
         ON CONFLICT (key) DO UPDATE SET expires_at = MAX(expires_at, excluded.expires_at)`,
      key,
      untilMs
    );
  }

  private has(key: string): boolean {
    return (
      this.db.get('SELECT 1 FROM revocations WHERE key = ? AND expires_at > ?', key, Date.now()) !==
      undefined
    );
  }

  static fromEnv(): RevocationList {
    return new RevocationList(envStateDb());
  }
}
