// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

global.fetch = vi.fn();

const forbidden = (body: string) =>
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
    ok: false,
    status: 403,
    statusText: 'Forbidden',
    text: () => Promise.resolve(body),
  });

describe('fetchOCS 403 handling', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('passes on the reason Nextcloud gives', async () => {
    forbidden(
      JSON.stringify({
        ocs: { meta: { statuscode: 403, message: '' }, data: { message: 'No sent mailbox' } },
      })
    );

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(fetchOCS('/ocs/v2.php/apps/mail/message/send')).rejects.toThrow(
      'Permission denied: No sent mailbox'
    );
  });

  it('reads a reason given as a plain data string', async () => {
    forbidden(JSON.stringify({ ocs: { meta: { statuscode: 403 }, data: 'No sent mailbox set' } }));

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(fetchOCS('/ocs/v2.php/apps/mail/message/send')).rejects.toThrow(
      'Permission denied: No sent mailbox set'
    );
  });

  it('falls back to a neutral message without a reason', async () => {
    forbidden('<html>forbidden</html>');

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(fetchOCS('/ocs/v2.php/cloud/users')).rejects.toThrow(
      /^Permission denied: Nextcloud gave no reason\.$/
    );
  });

  it('does not blame admin rights for an empty OCS message', async () => {
    // Talk's answer to a non-moderator changing a conversation's read-only state.
    forbidden(
      JSON.stringify({
        ocs: { meta: { status: 'failure', statuscode: 403, message: '' }, data: [] },
      })
    );

    const { fetchOCS } = await import('../client/ocs.js');
    const err = await fetchOCS('/ocs/v2.php/apps/spreed/api/v4/room/t/read-only').catch(
      (e: unknown) => e as Error
    );
    expect(err).toBeInstanceOf(Error);
    expect((err as Error).message).toBe('Permission denied: Nextcloud gave no reason.');
    expect((err as Error).message).not.toMatch(/admin/);
  });
});
