// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

/** A 304 response carries no body: `text()`/`json()` resolve to empty. */
function notModified(): Response {
  return {
    ok: false,
    status: 304,
    statusText: 'Not Modified',
    text: async () => '',
    json: async () => {
      throw new SyntaxError('Unexpected end of JSON input');
    },
  } as unknown as Response;
}

function okEnvelope(data: unknown): Response {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    json: async () => ({
      ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data },
    }),
  } as unknown as Response;
}

describe('fetchOCS', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('resolves with null on 304 when the caller opts in', async () => {
    vi.mocked(global.fetch).mockResolvedValue(notModified());

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(
      fetchOCS('/ocs/v2.php/apps/spreed/api/v1/chat/abc', { allowNotModified: true })
    ).resolves.toBeNull();
  });

  it('still throws on 304 for callers that did not opt in', async () => {
    vi.mocked(global.fetch).mockResolvedValue(notModified());

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(fetchOCS('/ocs/v2.php/cloud/users')).rejects.toThrow('OCS API error: 304');
  });

  it('does not change the happy path for opted-in callers', async () => {
    vi.mocked(global.fetch).mockResolvedValue(okEnvelope([{ id: 1 }]));

    const { fetchOCS } = await import('../client/ocs.js');
    const res = await fetchOCS<{ id: number }[]>('/ocs/v2.php/apps/spreed/api/v1/chat/abc', {
      allowNotModified: true,
    });
    expect(res?.ocs.data).toEqual([{ id: 1 }]);
  });
});

describe('talk_list_messages on an exhausted page', () => {
  beforeEach(() => {
    vi.resetModules();
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('reports "no messages" instead of surfacing the 304 as an error', async () => {
    vi.mocked(global.fetch).mockResolvedValue(notModified());

    const { listMessagesTool } = await import('../tools/apps/talk.js');
    const result = await listMessagesTool.handler({ token: 'abc', lastKnownMessageId: 42 });

    expect(result).not.toHaveProperty('isError');
    expect(result.content[0].text).toContain('No messages found.');
  });
});
