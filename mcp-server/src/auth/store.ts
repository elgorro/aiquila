// SPDX-License-Identifier: MIT

import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync, renameSync, unlinkSync } from 'node:fs';
import { join, dirname } from 'node:path';
import {
  OAuthError,
  OAuthErrorCode,
  type OAuthClientInformationFull,
} from '@modelcontextprotocol/server';
import { ClientIdDocumentError, ClientIdDocumentResolver, looksLikeClientIdUrl } from './cimd.js';
import { RedirectUriPolicy } from './redirect-uri.js';
import { logger } from '../logger.js';

export interface CodeEntry {
  pkceChallenge: string;
  clientId: string;
  scopes: string[];
  redirectUri: string;
  // Whether the authorization request carried redirect_uri itself rather than
  // falling back to the client's only registered one. If it did, the token
  // request must repeat it (RFC 6749 §4.1.3).
  redirectUriExplicit: boolean;
  userId: string;
  state?: string;
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
  expiresAt: number;
}

const CODE_TTL_MS = 5 * 60 * 1000; // 5 minutes
const REFRESH_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours

const DEFAULT_STATE_DIR = '/app/state';

function stateDir(): string {
  return (process.env.MCP_AUTH_STATE_DIR ?? DEFAULT_STATE_DIR).replace(/\/+$/, '');
}

// True once we've emitted the loud "state dir not writable" warning, so the
// startup probe and the first failed persist warn at most once between them —
// subsequent persist failures drop to debug to avoid flooding the logs.
let warnedUnwritable = false;

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

/**
 * Marks the unwritable-state warning as already emitted (called by the startup
 * probe in the HTTP transport) so the first failed persist does not warn twice.
 */
export function markStateUnwritableWarned(): void {
  warnedUnwritable = true;
}

function warnPersistFailed(dir: string, code: string | undefined, err: unknown): void {
  if (warnedUnwritable) {
    logger.debug({ dir, code, err }, '[state] persist failed — state dir not writable');
    return;
  }
  warnedUnwritable = true;
  logger.warn({ dir, code }, stateUnwritableMessage(dir, code));
}

function ensureDir(dir: string): void {
  try {
    mkdirSync(dir, { recursive: true });
  } catch (err) {
    // Best-effort: probeStateDir() (called from startup) is the authoritative
    // check. Logging here would spam non-HTTP transports that never persist.
    logger.debug({ dir, err }, '[state] mkdir failed (probe will report fatal if writes break)');
  }
}

function loadJson<T>(filePath: string): T | null {
  try {
    return JSON.parse(readFileSync(filePath, 'utf8')) as T;
  } catch (err: unknown) {
    if ((err as NodeJS.ErrnoException).code !== 'ENOENT') {
      logger.warn({ file: filePath, err }, '[state] Failed to load state file — starting fresh');
      try {
        unlinkSync(filePath);
      } catch {
        /* ignore */
      }
    }
    return null;
  }
}

