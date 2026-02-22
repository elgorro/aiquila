import { randomUUID } from 'node:crypto';
import type { OAuthClientInformationFull } from '@modelcontextprotocol/sdk/shared/auth.js';
import type { OAuthRegisteredClientsStore } from '@modelcontextprotocol/sdk/server/auth/clients.js';

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

interface ClientsStoreOptions {
  preseededClients?: OAuthClientInformationFull[];
  enableDynamicRegistration?: boolean;
}

export class ClientsStore implements OAuthRegisteredClientsStore {
  private readonly clients = new Map<string, OAuthClientInformationFull>();

  // Optional: SDK only mounts POST /register when this is defined.
  readonly registerClient?: (
    client: Omit<OAuthClientInformationFull, 'client_id' | 'client_id_issued_at'>
  ) => OAuthClientInformationFull;

  constructor(options?: ClientsStoreOptions) {
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
        return full;
      };
    }
  }

  getClient(clientId: string): OAuthClientInformationFull | undefined {
    return this.clients.get(clientId);
  }

  /**
   * Creates a ClientsStore configured from environment variables:
   *   MCP_CLIENT_ID + MCP_CLIENT_SECRET  → pre-seeded static client (for Claude Desktop)
   *   MCP_REGISTRATION_ENABLED=true      → enable dynamic POST /register
   */
  static fromEnv(): ClientsStore {
    const preseeded: OAuthClientInformationFull[] = [];
    const id = process.env.MCP_CLIENT_ID;
    const secret = process.env.MCP_CLIENT_SECRET;

    if (id && secret) {
      preseeded.push({
        client_id: id,
        client_secret: secret,
        client_id_issued_at: Math.floor(Date.now() / 1000),
        client_secret_expires_at: 0, // 0 = never expires (SDK skips check when falsy)
        redirect_uris: [],
        token_endpoint_auth_method: 'client_secret_post',
        grant_types: ['authorization_code', 'refresh_token'],
        response_types: ['code'],
        client_name: 'Pre-seeded client',
      });
    }

    return new ClientsStore({
      preseededClients: preseeded,
      enableDynamicRegistration: process.env.MCP_REGISTRATION_ENABLED === 'true',
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

  store(entry: Omit<RefreshEntry, 'expiresAt'>): string {
    const token = randomUUID();
    this.tokens.set(token, { ...entry, expiresAt: Date.now() + REFRESH_TTL_MS });
    return token;
  }

  get(token: string): RefreshEntry | undefined {
    const entry = this.tokens.get(token);
    if (!entry) return undefined;
    if (Date.now() > entry.expiresAt) {
      this.tokens.delete(token);
      return undefined;
    }
    return entry;
  }

  delete(token: string): void {
    this.tokens.delete(token);
  }
}
