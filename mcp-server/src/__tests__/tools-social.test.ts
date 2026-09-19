// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ApiError } from '../client/aiquila.js';

// Mock the Social API client module
const mockFetchSocialAPI = vi.fn();

vi.mock('../client/social.js', () => ({
  fetchSocialAPI: (...args: unknown[]) => mockFetchSocialAPI(...args),
}));

const sampleAccount = {
  id: '12',
  username: 'alice',
  acct: 'alice',
  display_name: 'Alice',
  locked: false,
  bot: false,
  created_at: '2026-01-02T03:04:05.000Z',
  note: '<p>Gardener &amp; <b>bookbinder</b></p>',
  url: 'https://cloud.example.com/index.php/apps/social/@alice',
  avatar: 'https://cloud.example.com/avatar.png',
  followers_count: 42,
  following_count: 7,
  statuses_count: 130,
  last_status_at: '2026-09-18T10:00:00.000Z',
};

const sampleStatus = {
  id: '99',
  nid: 99,
  uri: 'https://cloud.example.com/index.php/apps/social/@alice/99',
  url: 'https://cloud.example.com/index.php/apps/social/@alice/99',
  created_at: '2026-09-18T10:00:00.000Z',
  edited_at: null,
  content: '<p>Hello <b>fediverse</b></p>',
  spoiler_text: '',
  sensitive: false,
  visibility: 'public',
  language: 'en',
  in_reply_to_id: null,
  replies_count: 1,
  reblogs_count: 2,
  favourites_count: 3,
  favourited: false,
  reblogged: false,
  bookmarked: false,
  pinned: false,
  account: sampleAccount,
  media_attachments: [],
  poll: null,
  reblog: null,
};

async function tool(name: string) {
  const { socialTools } = await import('../tools/apps/social.js');
  const found = socialTools.find((t) => t.name === name);
  expect(found, `tool ${name} is registered`).toBeDefined();
  return found!;
}

