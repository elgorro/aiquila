// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

describe('fetchMapsAPI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('calls the controller through index.php', async () => {
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: () => Promise.resolve([]),
      text: () => Promise.resolve('[]'),
    });

    const { fetchMapsAPI } = await import('../client/maps.js');
    await fetchMapsAPI('/favorites');

    // /apps/maps/... only resolves with pretty URLs; elsewhere it is a 404
    const [url, init] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toBe('https://cloud.example.com/index.php/apps/maps/favorites');
    expect(init.headers['OCS-APIRequest']).toBe('true');
  });
});
