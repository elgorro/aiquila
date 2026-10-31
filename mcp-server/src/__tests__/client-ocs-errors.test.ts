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

  it('falls back to the admin-rights hint without a reason', async () => {
    forbidden('<html>forbidden</html>');

    const { fetchOCS } = await import('../client/ocs.js');
    await expect(fetchOCS('/ocs/v2.php/cloud/users')).rejects.toThrow(
      /^Permission denied\. This operation requires admin/
    );
  });
});
