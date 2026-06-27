// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const ocsWrap = <T>(data: T) => ({
  ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data },
});

/**
 * Route mock responses by OCS path: the shares endpoint returns the given share,
 * the apps endpoint returns the given enabled-app list.
 */
function setupMocks(opts: { share?: unknown; sharesList?: unknown[]; enabledApps: string[] }) {
  mockFetchOCS.mockImplementation((path: string) => {
    if (path.startsWith('/ocs/v2.php/cloud/apps')) {
      return Promise.resolve(ocsWrap({ apps: opts.enabledApps }));
    }
    if (path.includes('/files_sharing/api/v1/shares')) {
      if (opts.sharesList !== undefined) return Promise.resolve(ocsWrap(opts.sharesList));
      return Promise.resolve(ocsWrap([opts.share]));
    }
    throw new Error(`Unexpected path ${path}`);
  });
}

describe('Social Sharing Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('resolves a share by id and emits enabled-network URLs with the token', async () => {
    setupMocks({
      share: { id: 7, share_type: 3, token: 'tok123', url: 'https://cloud.example.com/s/tok123' },
      enabledApps: ['files', 'socialsharing_twitter', 'socialsharing_facebook'],
    });

    const { generateSocialShareLinksTool } = await import('../tools/apps/social-sharing.js');
    const result = await generateSocialShareLinksTool.handler({ share_id: 7 });

    expect(result.isError).toBeUndefined();
    const text = result.content[0].text;
    expect(text).toContain('twitter:');
    expect(text).toContain('facebook:');
    expect(text).toContain('tok123');
    // email app not enabled → not listed
    expect(text).not.toContain('email:');
  });

  it('honors the networks filter', async () => {
    setupMocks({
      share: { id: 7, share_type: 3, token: 'tok123' },
      enabledApps: ['socialsharing_twitter', 'socialsharing_facebook'],
    });

    const { generateSocialShareLinksTool } = await import('../tools/apps/social-sharing.js');
    const result = await generateSocialShareLinksTool.handler({
      share_id: 7,
      networks: ['twitter'],
    });

    const text = result.content[0].text;
    expect(text).toContain('twitter:');
    expect(text).not.toContain('facebook:');
  });

  it('finds the public-link share by path', async () => {
    setupMocks({
      sharesList: [
        { id: 1, share_type: 0, token: undefined },
        { id: 2, share_type: 3, token: 'pubtok' },
      ],
      enabledApps: ['socialsharing_email'],
    });

    const { generateSocialShareLinksTool } = await import('../tools/apps/social-sharing.js');
    const result = await generateSocialShareLinksTool.handler({ path: '/Documents/file.txt' });

    expect(result.isError).toBeUndefined();
    expect(result.content[0].text).toContain('pubtok');
  });

  it('errors when the share is not a public link', async () => {
    setupMocks({
      share: { id: 9, share_type: 0, token: undefined },
      enabledApps: ['socialsharing_twitter'],
    });

    const { generateSocialShareLinksTool } = await import('../tools/apps/social-sharing.js');
    const result = await generateSocialShareLinksTool.handler({ share_id: 9 });

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('not a public link');
  });

  it('errors when neither share_id nor path is provided', async () => {
    const { generateSocialShareLinksTool } = await import('../tools/apps/social-sharing.js');
    const result = await generateSocialShareLinksTool.handler({});

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('share_id or path');
  });
});
