// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

const respond = (statuscode: number, data: unknown) =>
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
    ok: true,
    status: statuscode,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: () => Promise.resolve({ ocs: { meta: { status: 'ok', statuscode }, data } }),
  });

describe('fetchFormsAPI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('accepts the 201 OCS v2 answers a create with', async () => {
    respond(201, { id: 5 });

    const { fetchFormsAPI } = await import('../client/forms.js');
    await expect(fetchFormsAPI('/forms', { method: 'POST' })).resolves.toEqual({ id: 5 });
  });

  it('rejects a non-2xx OCS status', async () => {
    respond(403, null);

    const { fetchFormsAPI } = await import('../client/forms.js');
    await expect(fetchFormsAPI('/forms/5')).rejects.toThrow();
  });
});
