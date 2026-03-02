import { randomUUID } from 'node:crypto';
import { readFileSync, writeFile, mkdirSync } from 'node:fs';
import { join } from 'node:path';
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

function ensureDir(dir: string): void {
  try {
    mkdirSync(dir, { recursive: true });
  } catch (err) {
    logger.warn({ dir, err }, '[state] Failed to create state directory');
  }
}

function loadJson<T>(filePath: string): T | null {
  try {
    return JSON.parse(readFileSync(filePath, 'utf8')) as T;
  } catch (err: unknown) {
    if ((err as NodeJS.ErrnoException).code !== 'ENOENT') {
      logger.warn({ file: filePath, err }, '[state] Failed to load state file — starting fresh');
    }
    return null;
  }
}

function saveJson(filePath: string, data: unknown): void {
  writeFile(filePath, JSON.stringify(data, null, 2), 'utf8', (err) => {
    if (err) logger.warn({ file: filePath, err }, '[state] Failed to save state file');
  });
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
   *   MCP_CLIENT_ID  → pre-seeded static public PKCE client (for Claude.ai / Claude Desktop)
   *   MCP_REGISTRATION_ENABLED=true      → enable dynamic POST /register
   *
   * Note: no client_secret is stored — the SDK's clientAuth middleware requires the caller
   * to send client_secret whenever client.client_secret is set, which public OAuth clients
   * (e.g. Claude.ai) never do. Security is enforced via PKCE instead.
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
        : ['https://claude.ai/api/mcp/auth_callback'];

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
