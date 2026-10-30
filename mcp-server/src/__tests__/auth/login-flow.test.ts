// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  checkCredential,
  deleteAppPassword,
  pollLoginFlow,
  startLoginFlow,
} from '../../auth/login-flow.js';

const fetchMock = vi.fn();
const saved = process.env;

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal('fetch', fetchMock);
  process.env = { ...saved, NEXTCLOUD_URL: 'http://nc', NEXTCLOUD_PUBLIC_URL: undefined };
});
afterEach(() => {
  vi.unstubAllGlobals();
  process.env = saved;
});

function started(login: string, endpoint = 'http://nc/login/v2/poll') {
  fetchMock.mockResolvedValue(
    new Response(JSON.stringify({ poll: { token: 'tok', endpoint }, login }), { status: 200 })
  );
}

describe('startLoginFlow', () => {
  it('starts the flow anonymously, naming the device in the User-Agent', async () => {
    started('http://nc/login/v2/flow/abc');
    const flow = await startLoginFlow('AIquila MCP (Claude)');
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('http://nc/index.php/login/v2');
    expect(init.method).toBe('POST');
    expect(init.headers['User-Agent']).toBe('AIquila MCP (Claude)');
    expect(init.headers.Authorization).toBeUndefined();
    expect(flow).toEqual({
      loginUrl: 'http://nc/login/v2/flow/abc',
      pollToken: 'tok',
      pollPath: '/login/v2/poll',
    });
  });

  it('sends the browser to NEXTCLOUD_PUBLIC_URL when Nextcloud is reached internally', async () => {
    process.env.NEXTCLOUD_PUBLIC_URL = 'https://cloud.example.com/';
    started('http://nc/login/v2/flow/abc');
    const flow = await startLoginFlow('d');
    expect(flow.loginUrl).toBe('https://cloud.example.com/login/v2/flow/abc');
  });

  it('accepts URLs Nextcloud already built on its public origin', async () => {
    process.env.NEXTCLOUD_PUBLIC_URL = 'https://cloud.example.com';
    started(
      'https://cloud.example.com/index.php/login/v2/flow/abc',
      'https://cloud.example.com/index.php/login/v2/poll'
    );
    const flow = await startLoginFlow('d');
    expect(flow.loginUrl).toBe('https://cloud.example.com/index.php/login/v2/flow/abc');
    expect(flow.pollPath).toBe('/index.php/login/v2/poll');
  });

  it('refuses a login URL or poll endpoint on any other origin', async () => {
    started('https://evil.example/login/v2/flow/abc');
    await expect(startLoginFlow('d')).rejects.toThrow(/unexpected origin/);
    started('http://nc/login/v2/flow/abc', 'https://evil.example/poll');
    await expect(startLoginFlow('d')).rejects.toThrow(/unexpected origin/);
  });
});

describe('pollLoginFlow', () => {
  const flow = { loginUrl: 'x', pollToken: 'tok', pollPath: '/login/v2/poll' };

  it('is null until access is granted (404)', async () => {
    fetchMock.mockResolvedValue(new Response('', { status: 404 }));
    await expect(pollLoginFlow(flow)).resolves.toBeNull();
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('http://nc/login/v2/poll');
    expect(init.body).toBe('token=tok');
  });

  it('returns the credentials, and ignores the server Nextcloud names', async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ server: 'https://evil.example', loginName: 'alice', appPassword: 'pw' })
      )
    );
    await expect(pollLoginFlow(flow)).resolves.toEqual({ loginName: 'alice', appPassword: 'pw' });
  });
});

describe('checkCredential', () => {
  it('reports the uid behind a valid app password', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ ocs: { data: { id: 'alice' } } })));
    await expect(checkCredential('alice@example.com', 'pw')).resolves.toEqual({
      state: 'valid',
      userId: 'alice',
    });
  });

  it('a 401 is a rejection; an outage is unknown', async () => {
    fetchMock.mockResolvedValueOnce(new Response('', { status: 401 }));
    await expect(checkCredential('a', 'pw')).resolves.toEqual({ state: 'rejected' });
    fetchMock.mockResolvedValueOnce(new Response('', { status: 503 }));
    await expect(checkCredential('a', 'pw')).resolves.toEqual({ state: 'unknown' });
    fetchMock.mockRejectedValueOnce(new Error('ECONNREFUSED'));
    await expect(checkCredential('a', 'pw')).resolves.toEqual({ state: 'unknown' });
  });
});

describe('deleteAppPassword', () => {
  it('deletes with the app password itself, and treats one already gone as deleted', async () => {
    fetchMock.mockResolvedValueOnce(new Response('', { status: 200 }));
    await expect(deleteAppPassword('alice', 'pw')).resolves.toBe(true);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('http://nc/ocs/v2.php/core/apppassword');
    expect(init.method).toBe('DELETE');
    expect(init.headers.Authorization).toBe(`Basic ${Buffer.from('alice:pw').toString('base64')}`);
    fetchMock.mockResolvedValueOnce(new Response('', { status: 401 }));
    await expect(deleteAppPassword('alice', 'pw')).resolves.toBe(true);
    fetchMock.mockRejectedValueOnce(new Error('down'));
    await expect(deleteAppPassword('alice', 'pw')).resolves.toBe(false);
  });
});
