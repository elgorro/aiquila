// SPDX-License-Identifier: MIT

import { createCipheriv, createDecipheriv, hkdfSync, randomBytes } from 'node:crypto';
import { join } from 'node:path';
import type { NextcloudUserCredential } from '../nc-context.js';
import { logger } from '../logger.js';
import { ensureDir, loadJson, saveJson, stateDir } from './store.js';

/**
 * Encrypts and decrypts the Nextcloud app passwords held for OAuth grants.
 * `aad` is authenticated but not encrypted: it binds a sealed value to the
 * grant and user it was written for, so an entry cannot be moved to another.
 */
export interface CredentialCipher {
  /** Short name for logs: `local` or `openbao`. */
  readonly name: string;
  encrypt(plaintext: Buffer, aad: Buffer): Promise<string>;
  decrypt(sealed: string, aad: Buffer): Promise<Buffer>;
}

/**
 * The key service could not be asked (OpenBao unreachable, timing out, or
 * refusing the token). Says nothing about the stored value, so it must never
 * end a grant: the request fails and the value is tried again later.
 */
export class CredentialBackendUnavailableError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CredentialBackendUnavailableError';
  }
}

// --- Built-in: AES-256-GCM, key derived from MCP_CREDENTIAL_KEY ---

const LOCAL_PREFIX = 'local:v1:';
const IV_BYTES = 12; // GCM's native nonce size (NIST SP 800-38D §5.2.1.1)
const TAG_BYTES = 16;
// MCP_CREDENTIAL_KEY must carry at least the AES key's strength; 64 bytes
// (`openssl rand -hex 64`) is what the docs recommend.
export const MIN_CREDENTIAL_KEY_BYTES = 32;

/**
 * AES-256-GCM with a fresh random 96-bit IV per encryption. The key is
 * HKDF-SHA-512 (RFC 5869) over MCP_CREDENTIAL_KEY, so the raw secret is never
 * used as a cipher key and a later key version can be derived alongside.
 * Sealed format: `local:v1:` + base64url(iv ‖ ciphertext ‖ tag).
 */
export class LocalCipher implements CredentialCipher {
  readonly name = 'local';
  private readonly key: Buffer;

  constructor(keyMaterial: Buffer) {
    if (keyMaterial.length < MIN_CREDENTIAL_KEY_BYTES) {
      throw new Error(
        `MCP_CREDENTIAL_KEY must be at least ${MIN_CREDENTIAL_KEY_BYTES} bytes ` +
          `(${MIN_CREDENTIAL_KEY_BYTES * 2} hex characters); generate one with openssl rand -hex 64`
      );
    }
    this.key = Buffer.from(
      hkdfSync('sha512', keyMaterial, 'aiquila', 'aiquila credential store v1', 32)
    );
  }

  /** Reads MCP_CREDENTIAL_KEY, hex-encoded. */
  static fromHex(hex: string): LocalCipher {
    const trimmed = hex.trim();
    if (!/^(?:[0-9a-fA-F]{2})+$/.test(trimmed)) {
      throw new Error('MCP_CREDENTIAL_KEY must be hex-encoded (openssl rand -hex 64)');
    }
    return new LocalCipher(Buffer.from(trimmed, 'hex'));
  }

  async encrypt(plaintext: Buffer, aad: Buffer): Promise<string> {
    const iv = randomBytes(IV_BYTES);
    const cipher = createCipheriv('aes-256-gcm', this.key, iv, { authTagLength: TAG_BYTES });
    cipher.setAAD(aad);
    const ct = Buffer.concat([cipher.update(plaintext), cipher.final()]);
    return LOCAL_PREFIX + Buffer.concat([iv, ct, cipher.getAuthTag()]).toString('base64url');
  }

  async decrypt(sealed: string, aad: Buffer): Promise<Buffer> {
    if (!sealed.startsWith(LOCAL_PREFIX)) throw new Error('Not a local:v1 credential');
    const raw = Buffer.from(sealed.slice(LOCAL_PREFIX.length), 'base64url');
    if (raw.length < IV_BYTES + TAG_BYTES) throw new Error('Sealed credential is truncated');
    const iv = raw.subarray(0, IV_BYTES);
    const tag = raw.subarray(raw.length - TAG_BYTES);
    const decipher = createDecipheriv('aes-256-gcm', this.key, iv, { authTagLength: TAG_BYTES });
    decipher.setAAD(aad);
    decipher.setAuthTag(tag);
    return Buffer.concat([
      decipher.update(raw.subarray(IV_BYTES, raw.length - TAG_BYTES)),
      decipher.final(),
    ]);
  }
}

// --- Optional: OpenBao (or Vault) Transit ---

