// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

describe('fetchPollsAPI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('calls the OCS route and returns the ocs.data payload', async () => {
    // Polls serves api/v1.0 as #[ApiRoute]; /index.php/... answers 405
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json; charset=utf-8' }),
      json: () =>
        Promise.resolve({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { polls: [] } } }),
    });

    const { fetchPollsAPI } = await import('../client/polls.js');
    const data = await fetchPollsAPI('/polls');

    const [url, init] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(url).toBe('https://cloud.example.com/ocs/v2.php/apps/polls/api/v1.0/polls');
    expect(init.headers['OCS-APIRequest']).toBe('true');
    expect(data).toEqual({ polls: [] });
  });
});