describe('Social Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('social_home_timeline', () => {
    it('reads the home timeline and renders each status', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleStatus]);

      const result = await (await tool('social_home_timeline')).handler({});

      // the trailing slash is part of the registered route
      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/timelines/home/', {
        queryParams: { limit: 20, max_id: undefined, min_id: undefined },
      });
      expect(result.content[0].text).toContain('Home timeline (1)');
      expect(result.content[0].text).toContain('Alice (@alice)');
      // HTML is stripped rather than passed through
      expect(result.content[0].text).toContain('Hello fediverse');
      expect(result.content[0].text).not.toContain('<b>');
    });

    it('clamps the limit to the 50 the server accepts', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      await (await tool('social_home_timeline')).handler({ limit: 500 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(
        '/api/v1/timelines/home/',
        expect.objectContaining({ queryParams: expect.objectContaining({ limit: 50 }) })
      );
    });

    it('reports an empty timeline', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (await tool('social_home_timeline')).handler({});

      expect(result.content[0].text).toContain('Home timeline is empty');
    });

    it('maps a 401 to a credentials message', async () => {
      mockFetchSocialAPI.mockRejectedValue(new ApiError(401, 'Unauthorized', ''));

      const result = await (await tool('social_home_timeline')).handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Not authenticated to Nextcloud Social');
    });
  });

  describe('social_public_timeline', () => {
    it('passes the local flag through', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleStatus]);

      const result = await (await tool('social_public_timeline')).handler({ local: true });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/timelines/public/', {
        queryParams: { limit: 20, max_id: undefined, min_id: undefined, local: true },
      });
      expect(result.content[0].text).toContain('Local timeline (1)');
    });
  });

  describe('social_hashtag_timeline', () => {
    it('strips a leading # and encodes the tag', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (
        await tool('social_hashtag_timeline')
      ).handler({ hashtag: '#nextcloud' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(
        '/api/v1/timelines/tag/nextcloud',
        expect.anything()
      );
      expect(result.content[0].text).toContain('No posts tagged #nextcloud');
    });
  });

  describe('social_list_timeline', () => {
    it('lists the available lists when no id is given', async () => {
      mockFetchSocialAPI.mockResolvedValue([{ id: 3, title: 'Colleagues' }]);

      const result = await (await tool('social_list_timeline')).handler({});

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/lists');
      expect(result.content[0].text).toContain('Colleagues');
      expect(result.content[0].text).toContain('ID: 3');
    });

    it('reads one list timeline when an id is given', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleStatus]);

      await (await tool('social_list_timeline')).handler({ list_id: 3 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(
        '/api/v1/timelines/list/3',
        expect.anything()
      );
    });
  });

  describe('social_list_saved_statuses', () => {
    it('keeps the trailing slash on favourites', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleStatus]);

      const result = await (
        await tool('social_list_saved_statuses')
      ).handler({
        kind: 'favourites',
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/favourites/', expect.anything());
      expect(result.content[0].text).toContain('Favourites (1)');
    });

    it('uses the slashless bookmarks route', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (
        await tool('social_list_saved_statuses')
      ).handler({
        kind: 'bookmarks',
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/bookmarks', expect.anything());
      expect(result.content[0].text).toContain('No bookmarks yet');
    });
  });

  describe('social_get_status', () => {
    it('reads one status without the thread by default', async () => {
      mockFetchSocialAPI.mockResolvedValue(sampleStatus);

      const result = await (await tool('social_get_status')).handler({ status_id: 99 });

      expect(mockFetchSocialAPI).toHaveBeenCalledTimes(1);
      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses/99');
      expect(result.content[0].text).toContain('ID: 99');
    });

    it('adds ancestors and descendants when asked for the context', async () => {
      mockFetchSocialAPI.mockResolvedValueOnce(sampleStatus).mockResolvedValueOnce({
        ancestors: [{ ...sampleStatus, id: '98', content: '<p>the question</p>' }],
        descendants: [{ ...sampleStatus, id: '100', content: '<p>the answer</p>' }],
      });

      const result = await (
        await tool('social_get_status')
      ).handler({
        status_id: 99,
        include_context: true,
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses/99/context');
      expect(result.content[0].text).toContain('In reply to (1)');
      expect(result.content[0].text).toContain('the question');
      expect(result.content[0].text).toContain('Replies (1)');
      expect(result.content[0].text).toContain('the answer');
    });

    it('maps a 404 to a plain sentence', async () => {
      mockFetchSocialAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const result = await (await tool('social_get_status')).handler({ status_id: 1 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No such status');
    });
  });

  describe('social_status_history', () => {
    it('renders each revision', async () => {
      mockFetchSocialAPI.mockResolvedValue([
        { created_at: '2026-09-18T10:00:00.000Z', content: '<p>first</p>' },
        { created_at: '2026-09-18T11:00:00.000Z', content: '<p>second</p>' },
      ]);

      const result = await (await tool('social_status_history')).handler({ status_id: 99 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses/99/history');
      expect(result.content[0].text).toContain('Edit history (2)');
      expect(result.content[0].text).toContain('Version 2');
    });

    it('says so when the post was never edited', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (await tool('social_status_history')).handler({ status_id: 99 });

      expect(result.content[0].text).toContain('never been edited');
    });
  });

  describe('social_upload_media', () => {
    it('stages a Nextcloud file rather than uploading bytes', async () => {
      mockFetchSocialAPI.mockResolvedValue({
        id: '77',
        type: 'image',
        url: 'https://cloud.example.com/media/77',
        preview_url: null,
        remote_url: null,
        description: 'A sunset',
      });

      const result = await (
        await tool('social_upload_media')
      ).handler({
        path: 'Photos/sunset.jpg',
        description: 'A sunset',
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/media/from-file', {
        method: 'POST',
        body: { path: 'Photos/sunset.jpg', description: 'A sunset' },
      });
      expect(result.content[0].text).toContain('media ID: 77');
    });

    it('names the missing path on a 404', async () => {
      mockFetchSocialAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const result = await (await tool('social_upload_media')).handler({ path: 'Photos/gone.jpg' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Photos/gone.jpg');
    });
  });

  describe('social_post_status', () => {
    it('defaults to public visibility', async () => {
      mockFetchSocialAPI.mockResolvedValue(sampleStatus);

      await (await tool('social_post_status')).handler({ status: 'Hello' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses', {
        method: 'POST',
        body: { status: 'Hello', visibility: 'public' },
      });
    });

    it('passes visibility, content warning, media and poll through', async () => {
      mockFetchSocialAPI.mockResolvedValue(sampleStatus);

      const result = await (
        await tool('social_post_status')
      ).handler({
        status: 'Vote please',
        visibility: 'unlisted',
        spoiler_text: 'politics',
        sensitive: true,
        media_ids: ['77'],
        in_reply_to_id: 98,
        language: 'en',
        poll: { options: ['yes', 'no'], expires_in: 86400, multiple: false },
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses', {
        method: 'POST',
        body: {
          status: 'Vote please',
          visibility: 'unlisted',
          in_reply_to_id: 98,
          media_ids: ['77'],
          poll: { options: ['yes', 'no'], expires_in: 86400, multiple: false },
          spoiler_text: 'politics',
          sensitive: true,
          language: 'en',
        },
      });
      expect(result.content[0].text).toContain('Posted (ID: 99');
    });

    it('warns that the post is public and federated', async () => {
      const posting = await tool('social_post_status');
      expect(posting.description).toContain('cannot be recalled');
      expect(posting.description).toContain('world-readable');
    });
  });

  describe('social_delete_status', () => {
    it('deletes by id', async () => {
      mockFetchSocialAPI.mockResolvedValue(undefined);

      const result = await (await tool('social_delete_status')).handler({ status_id: 99 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/statuses/99', { method: 'DELETE' });
      expect(result.content[0].text).toContain('Deleted status 99');
    });
  });

  describe('interaction tools', () => {
    const cases: [string, string][] = [
      ['social_favourite', 'favourite'],
      ['social_unfavourite', 'unfavourite'],
      ['social_boost', 'reblog'],
      ['social_unboost', 'unreblog'],
      ['social_bookmark', 'bookmark'],
      ['social_unbookmark', 'unbookmark'],
    ];

    it.each(cases)('%s posts the %s action', async (name, act) => {
      mockFetchSocialAPI.mockResolvedValue(sampleStatus);

      await (await tool(name)).handler({ status_id: 99 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(`/api/v1/statuses/99/${act}`, {
        method: 'POST',
      });
    });
  });

  describe('social_lookup_account', () => {
    it('strips a leading @ and renders the profile', async () => {
      mockFetchSocialAPI.mockResolvedValue(sampleAccount);

      const result = await (await tool('social_lookup_account')).handler({ acct: '@alice' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/accounts/lookup', {
        queryParams: { acct: 'alice' },
      });
      expect(result.content[0].text).toContain('42 followers');
      // the bio is HTML and gets flattened
      expect(result.content[0].text).toContain('Gardener');
      expect(result.content[0].text).not.toContain('<b>');
    });
  });

  describe('social_account_statuses', () => {
    it('encodes a remote handle into the path', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleStatus]);

      await (await tool('social_account_statuses')).handler({ account: '@bob@remote.example' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(
        '/api/v1/accounts/bob%40remote.example/statuses',
        expect.anything()
      );
    });
  });

  describe('social_list_account_follows', () => {
    it('reads followers', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleAccount]);

      const result = await (
        await tool('social_list_account_follows')
      ).handler({
        account: 'alice',
        direction: 'followers',
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/accounts/alice/followers', {
        queryParams: { limit: 20, max_id: undefined, min_id: undefined },
      });
      expect(result.content[0].text).toContain('Followers of alice (1)');
    });

    it('reports an empty following list', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (
        await tool('social_list_account_follows')
      ).handler({
        account: 'alice',
        direction: 'following',
      });

      expect(result.content[0].text).toContain('No following found for alice');
    });
  });

  describe('follow tools', () => {
    it('follows an account', async () => {
      mockFetchSocialAPI.mockResolvedValue({});

      const result = await (await tool('social_follow_account')).handler({ account: 'alice' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/accounts/alice/follow', {
        method: 'POST',
      });
      expect(result.content[0].text).toContain('Now following alice');
    });

    it('unfollows an account', async () => {
      mockFetchSocialAPI.mockResolvedValue({});

      const result = await (await tool('social_unfollow_account')).handler({ account: 'alice' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/accounts/alice/unfollow', {
        method: 'POST',
      });
      expect(result.content[0].text).toContain('No longer following alice');
    });
  });

  describe('follow requests', () => {
    it('lists pending requests', async () => {
      mockFetchSocialAPI.mockResolvedValue([sampleAccount]);

      const result = await (await tool('social_list_follow_requests')).handler();

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/follow_requests');
      expect(result.content[0].text).toContain('Pending follow requests (1)');
    });

    it('says so when there are none', async () => {
      mockFetchSocialAPI.mockResolvedValue([]);

      const result = await (await tool('social_list_follow_requests')).handler();

      expect(result.content[0].text).toContain('No pending follow requests');
    });

    it('authorizes a request', async () => {
      mockFetchSocialAPI.mockResolvedValue({});

      const result = await (
        await tool('social_respond_follow_request')
      ).handler({
        account_id: '12',
        action: 'authorize',
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/follow_requests/12/authorize', {
        method: 'POST',
      });
      expect(result.content[0].text).toContain('Approved');
    });
  });

  describe('social_list_notifications', () => {
    it('renders grouped notifications with their referenced entities', async () => {
      mockFetchSocialAPI.mockResolvedValue({
        notification_groups: [
          {
            group_key: 'favourite-99',
            notifications_count: 3,
            type: 'favourite',
            most_recent_notification_id: '501',
            latest_page_notification_at: '2026-09-18T12:00:00.000Z',
            sample_account_ids: ['12'],
            status_id: '99',
          },
        ],
        accounts: [sampleAccount],
        statuses: [sampleStatus],
      });

      const result = await (await tool('social_list_notifications')).handler({});

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v2/notifications', {
        queryParams: { limit: 20, max_id: undefined },
      });
      expect(result.content[0].text).toContain('favourite');
      expect(result.content[0].text).toContain('Alice (@alice)');
      expect(result.content[0].text).toContain('and 2 more');
      expect(result.content[0].text).toContain('Status 99: Hello fediverse');
    });

    it('sends type filters as repeated array params', async () => {
      mockFetchSocialAPI.mockResolvedValue({ notification_groups: [] });

      await (
        await tool('social_list_notifications')
      ).handler({
        types: ['mention', 'follow'],
        exclude_types: ['favourite'],
      });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v2/notifications', {
        queryParams: {
          limit: 20,
          max_id: undefined,
          'types[0]': 'mention',
          'types[1]': 'follow',
          'exclude_types[0]': 'favourite',
        },
      });
    });
  });

  describe('social_dismiss_notifications', () => {
    it('clears everything when no id is given', async () => {
      mockFetchSocialAPI.mockResolvedValue(undefined);

      const result = await (await tool('social_dismiss_notifications')).handler({});

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/notifications/clear', {
        method: 'POST',
      });
      expect(result.content[0].text).toContain('Cleared all notifications');
    });

    it('dismisses one notification by id', async () => {
      mockFetchSocialAPI.mockResolvedValue(undefined);

      await (await tool('social_dismiss_notifications')).handler({ notification_id: 501 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v1/notifications/501/dismiss', {
        method: 'POST',
      });
    });
  });

  describe('social_search', () => {
    it('renders each kind of result', async () => {
      mockFetchSocialAPI.mockResolvedValue({
        accounts: [sampleAccount],
        statuses: [sampleStatus],
        hashtags: [{ name: 'nextcloud', url: 'https://cloud.example.com/tags/nextcloud' }],
      });

      const result = await (await tool('social_search')).handler({ q: 'nextcloud' });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith('/api/v2/search', {
        queryParams: { q: 'nextcloud', type: '', limit: 20, resolve: false },
      });
      expect(result.content[0].text).toContain('Accounts (1)');
      expect(result.content[0].text).toContain('Hashtags (1)');
      expect(result.content[0].text).toContain('Statuses (1)');
    });

    it('clamps the limit to 40', async () => {
      mockFetchSocialAPI.mockResolvedValue({ accounts: [], statuses: [], hashtags: [] });

      const result = await (await tool('social_search')).handler({ q: 'x', limit: 999 });

      expect(mockFetchSocialAPI).toHaveBeenCalledWith(
        '/api/v2/search',
        expect.objectContaining({ queryParams: expect.objectContaining({ limit: 40 }) })
      );
      expect(result.content[0].text).toContain('No results for "x"');
    });
  });

  describe('module shape', () => {
    it('exports the expected number of tools', async () => {
      const { socialTools } = await import('../tools/apps/social.js');
      expect(socialTools).toHaveLength(26);
    });

    it('gives every tool a name, title, description and schema', async () => {
      const { socialTools } = await import('../tools/apps/social.js');
      for (const t of socialTools) {
        expect(t.name).toMatch(/^social_/);
        expect(t.title.length).toBeGreaterThan(0);
        expect(t.description.length).toBeGreaterThan(0);
        expect(t.inputSchema).toBeDefined();
        expect(typeof t.handler).toBe('function');
      }
    });

    it('marks every federating write tool as reaching the open world', async () => {
      const { socialTools } = await import('../tools/apps/social.js');
      const writes = socialTools.filter((t) => !t.annotations.readOnlyHint);
      // social_upload_media stages a local file and is the only write that does not federate
      const local = writes.filter((t) => !t.annotations.openWorldHint).map((t) => t.name);
      expect(local).toEqual(['social_upload_media', 'social_dismiss_notifications']);
    });
  });
});