function saveJson(filePath: string, data: unknown): void {
  const tmp = filePath + '.tmp';
  try {
    writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
    renameSync(tmp, filePath);
  } catch (err) {
    try {
      unlinkSync(tmp);
    } catch {
      // ignore cleanup failure
    }
    // Graceful degradation: a persist failure must never crash a request or the
    // process. The server keeps running with in-memory state (tokens just won't
    // survive a restart) and warns the operator how to fix it. See discussion #342.
    warnPersistFailed(dirname(filePath), (err as NodeJS.ErrnoException).code, err);
  }
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
  enableDynamicRegistration?: boolean;
  stateFile?: string;
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

export class ClientsStore {
  private readonly clients = new Map<string, OAuthClientInformationFull>();
  private readonly stateFile?: string;
  private readonly clientIdDocuments?: ClientIdDocumentResolver;

  // Optional: POST /register is only mounted when this is defined.
  readonly registerClient?: (
    client: Omit<OAuthClientInformationFull, 'client_id' | 'client_id_issued_at'>
  ) => OAuthClientInformationFull;

  constructor(options?: ClientsStoreOptions) {
    this.stateFile = options?.stateFile;
    this.clientIdDocuments = options?.clientIdDocuments;
    const policy = options?.redirectPolicy;

    // Load persisted clients first (dynamic registrations from previous runs).
    // The redirect policy applies to them too, so registrations from before
    // it existed, or before it was narrowed, are dropped.
    if (this.stateFile) {
      const persisted = loadJson<OAuthClientInformationFull[]>(this.stateFile);
      if (persisted && Array.isArray(persisted)) {
        let dropped = 0;
        for (const client of persisted) {
          const reason = rejectedRedirect(client, policy);
          if (reason) {
            logger.warn(
              { clientId: client.client_id, reason },
              '[auth] Dropping registered client: redirect URI not allowed'
            );
            dropped++;
            continue;
          }
          this.clients.set(client.client_id, client);
        }
        if (dropped > 0) this.persist();
      }
    }

    // Pre-seeded clients always win on collision
    for (const client of options?.preseededClients ?? []) {
      this.clients.set(client.client_id, client);
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
        this.clients.set(full.client_id, full);
        this.persist();
        return full;
      };
    }
  }

  /** True when https:// client_ids are resolved as Client ID Metadata Documents. */
  get clientIdMetadataDocumentsEnabled(): boolean {
    return this.clientIdDocuments !== undefined;
  }

  /**
   * Looks up a pre-seeded or registered client, then — for an https:// client_id
   * — fetches its Client ID Metadata Document. Returns undefined for an unknown
   * client or a document that fails validation.
   */
  async getClient(clientId: string): Promise<OAuthClientInformationFull | undefined> {
    const known = this.clients.get(clientId);
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

  private persist(): void {
    if (!this.stateFile) return;
    saveJson(this.stateFile, [...this.clients.values()]);
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

    let stateFile: string | undefined;
    const registrationEnabled = process.env.MCP_REGISTRATION_ENABLED === 'true';
    if (registrationEnabled) {
      const dir = stateDir();
      ensureDir(dir);
      stateFile = join(dir, 'clients.json');
    }

    return new ClientsStore({
      preseededClients: preseeded,
      enableDynamicRegistration: registrationEnabled,
      redirectPolicy: registrationEnabled ? RedirectUriPolicy.fromEnv() : undefined,
      stateFile,
      clientIdDocuments: cimdEnabledFromEnv() ? new ClientIdDocumentResolver() : undefined,
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
    if (!entry) return undefined;
    if (Date.now() > entry.expiresAt) {
      this.codes.delete(code);
      return undefined;
    }
    return entry;
  }

  delete(code: string): void {
    this.codes.delete(code);
  }
}

/** The key a refresh token is stored under: its SHA-256, hex-encoded. */
function refreshTokenKey(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}

const HASHED_KEY = /^[0-9a-f]{64}$/;

/**
 * Refresh tokens, keyed by their SHA-256 hash so the state file holds nothing
 * a reader could present as a token. The tokens are 256 random bits, so a
 * plain hash needs no salt or stretching.
 */
export class RefreshStore {
  private readonly tokens = new Map<string, RefreshEntry>();
  private readonly stateFile?: string;

  constructor(stateFile?: string) {
    this.stateFile = stateFile;

    if (this.stateFile) {
      const persisted = loadJson<Record<string, RefreshEntry>>(this.stateFile);
      if (persisted && typeof persisted === 'object') {
        const now = Date.now();
        let loaded = 0;
        let migrated = 0;
        for (const [key, entry] of Object.entries(persisted)) {
          if (entry.expiresAt > now) {
            // Files from earlier versions keyed entries by the token itself.
            const hashed = HASHED_KEY.test(key) ? key : refreshTokenKey(key);
            if (hashed !== key) migrated++;
            // Entries written before grants existed get one of their own.
            this.tokens.set(hashed, { ...entry, grantId: entry.grantId ?? randomUUID() });
            loaded++;
          }
        }
        if (loaded > 0) {
          logger.info({ count: loaded }, '[state] Loaded refresh tokens from file');
        }
        if (migrated > 0) {
          logger.info({ count: migrated }, '[state] Hashed plaintext refresh tokens');
          this.persist();
        }
      }
    }
  }

  store(entry: Omit<RefreshEntry, 'expiresAt'>): string {
    const token = randomBytes(32).toString('base64url');
    this.tokens.set(refreshTokenKey(token), { ...entry, expiresAt: Date.now() + REFRESH_TTL_MS });
    this.persist();
    return token;
  }

  get(token: string): RefreshEntry | undefined {
    const key = refreshTokenKey(token);
    const entry = this.tokens.get(key);
    if (!entry) return undefined;
    if (Date.now() > entry.expiresAt) {
      this.tokens.delete(key);
      this.persist();
      return undefined;
    }
    return entry;
  }

  delete(token: string): void {
    this.tokens.delete(refreshTokenKey(token));
    this.persist();
  }

  /** Marks a token as exchanged. It no longer redeems, but is still recognised. */
  markSpent(token: string): void {
    const entry = this.tokens.get(refreshTokenKey(token));
    if (!entry) return;
    entry.spent = true;
    this.persist();
  }

  /** Removes every refresh token of a grant. */
  deleteGrant(grantId: string): void {
    for (const [token, entry] of this.tokens) {
      if (entry.grantId === grantId) this.tokens.delete(token);
    }
    this.persist();
  }

  private persist(): void {
    if (!this.stateFile) return;
    const obj: Record<string, RefreshEntry> = {};
    for (const [key, entry] of this.tokens) {
      obj[key] = entry;
    }
    saveJson(this.stateFile, obj);
  }

  static fromEnv(): RefreshStore {
    const dir = stateDir();
    ensureDir(dir);
    return new RefreshStore(join(dir, 'refresh-tokens.json'));
  }
}

/**
 * Revoked grants and access tokens, each kept until the last access token it
 * could cover has expired. Access tokens are self-contained JWTs, so this list
 * is what makes them revocable before they expire (RFC 7009 §2).
 */
export class RevocationList {
  private readonly revoked = new Map<string, number>();
  private readonly stateFile?: string;

  constructor(stateFile?: string) {
    this.stateFile = stateFile;
    if (this.stateFile) {
      const persisted = loadJson<Record<string, number>>(this.stateFile);
      if (persisted && typeof persisted === 'object') {
        const now = Date.now();
        for (const [key, expiresAt] of Object.entries(persisted)) {
          if (typeof expiresAt === 'number' && expiresAt > now) this.revoked.set(key, expiresAt);
        }
      }
    }
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
    const now = Date.now();
    for (const [k, expiresAt] of this.revoked) {
      if (expiresAt <= now) this.revoked.delete(k);
    }
    this.revoked.set(key, Math.max(untilMs, this.revoked.get(key) ?? 0));
    this.persist();
  }

  private has(key: string): boolean {
    const expiresAt = this.revoked.get(key);
    return expiresAt !== undefined && expiresAt > Date.now();
  }

  private persist(): void {
    if (!this.stateFile) return;
    saveJson(this.stateFile, Object.fromEntries(this.revoked));
  }

  static fromEnv(): RevocationList {
    const dir = stateDir();
    ensureDir(dir);
    return new RevocationList(join(dir, 'revoked.json'));
  }
}