// OpenBao Transit's errors for a ciphertext that is malformed or fails authentication.
const OPENBAO_VALUE_ERROR = /message authentication failed|invalid ciphertext/i;
const OPENBAO_PREFIX = 'openbao:';
const OPENBAO_TIMEOUT_MS = 5000;
// A periodic token must be renewed within its period; renewing hourly covers
// any period of an hour or more.
const OPENBAO_RENEW_MS = 60 * 60 * 1000;

export interface OpenBaoOptions {
  addr: string;
  token: string;
  mount: string;
  key: string;
}

/**
 * Encryption as a service: the key never leaves OpenBao, which also handles
 * key rotation (`rotate`, then `rewrap`). The Transit key must be an AEAD type
 * (`aes256-gcm96`, the default, or `chacha20-poly1305` / `xchacha20-poly1305`)
 * because `associated_data` is sent on every call. The API is Vault's, so a
 * HashiCorp Vault server works too.
 */
export class OpenBaoCipher implements CredentialCipher {
  readonly name = 'openbao';
  private readonly base: string;

  constructor(private readonly opts: OpenBaoOptions) {
    this.base = `${opts.addr.replace(/\/+$/, '')}/v1`;
  }

  static fromEnv(): OpenBaoCipher {
    const addr = process.env.MCP_OPENBAO_ADDR!;
    const token = process.env.MCP_OPENBAO_TOKEN;
    if (!token) throw new Error('MCP_OPENBAO_TOKEN must be set when MCP_OPENBAO_ADDR is set');
    return new OpenBaoCipher({
      addr,
      token,
      mount: process.env.MCP_OPENBAO_TRANSIT_MOUNT || 'transit',
      key: process.env.MCP_OPENBAO_TRANSIT_KEY || 'aiquila-credentials',
    });
  }

  private async call(path: string, body?: unknown): Promise<any> {
    let resp: Response;
    try {
      resp = await fetch(`${this.base}/${path}`, {
        method: 'POST',
        headers: { 'X-Vault-Token': this.opts.token, 'Content-Type': 'application/json' },
        body: JSON.stringify(body ?? {}),
        signal: AbortSignal.timeout(OPENBAO_TIMEOUT_MS),
      });
    } catch (err) {
      throw new CredentialBackendUnavailableError(
        `OpenBao ${path} unreachable: ${(err as Error).message}`
      );
    }
    if (!resp.ok) {
      // The error body names the failing check; it never echoes the plaintext.
      const detail = (await resp.text().catch(() => '')).slice(0, 200);
      const message = `OpenBao ${path} failed: ${resp.status} ${detail}`;
      // Only a 400 naming the ciphertext is about the value itself (it does not
      // decrypt under this grant's associated data). OpenBao answers 400 for
      // configuration errors too — a missing transit key, say — and those, like
      // a refused or expired token or a sealed or overloaded server, are about
      // OpenBao: treating them as bad values would end every grant.
      if (resp.status === 400 && OPENBAO_VALUE_ERROR.test(detail)) throw new Error(message);
      throw new CredentialBackendUnavailableError(message);
    }
    return resp.json();
  }

  async encrypt(plaintext: Buffer, aad: Buffer): Promise<string> {
    const r = await this.call(`${this.opts.mount}/encrypt/${encodeURIComponent(this.opts.key)}`, {
      plaintext: plaintext.toString('base64'),
      associated_data: aad.toString('base64'),
    });
    const ct = r?.data?.ciphertext;
    if (typeof ct !== 'string') throw new Error('OpenBao encrypt returned no ciphertext');
    return OPENBAO_PREFIX + ct;
  }

  async decrypt(sealed: string, aad: Buffer): Promise<Buffer> {
    if (!sealed.startsWith(OPENBAO_PREFIX)) throw new Error('Not an OpenBao credential');
    const r = await this.call(`${this.opts.mount}/decrypt/${encodeURIComponent(this.opts.key)}`, {
      ciphertext: sealed.slice(OPENBAO_PREFIX.length),
      associated_data: aad.toString('base64'),
    });
    const pt = r?.data?.plaintext;
    if (typeof pt !== 'string') throw new Error('OpenBao decrypt returned no plaintext');
    return Buffer.from(pt, 'base64');
  }

  /**
   * Keeps a periodic token alive, and confirms it works. Renewal is scheduled
   * before the first attempt, so an OpenBao that is not up yet at boot still
   * gets its token renewed once it is.
   */
  async start(): Promise<void> {
    const renew = () => this.call('auth/token/renew-self');
    setInterval(() => {
      renew().catch((err) =>
        logger.warn({ err: (err as Error).message }, '[credentials] OpenBao token renewal failed')
      );
    }, OPENBAO_RENEW_MS).unref();
    await renew();
  }
}

/**
 * The cipher the environment configures: OpenBao Transit when
 * MCP_OPENBAO_ADDR is set, else the built-in one keyed by MCP_CREDENTIAL_KEY.
 */
