// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

const respond = (json: unknown) =>
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
    ok: true,
    status: 200,
    json: () => Promise.resolve(json),
  });

describe('fetchBookmarksAPI', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('encodes array values as key[]= once', async () => {
    respond({ status: 'success', data: [] });

    const { fetchBookmarksAPI } = await import('../client/bookmarks.js');
    await fetchBookmarksAPI('/bookmark', { queryParams: { search: ['a b'], tags: ['x', 'y'] } });

    const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
    // search[][]= reaches the app as a nested array and fails with a 500
    expect(url).toBe(
      'https://cloud.example.com/index.php/apps/bookmarks/public/rest/v2/bookmark' +
        '?search[]=a%20b&tags[]=x&tags[]=y'
    );
  });

  it('returns the bare array GET /tag answers with', async () => {
    respond(['news', 'tech']);

    const { fetchBookmarksAPI } = await import('../client/bookmarks.js');
    expect(await fetchBookmarksAPI('/tag')).toEqual(['news', 'tech']);
  });

  it('rejects a status other than success', async () => {
    respond({ status: 'error', data: ['Not found'] });

    const { fetchBookmarksAPI } = await import('../client/bookmarks.js');
    await expect(fetchBookmarksAPI('/bookmark/1')).rejects.toThrow('Bookmarks API error');
  });
});
