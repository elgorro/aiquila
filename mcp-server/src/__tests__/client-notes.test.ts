// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { fetchNotesAPI } from '../client/notes.js';

describe('fetchNotesAPI', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function okJson(body: unknown) {
    return {
      ok: true,
      status: 200,
      statusText: 'OK',
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => body,
      text: async () => JSON.stringify(body),
    };
  }

  it('wraps unquoted If-Match etag in quotes (Notes API returns raw hash)', async () => {
    fetchMock.mockResolvedValue(okJson({ id: 1 }));

    await fetchNotesAPI('/notes/1', {
      method: 'PUT',
      ifMatch: '4257c67ab42607fb04f2f1302e2a9d4e',
      body: { title: 't', content: 'c', category: '', favorite: false },
    });

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>;
    expect(headers['If-Match']).toBe('"4257c67ab42607fb04f2f1302e2a9d4e"');
  });

  it('preserves already-quoted If-Match etag', async () => {
    fetchMock.mockResolvedValue(okJson({ id: 1 }));

    await fetchNotesAPI('/notes/1', {
      method: 'PUT',
      ifMatch: '"already-quoted"',
      body: {},
    });

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>;
    expect(headers['If-Match']).toBe('"already-quoted"');
  });

  it('omits If-Match header when ifMatch is not provided', async () => {
    fetchMock.mockResolvedValue(okJson({ id: 1 }));

    await fetchNotesAPI('/notes/1');

    const headers = fetchMock.mock.calls[0][1].headers as Record<string, string>;
    expect(headers['If-Match']).toBeUndefined();
  });
});
