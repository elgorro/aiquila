// SPDX-License-Identifier: MIT

// Whose Nextcloud account a grant acts as. Every tool call runs as the user
// who signed in, with the app password Nextcloud issued through Login Flow
// v2 — never as the NEXTCLOUD_USER service account. Ending a grant deletes
// that app password: revocation (RFC 7009 §2.1) and refresh token reuse
// (RFC 9700 §4.14.2) included.

import { readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  form,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  decodeJwt,
  type Stack,
} from './harness.js';

let s: Stack;
let clientId: string;
beforeAll(async () => {
  s = await startStack();
  clientId = (await register(s)).client_id;
});
afterAll(() => s.close());

/** Calls system_status, and returns the uids Nextcloud saw the call as. */
async function callAs(token: string): Promise<{ status: number; users: Set<string | undefined> }> {
  const from = s.nc.requests.length;
  const res = await mcp(s, modernBody(1, 'tools/call', { name: 'system_status', arguments: {} }), {
    token,
  });
  await rpcResult(res);
  const users = new Set<string | undefined>();
  for (let i = from; i < s.nc.requests.length; i++) {
    if (s.nc.requests[i].url.startsWith('/ocs/v2.php/cloud/capabilities'))
      users.add(s.nc.userOf(i));
  }
  return { status: res.status, users };
}

function refresh(refreshToken: string): Promise<Response> {
  return fetch(
    `${s.base}/token`,
    form({ grant_type: 'refresh_token', refresh_token: refreshToken, client_id: clientId })
  );
}

describe('tools act as the signed-in user', () => {
  it('a non-admin user acts as themselves, not as the service account', async () => {
    const { access_token } = await obtainTokens(s, clientId, { username: 'bob' });
    const { status, users } = await callAs(access_token);
    expect(status).toBe(200);
    expect([...users]).toEqual(['bob']);
  });

  it('no request of a signed-in user ever carries the service account', async () => {
    const from = s.nc.requests.length;
    const { access_token } = await obtainTokens(s, clientId);
    await callAs(access_token);
    for (let i = from; i < s.nc.requests.length; i++) {
      expect(s.nc.userOf(i), s.nc.requests[i].url).not.toBe('service');
    }
  });

  it('acts with the app password Nextcloud issued, not with the user password', async () => {
    const before = new Set(s.nc.appPasswords.keys());
    const { access_token } = await obtainTokens(s, clientId);
    const issued = [...s.nc.appPasswords.keys()].filter((k) => !before.has(k));
    expect(issued).toHaveLength(1);
    const from = s.nc.requests.length;
    await callAs(access_token);
    const sent = s.nc.requests
      .slice(from)
      .map((r) => r.headers.authorization)
      .filter((h): h is string => !!h);
    expect(sent.length).toBeGreaterThan(0);
    for (const h of sent) {
      expect(h.startsWith('Basic ')).toBe(true);
      expect(Buffer.from(h.slice(6), 'base64').toString()).toBe(`alice:${issued[0]}`);
    }
  });

  it('binds the grant to the uid when the login name differs (an email login)', async () => {
    const before = new Set(s.nc.appPasswords.keys());
    const { access_token } = await obtainTokens(s, clientId, { username: 'alice@example.com' });
    const issued = [...s.nc.appPasswords.keys()].find((k) => !before.has(k))!;
    expect(decodeJwt(access_token).payload.sub).toBe('alice');
    const { users } = await callAs(access_token);
    expect([...users]).toEqual(['alice']);
    // Every request made with this grant's app password logs in by the login name.
    const logins = s.nc.requests
      .map((r) => r.headers.authorization)
      .filter((h): h is string => !!h?.startsWith('Basic '))
      .map((h) => Buffer.from(h.slice(6), 'base64').toString().split(':'))
      .filter(([, ...pw]) => pw.join(':') === issued)
      .map(([login]) => login);
    expect(logins.length).toBeGreaterThan(0);
    expect(new Set(logins)).toEqual(new Set(['alice@example.com']));
  });

  it('a failed tool call has Nextcloud asked again at once, ending a revoked grant', async () => {
    const { access_token } = await obtainTokens(s, clientId, { username: 'bob' });
    s.nc.revokeDevicesOf('bob');
    // The call fails against Nextcloud (the app password is gone)…
    expect((await callAs(access_token)).status).toBe(200);
    // …which ends the grant, so the client is told to sign in again.
    await expect.poll(async () => (await callAs(access_token)).status, { timeout: 2000 }).toBe(401);
  });
});

describe('the app password ends with the grant', () => {
  function devicesOf(user: string): number {
    return [...s.nc.appPasswords.values()].filter((a) => a.user === user).length;
  }

  it('RFC 7009 §2.1: revoking the refresh token deletes the app password', async () => {
    const { refresh_token } = await obtainTokens(s, clientId, { username: 'bob' });
    const before = devicesOf('bob');
    const res = await fetch(
      `${s.base}/revoke`,
      form({ token: refresh_token, token_type_hint: 'refresh_token', client_id: clientId })
    );
    expect(res.status).toBe(200);
    await expect.poll(() => devicesOf('bob')).toBe(before - 1);
  });

  it('RFC 9700 §4.14.2: refresh token reuse ends the grant and deletes the app password', async () => {
    const { refresh_token } = await obtainTokens(s, clientId, { username: 'bob' });
    const before = devicesOf('bob');
    expect((await refresh(refresh_token)).status).toBe(200);
    expect((await refresh(refresh_token)).status).toBe(400);
    await expect.poll(() => devicesOf('bob')).toBe(before - 1);
  });

  it('a device removed in Nextcloud ends the grant at the next refresh (RFC 6749 §5.2)', async () => {
    const { refresh_token } = await obtainTokens(s, clientId, { username: 'bob' });
    s.nc.revokeDevicesOf('bob');
    const res = await refresh(refresh_token);
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_grant');
  });

  it('a user disabled in Nextcloud loses the grant at the next refresh', async () => {
    const { refresh_token } = await obtainTokens(s, clientId, { username: 'bob' });
    s.nc.users.get('bob')!.enabled = false;
    try {
      const res = await refresh(refresh_token);
      expect(res.status).toBe(400);
      expect((await res.json()).error).toBe('invalid_grant');
    } finally {
      s.nc.users.get('bob')!.enabled = true;
    }
  });
});

describe('credentials at rest', () => {
  it('are encrypted, and the state file is readable by its owner only', async () => {
    const before = new Set(s.nc.appPasswords.keys());
    await obtainTokens(s, clientId);
    const issued = [...s.nc.appPasswords.keys()].find((k) => !before.has(k))!;
    const file = join(s.stateDir, 'credentials.json');
    const text = readFileSync(file, 'utf8');
    expect(text).not.toContain(issued);
    expect(text).toContain('local:v1:');
    expect(statSync(file).mode & 0o777).toBe(0o600);
  });
});
