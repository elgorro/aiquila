// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
  CredentialStore,
  LocalCipher,
  OpenBaoCipher,
  cipherFromEnv,
} from '../../auth/credential-store.js';

const CRED = { grantId: 'g1', userId: 'alice', loginName: 'alice@example.com', appPassword: 'pw' };
const LATER = () => Date.now() + 60_000;

describe('LocalCipher (AES-256-GCM, HKDF-SHA-512)', () => {
  const cipher = new LocalCipher(randomBytes(64));
  const aad = Buffer.from('aad');

  it('round-trips', async () => {
    const sealed = await cipher.encrypt(Buffer.from('secret'), aad);
    expect(sealed.startsWith('local:v1:')).toBe(true);
    expect((await cipher.decrypt(sealed, aad)).toString()).toBe('secret');
  });

  it('uses a fresh IV every time', async () => {
    const a = await cipher.encrypt(Buffer.from('secret'), aad);
    const b = await cipher.encrypt(Buffer.from('secret'), aad);
    expect(a).not.toBe(b);
  });

  it('refuses a ciphertext whose bytes or associated data changed', async () => {
    const sealed = await cipher.encrypt(Buffer.from('secret'), aad);
    const raw = Buffer.from(sealed.slice('local:v1:'.length), 'base64url');
    for (const i of [0, 12, raw.length - 1]) {
      const flipped = Buffer.from(raw);
      flipped[i] ^= 1;
      await expect(
        cipher.decrypt('local:v1:' + flipped.toString('base64url'), aad)
      ).rejects.toThrow();
    }
    await expect(cipher.decrypt(sealed, Buffer.from('other'))).rejects.toThrow();
  });

  it('refuses another key', async () => {
    const sealed = await cipher.encrypt(Buffer.from('secret'), aad);
    await expect(new LocalCipher(randomBytes(64)).decrypt(sealed, aad)).rejects.toThrow();
  });

  it('requires at least 256 bits of hex key material', () => {
    expect(() => LocalCipher.fromHex('ab'.repeat(31))).toThrow(/at least 32 bytes/);
    expect(() => LocalCipher.fromHex('zz'.repeat(64))).toThrow(/hex/);
    expect(() => LocalCipher.fromHex('ab'.repeat(64))).not.toThrow();
  });
});

describe('cipherFromEnv', () => {
  const saved = process.env;
  afterEach(() => {
    process.env = saved;
  });

  it('requires MCP_CREDENTIAL_KEY without OpenBao', () => {
    process.env = { ...saved, MCP_CREDENTIAL_KEY: undefined, MCP_OPENBAO_ADDR: undefined };
    expect(() => cipherFromEnv()).toThrow('MCP_CREDENTIAL_KEY');
  });

  it('chooses OpenBao when MCP_OPENBAO_ADDR is set, and requires its token', () => {
    process.env = { ...saved, MCP_OPENBAO_ADDR: 'http://bao:8200', MCP_OPENBAO_TOKEN: undefined };
    expect(() => cipherFromEnv()).toThrow('MCP_OPENBAO_TOKEN');
    process.env.MCP_OPENBAO_TOKEN = 't';
    expect(cipherFromEnv()).toBeInstanceOf(OpenBaoCipher);
  });
});

describe('OpenBaoCipher (Transit)', () => {
  const fetchMock = vi.fn();
  beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });
  afterEach(() => vi.unstubAllGlobals());

  const cipher = new OpenBaoCipher({
    addr: 'http://bao:8200/',
    token: 'bao-token',
    mount: 'transit',
    key: 'aiquila-credentials',
  });

  it('encrypts with associated data and the token header', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: { ciphertext: 'vault:v1:abc' } }), { status: 200 })
    );
    const sealed = await cipher.encrypt(Buffer.from('secret'), Buffer.from('aad'));
    expect(sealed).toBe('openbao:vault:v1:abc');
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('http://bao:8200/v1/transit/encrypt/aiquila-credentials');
    expect(init.headers['X-Vault-Token']).toBe('bao-token');
    expect(JSON.parse(init.body)).toEqual({
      plaintext: Buffer.from('secret').toString('base64'),
      associated_data: Buffer.from('aad').toString('base64'),
    });
  });

  it('decrypts', async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ data: { plaintext: Buffer.from('secret').toString('base64') } })
      )
    );
    const plain = await cipher.decrypt('openbao:vault:v1:abc', Buffer.from('aad'));
    expect(plain.toString()).toBe('secret');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).ciphertext).toBe('vault:v1:abc');
  });

  it('fails when OpenBao refuses', async () => {
    fetchMock.mockResolvedValue(new Response('{"errors":["permission denied"]}', { status: 403 }));
    await expect(cipher.encrypt(Buffer.from('x'), Buffer.from('a'))).rejects.toThrow(/403/);
  });
});

describe('CredentialStore', () => {
  let dir: string;
  let file: string;
  const cipher = new LocalCipher(randomBytes(64));

  beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'aiquila-creds-'));
    file = join(dir, 'credentials.json');
  });
  afterEach(() => rmSync(dir, { recursive: true, force: true }));

  it('returns what was stored, for the grant and user it was stored for', async () => {
    const store = new CredentialStore(cipher, file);
    await store.put(CRED, LATER());
    await expect(store.get('g1', 'alice')).resolves.toEqual(CRED);
    await expect(store.get('g1', 'bob')).resolves.toBeUndefined();
    await expect(store.get('g2', 'alice')).resolves.toBeUndefined();
  });

  it('persists only sealed values, in a file only its owner can read', async () => {
    await new CredentialStore(cipher, file).put(CRED, LATER());
    const text = readFileSync(file, 'utf8');
    expect(text).not.toContain('"pw"');
    expect(text).not.toContain('alice@example.com');
    expect(statSync(file).mode & 0o777).toBe(0o600);
    await expect(new CredentialStore(cipher, file).get('g1', 'alice')).resolves.toEqual(CRED);
  });

  it('an entry moved to another grant does not decrypt', async () => {
    await new CredentialStore(cipher, file).put(CRED, LATER());
    const data = JSON.parse(readFileSync(file, 'utf8'));
    writeFileSync(file, JSON.stringify({ g2: data.g1 }));
    await expect(new CredentialStore(cipher, file).get('g2', 'alice')).resolves.toBeUndefined();
  });

  it('does not hand out an expired credential, but reports it for clean-up', async () => {
    const store = new CredentialStore(cipher, file);
    await store.put(CRED, Date.now() - 1);
    await expect(store.get('g1', 'alice')).resolves.toBeUndefined();
    expect(store.expired()).toEqual([{ grantId: 'g1', userId: 'alice' }]);
    await expect(store.getForCleanup('g1')).resolves.toEqual(CRED);
  });

  it('extend() slides the expiry; delete() removes the entry', async () => {
    const store = new CredentialStore(cipher, file);
    await store.put(CRED, Date.now() - 1);
    store.extend('g1', LATER());
    await expect(store.get('g1', 'alice')).resolves.toEqual(CRED);
    store.delete('g1');
    expect(store.has('g1')).toBe(false);
  });
});
