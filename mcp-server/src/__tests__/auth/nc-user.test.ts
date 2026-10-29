// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { checkNextcloudUser } from '../../auth/nc-user.js';

function ocs(status: number, statuscode: number, data: unknown = []) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => ({ ocs: { meta: { statuscode }, data } }),
  };
}

describe('checkNextcloudUser', () => {
  const savedEnv = process.env;
  const fetchMock = vi.fn();

  beforeEach(() => {
    process.env = {
      ...savedEnv,
      NEXTCLOUD_URL: 'https://cloud.example.com',
      NEXTCLOUD_USER: 'admin',
      NEXTCLOUD_PASSWORD: 'pw',
    };
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
  });

  afterEach(() => {
    process.env = savedEnv;
    vi.unstubAllGlobals();
  });

  it('asks the provisioning API for the user, as the service account', async () => {
    fetchMock.mockResolvedValue(ocs(200, 200, { id: 'a b', enabled: true }));
    await expect(checkNextcloudUser('a b')).resolves.toBe('active');
    const [url, opts] = fetchMock.mock.calls[0];
    expect(url).toBe('https://cloud.example.com/ocs/v2.php/cloud/users/a%20b');
    expect(opts.headers.Authorization).toBe(`Basic ${Buffer.from('admin:pw').toString('base64')}`);
  });

  it('reports a disabled user', async () => {
    fetchMock.mockResolvedValue(ocs(200, 200, { id: 'u', enabled: false }));
    await expect(checkNextcloudUser('u')).resolves.toBe('disabled');
  });

  it('reports a deleted user (OCS 404)', async () => {
    fetchMock.mockResolvedValue(ocs(404, 404));
    await expect(checkNextcloudUser('u')).resolves.toBe('missing');
  });

  it('is unknown when the service account may not read users (OCS 998)', async () => {
    fetchMock.mockResolvedValue(ocs(404, 998));
    await expect(checkNextcloudUser('u')).resolves.toBe('unknown');
  });

  it('is unknown when Nextcloud is unreachable', async () => {
    fetchMock.mockRejectedValue(new Error('ECONNREFUSED'));
    await expect(checkNextcloudUser('u')).resolves.toBe('unknown');
  });
});
