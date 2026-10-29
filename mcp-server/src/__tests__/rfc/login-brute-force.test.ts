// SPDX-License-Identifier: MIT

// Brute-force protection on the login form. No RFC prescribes the mechanism,
// so this pins ours: failures are counted per username across IPs, and
// Nextcloud is told the end user's address.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  pkce,
  form,
  hiddenFields,
  cookieHeader,
  REDIRECT_URI,
  NC_PASSWORD,
  type Stack,
} from './harness.js';

let s: Stack;
let clientId: string;
beforeAll(async () => {
  // Trust one proxy hop so each request can claim its own client IP.
  s = await startStack({ MCP_TRUST_PROXY: '1' });
  clientId = (await register(s)).client_id;
});
afterAll(() => s.close());

async function attempt(ip: string, username: string, password: string): Promise<Response> {
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: clientId,
    redirect_uri: REDIRECT_URI,
    code_challenge: pkce().challenge,
    code_challenge_method: 'S256',
  });
  const page = await fetch(`${s.base}/authorize?${q}`);
  const fields = hiddenFields(await page.text());
  return fetch(
    `${s.base}/auth/login`,
    form({ ...fields, username, password }, { cookie: cookieHeader(page), 'x-forwarded-for': ip })
  );
}

describe('login brute-force protection', () => {
  it('throttles one username attacked from many IPs, without asking Nextcloud', async () => {
    for (let i = 0; i < 10; i++) {
      const res = await attempt(`203.0.113.${i}`, 'alice', 'guess');
      expect(res.status).toBe(200);
    }
    const before = s.nc.requests.length;
    const locked = await attempt('198.51.100.1', 'alice', NC_PASSWORD);
    expect(locked.status).toBe(429);
    expect(locked.headers.get('location')).toBeNull();
    expect(s.nc.requests.length).toBe(before);
  });

  it('other accounts are unaffected', async () => {
    const res = await attempt('198.51.100.2', 'bob', NC_PASSWORD);
    expect(res.status).toBe(302);
  });

  it('forwards the client address to Nextcloud', async () => {
    await attempt('198.51.100.3', 'bob', NC_PASSWORD);
    const login = s.nc.requests.filter((r) => r.url === '/ocs/v2.php/cloud/user').pop();
    expect(login?.headers['x-forwarded-for']).toBe('198.51.100.3');
  });
});
