// SPDX-License-Identifier: MIT

import { randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync, mkdirSync, renameSync, unlinkSync } from 'node:fs';
import { join, dirname } from 'node:path';
import type { OAuthClientInformationFull } from '@modelcontextprotocol/sdk/shared/auth.js';
import type { OAuthRegisteredClientsStore } from '@modelcontextprotocol/sdk/server/auth/clients.js';
import { logger } from '../logger.js';

export interface CodeEntry {
  pkceChallenge: string;
  clientId: string;
  scopes: string[];
  redirectUri: string;
  userId: string;
  state?: string;
  expiresAt: number;
}

interface RefreshEntry {
  userId: string;
  clientId: string;
  scopes: string[];
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
}

export class ClientsStore implements OAuthRegisteredClientsStore {
  private readonly clients = new Map<string, OAuthClientInformationFull>();
  private readonly stateFile?: string;

  // Optional: SDK only mounts POST /register when this is defined.
  readonly registerClient?: (
    client: Omit<OAuthClientInformationFull, 'client_id' | 'client_id_issued_at'>
  ) => OAuthClientInformationFull;

  constructor(options?: ClientsStoreOptions) {
    this.stateFile = options?.stateFile;

    // Load persisted clients first (dynamic registrations from previous runs)
    if (this.stateFile) {
      const persisted = loadJson<OAuthClientInformationFull[]>(this.stateFile);
      if (persisted && Array.isArray(persisted)) {
        for (const client of persisted) {
          this.clients.set(client.client_id, client);
        }
      }
    }

    // Pre-seeded clients always win on collision
    for (const client of options?.preseededClients ?? []) {
      this.clients.set(client.client_id, client);
    }

    if (options?.enableDynamicRegistration) {
      this.registerClient = (client) => {
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

  getClient(clientId: string): OAuthClientInformationFull | undefined {
    return this.clients.get(clientId);
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
   *
   * Note: no client_secret is stored — the SDK's clientAuth middleware requires the caller
   * to send client_secret whenever client.client_secret is set, which public OAuth clients
   * never do. Security is enforced via PKCE instead.
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
    if (process.env.MCP_REGISTRATION_ENABLED === 'true') {
      const dir = stateDir();
      ensureDir(dir);
      stateFile = join(dir, 'clients.json');
    }

    return new ClientsStore({
      preseededClients: preseeded,
      enableDynamicRegistration: process.env.MCP_REGISTRATION_ENABLED === 'true',
      stateFile,
    });
  }
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
        for (const [token, entry] of Object.entries(persisted)) {
          if (entry.expiresAt > now) {
            this.tokens.set(token, entry);
            loaded++;
          }
        }
        if (loaded > 0) {
          logger.info({ count: loaded }, '[state] Loaded refresh tokens from file');
        }
      }
    }
  }

  store(entry: Omit<RefreshEntry, 'expiresAt'>): string {
    const token = randomUUID();
    this.tokens.set(token, { ...entry, expiresAt: Date.now() + REFRESH_TTL_MS });
    this.persist();
    return token;
  }

  get(token: string): RefreshEntry | undefined {
    const entry = this.tokens.get(token);
    if (!entry) return undefined;
    if (Date.now() > entry.expiresAt) {
      this.tokens.delete(token);
      this.persist();
      return undefined;
    }
    return entry;
  }

  delete(token: string): void {
    this.tokens.delete(token);
    this.persist();
  }

  private persist(): void {
    if (!this.stateFile) return;
    const obj: Record<string, RefreshEntry> = {};
    for (const [token, entry] of this.tokens) {
      obj[token] = entry;
    }
    saveJson(this.stateFile, obj);
  }

  static fromEnv(): RefreshStore {
    const dir = stateDir();
    ensureDir(dir);
    return new RefreshStore(join(dir, 'refresh-tokens.json'));
  }
}