export function cipherFromEnv(): CredentialCipher {
  if (process.env.MCP_OPENBAO_ADDR) return OpenBaoCipher.fromEnv();
  const key = process.env.MCP_CREDENTIAL_KEY;
  if (!key) {
    throw new Error(
      'MCP_CREDENTIAL_KEY must be set when MCP_AUTH_ENABLED=true (openssl rand -hex 64), ' +
        'or MCP_OPENBAO_ADDR to keep the key in OpenBao'
    );
  }
  return LocalCipher.fromHex(key);
}

// --- The store ---

interface StoredCredential {
  userId: string;
  sealed: string;
  expiresAt: number;
}

interface SealedPayload {
  loginName: string;
  appPassword: string;
}

function aadFor(grantId: string, userId: string): Buffer {
  return Buffer.from(JSON.stringify(['aiquila-credential-v1', grantId, userId]));
}

/**
 * One Nextcloud credential per OAuth grant, encrypted at rest. The state file
 * holds only sealed values; a plaintext app password exists in memory only
 * while a request that needs it runs.
 */
export class CredentialStore {
  private readonly entries = new Map<string, StoredCredential>();

  constructor(
    private readonly cipher: CredentialCipher,
    private readonly stateFile?: string
  ) {
    if (this.stateFile) {
      const persisted = loadJson<Record<string, StoredCredential>>(this.stateFile);
      if (persisted && typeof persisted === 'object') {
        for (const [grantId, e] of Object.entries(persisted)) {
          if (
            e &&
            typeof e.userId === 'string' &&
            typeof e.sealed === 'string' &&
            typeof e.expiresAt === 'number'
          ) {
            this.entries.set(grantId, e);
          }
        }
      }
    }
  }

  static fromEnv(cipher: CredentialCipher = cipherFromEnv()): CredentialStore {
    const dir = stateDir();
    ensureDir(dir);
    return new CredentialStore(cipher, join(dir, 'credentials.json'));
  }

  get cipherName(): string {
    return this.cipher.name;
  }

  async put(cred: NextcloudUserCredential, expiresAt: number): Promise<void> {
    const payload: SealedPayload = { loginName: cred.loginName, appPassword: cred.appPassword };
    const sealed = await this.cipher.encrypt(
      Buffer.from(JSON.stringify(payload)),
      aadFor(cred.grantId, cred.userId)
    );
    this.entries.set(cred.grantId, { userId: cred.userId, sealed, expiresAt });
    this.persist();
  }

  /**
   * The credential of `grantId`, or undefined when there is none, it has
   * expired, it belongs to another user, or it no longer decrypts (the key
   * changed). Callers treat all of these alike: the grant cannot be used.
   * Throws CredentialBackendUnavailableError when the key service cannot be
   * asked, which says nothing about the grant.
   */
  async get(grantId: string, userId: string): Promise<NextcloudUserCredential | undefined> {
    const e = this.entries.get(grantId);
    if (!e || e.userId !== userId || e.expiresAt <= Date.now()) return undefined;
    return this.open(grantId, e);
  }

  has(grantId: string): boolean {
    return this.entries.has(grantId);
  }

  /** Slides the expiry along with the grant's refresh token; `Date.now()` marks it for the sweep. */
  extend(grantId: string, expiresAt: number): void {
    const e = this.entries.get(grantId);
    if (!e) return;
    e.expiresAt = expiresAt;
    this.persist();
  }

  delete(grantId: string): void {
    if (this.entries.delete(grantId)) this.persist();
  }

  /** Grant ids whose credential has expired, with their user. */
  expired(now = Date.now()): { grantId: string; userId: string }[] {
    return [...this.entries]
      .filter(([, e]) => e.expiresAt <= now)
      .map(([grantId, e]) => ({ grantId, userId: e.userId }));
  }

  /** Reads an entry whatever its expiry, to delete its app password in Nextcloud. */
  async getForCleanup(grantId: string): Promise<NextcloudUserCredential | undefined> {
    const e = this.entries.get(grantId);
    return e ? this.open(grantId, e) : undefined;
  }

  private async open(
    grantId: string,
    e: StoredCredential
  ): Promise<NextcloudUserCredential | undefined> {
    try {
      const plain = await this.cipher.decrypt(e.sealed, aadFor(grantId, e.userId));
      const p = JSON.parse(plain.toString('utf8')) as SealedPayload;
      if (typeof p.loginName !== 'string' || typeof p.appPassword !== 'string') return undefined;
      return { grantId, userId: e.userId, loginName: p.loginName, appPassword: p.appPassword };
    } catch (err) {
      if (err instanceof CredentialBackendUnavailableError) throw err;
      logger.warn(
        { grant: grantId, cipher: this.cipher.name, err: (err as Error).message },
        '[credentials] Stored credential could not be decrypted'
      );
      return undefined;
    }
  }

  private persist(): void {
    if (!this.stateFile) return;
    saveJson(this.stateFile, Object.fromEntries(this.entries), 0o600);
  }
}
