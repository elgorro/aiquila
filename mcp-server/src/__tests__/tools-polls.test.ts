// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchPollsAPI = vi.fn();
vi.mock('../client/polls.js', () => ({
  fetchPollsAPI: (...args: unknown[]) => mockFetchPollsAPI(...args),
}));

const samplePoll = {
  id: 7,
  type: 'textPoll',
  configuration: {
    title: 'Lunch',
    description: 'Where to eat?',
    access: 'private',
    expire: 0,
    showResults: 'always',
    allowComment: true,
    allowMaybe: false,
    anonymous: false,
    useNo: true,
  },
  owner: { userId: 'alice', displayName: 'Alice' },
  status: { isArchived: false, isExpired: false },
  currentUserStatus: { userRole: 'owner', countVotes: 0 },
};

describe('Polls Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_polls', () => {
    it('returns a formatted poll list', async () => {
      // GET /polls answers { polls: [...] }
      mockFetchPollsAPI.mockResolvedValue({
        polls: [
          samplePoll,
          {
            ...samplePoll,
            id: 8,
            configuration: { ...samplePoll.configuration, title: 'Sprint dates' },
            type: 'datePoll',
          },
        ],
      });

      const { listPollsTool } = await import('../tools/apps/polls.js');
      const result = await listPollsTool.handler();

      expect(result.content[0].text).toContain('Polls (2)');
      expect(result.content[0].text).toContain('Lunch');
      expect(result.content[0].text).toContain('Sprint dates');
    });

    it('handles empty list', async () => {
      mockFetchPollsAPI.mockResolvedValue({ polls: [] });
      const { listPollsTool } = await import('../tools/apps/polls.js');
      const result = await listPollsTool.handler();
      expect(result.content[0].text).toContain('No polls found');
    });

    it('maps 403 to access denied', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchPollsAPI.mockRejectedValue(new ApiError(403, 'Forbidden', ''));
      const { listPollsTool } = await import('../tools/apps/polls.js');
      const result = await listPollsTool.handler();
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Access denied');
    });
  });

  describe('get_poll', () => {
    it('renders details', async () => {
      // GET /poll/{id} carries `subscribed` beside the poll
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll, subscribed: true });
      const { getPollTool } = await import('../tools/apps/polls.js');
      const result = await getPollTool.handler({ pollId: 7 });
      expect(result.content[0].text).toContain('Lunch');
      expect(result.content[0].text).toContain('Description: Where to eat?');
      expect(result.content[0].text).toContain('subscribed=true');
    });

    it('handles 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchPollsAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { getPollTool } = await import('../tools/apps/polls.js');
      const result = await getPollTool.handler({ pollId: 999 });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_poll', () => {
    it('posts title and type', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll });
      const { createPollTool } = await import('../tools/apps/polls.js');
      const result = await createPollTool.handler({ title: 'Lunch', type: 'textPoll' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll', {
        method: 'POST',
        body: { title: 'Lunch', type: 'textPoll' },
      });
      expect(result.content[0].text).toContain('Poll created');
    });
  });

  describe('update_poll', () => {
    it('wraps fields in { pollConfiguration: ... }', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll });
      const { updatePollTool } = await import('../tools/apps/polls.js');
      await updatePollTool.handler({ pollId: 7, title: 'New', anonymous: true });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7', {
        method: 'PUT',
        body: { pollConfiguration: { title: 'New', anonymous: true } },
      });
    });

    it('maps a boolean allowProposals to the stored string', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll });
      const { updatePollTool } = await import('../tools/apps/polls.js');
      await updatePollTool.handler({ pollId: 7, allowProposals: true });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7', {
        method: 'PUT',
        body: { pollConfiguration: { allowProposals: 'allow' } },
      });
    });

    it('errors if no fields provided', async () => {
      const { updatePollTool } = await import('../tools/apps/polls.js');
      const result = await updatePollTool.handler({ pollId: 7 });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No fields');
      expect(mockFetchPollsAPI).not.toHaveBeenCalled();
    });
  });

  describe('delete_poll', () => {
    it('calls DELETE', async () => {
      mockFetchPollsAPI.mockResolvedValue(undefined);
      const { deletePollTool } = await import('../tools/apps/polls.js');
      const result = await deletePollTool.handler({ pollId: 7 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7', { method: 'DELETE' });
      expect(result.content[0].text).toContain('deleted');
    });
  });

  describe('close_poll / reopen_poll', () => {
    it('close hits the /close endpoint', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll });
      const { closePollTool } = await import('../tools/apps/polls.js');
      await closePollTool.handler({ pollId: 7 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/close', { method: 'PUT' });
    });

    it('reopen hits the /reopen endpoint', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: samplePoll });
      const { reopenPollTool } = await import('../tools/apps/polls.js');
      await reopenPollTool.handler({ pollId: 7 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/reopen', { method: 'PUT' });
    });
  });

  describe('clone_poll', () => {
    it('posts to /clone', async () => {
      mockFetchPollsAPI.mockResolvedValue({ poll: { ...samplePoll, id: 8 } });
      const { clonePollTool } = await import('../tools/apps/polls.js');
      const result = await clonePollTool.handler({ pollId: 7 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/clone', { method: 'POST' });
      expect(result.content[0].text).toContain('cloned');
    });
  });

  describe('list_poll_options', () => {
    it('formats options with tallies', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        options: [
          { id: 1, pollId: 7, text: 'Pizza', votes: { yes: 3, no: 0, maybe: 1, count: 4 } },
          { id: 2, pollId: 7, text: 'Sushi', votes: { yes: 2, no: 1, maybe: 0, count: 3 } },
        ],
      });
      const { listPollOptionsTool } = await import('../tools/apps/polls.js');
      const result = await listPollOptionsTool.handler({ pollId: 7 });
      expect(result.content[0].text).toContain('Options (2)');
      expect(result.content[0].text).toContain('Pizza');
      expect(result.content[0].text).toContain('yes=3');
    });
  });

  describe('add_text_poll_option', () => {
    it('wraps the text in an option object', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        option: { id: 10, pollId: 7, text: 'Ramen' },
      });
      const { addTextPollOptionTool } = await import('../tools/apps/polls.js');
      await addTextPollOptionTool.handler({ pollId: 7, text: 'Ramen' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/option', {
        method: 'POST',
        body: { option: { text: 'Ramen' } },
      });
    });
  });

  describe('add_date_poll_option', () => {
    it('converts ISO to unix seconds and wraps in option object', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        option: { id: 11, pollId: 7, timestamp: 1810483200, duration: 3600 },
      });
      const { addDatePollOptionTool } = await import('../tools/apps/polls.js');
      await addDatePollOptionTool.handler({
        pollId: 7,
        startAt: '2027-05-12T14:00:00Z',
        durationSeconds: 3600,
      });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/option', {
        method: 'POST',
        body: {
          option: {
            timestamp: Math.floor(Date.parse('2027-05-12T14:00:00Z') / 1000),
            duration: 3600,
          },
        },
      });
    });

    it('rejects invalid ISO strings', async () => {
      const { addDatePollOptionTool } = await import('../tools/apps/polls.js');
      const result = await addDatePollOptionTool.handler({
        pollId: 7,
        startAt: 'not-a-date',
        durationSeconds: 3600,
      });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Invalid startAt');
      expect(mockFetchPollsAPI).not.toHaveBeenCalled();
    });
  });

  describe('delete_poll_option', () => {
    it('calls DELETE on /option/{id}', async () => {
      mockFetchPollsAPI.mockResolvedValue(undefined);
      const { deletePollOptionTool } = await import('../tools/apps/polls.js');
      await deletePollOptionTool.handler({ optionId: 10 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/option/10', { method: 'DELETE' });
    });
  });

  describe('list_poll_votes', () => {
    it('formats votes', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        votes: [
          {
            id: 1,
            pollId: 7,
            user: { userId: 'alice', displayName: 'alice' },
            answer: 'yes',
            optionText: 'Pizza',
          },
          {
            id: 2,
            pollId: 7,
            user: { userId: 'bob', displayName: 'bob' },
            answer: 'no',
            optionText: 'Sushi',
          },
        ],
      });
      const { listPollVotesTool } = await import('../tools/apps/polls.js');
      const result = await listPollVotesTool.handler({ pollId: 7 });
      expect(result.content[0].text).toContain('Votes (2)');
      expect(result.content[0].text).toContain('alice → yes');
    });
  });

  describe('vote_on_poll', () => {
    it('PUTs the answer to /option/{id}/vote/{answer}', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        vote: {
          id: 5,
          pollId: 7,
          user: { userId: 'alice', displayName: 'Alice' },
          answer: 'yes',
          optionText: 'Pizza',
        },
      });
      const { voteOnPollTool } = await import('../tools/apps/polls.js');
      const result = await voteOnPollTool.handler({ optionId: 1, setTo: 'yes' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/option/1/vote/yes', { method: 'PUT' });
      expect(result.content[0].text).toContain('Alice → yes on Pizza');
    });
  });

  describe('comments', () => {
    it('list_poll_comments formats output', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        comments: [
          {
            id: 1,
            pollId: 7,
            user: { userId: 'alice', displayName: 'Alice' },
            comment: 'Looks good',
            timestamp: 1714078369,
          },
        ],
      });
      const { listPollCommentsTool } = await import('../tools/apps/polls.js');
      const result = await listPollCommentsTool.handler({ pollId: 7 });
      expect(result.content[0].text).toContain('Comments (1)');
      expect(result.content[0].text).toContain('Looks good');
    });

    it('add_poll_comment posts to /poll/{id}/comment', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        comment: {
          id: 2,
          pollId: 7,
          user: { userId: 'alice', displayName: 'Alice' },
          comment: 'Hi',
        },
      });
      const { addPollCommentTool } = await import('../tools/apps/polls.js');
      await addPollCommentTool.handler({ pollId: 7, message: 'Hi' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/comment', {
        method: 'POST',
        body: { comment: 'Hi' },
      });
    });

    it('delete_poll_comment calls DELETE', async () => {
      mockFetchPollsAPI.mockResolvedValue(undefined);
      const { deletePollCommentTool } = await import('../tools/apps/polls.js');
      await deletePollCommentTool.handler({ commentId: 2 });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/comment/2', { method: 'DELETE' });
    });
  });

  describe('shares', () => {
    it('list_poll_shares formats shares', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        shares: [
          { id: 1, pollId: 7, type: 'public', token: 'abc', URL: 'https://example.com/s/abc' },
          {
            id: 2,
            pollId: 7,
            type: 'user',
            token: 'def',
            user: { userId: 'bob', displayName: 'Bob' },
          },
        ],
      });
      const { listPollSharesTool } = await import('../tools/apps/polls.js');
      const result = await listPollSharesTool.handler({ pollId: 7 });
      expect(result.content[0].text).toContain('Shares (2)');
      expect(result.content[0].text).toContain('[public]');
      expect(result.content[0].text).toContain('[user]');
    });

    it('add_poll_share public posts to /share/public', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        share: { id: 1, pollId: 7, type: 'public', token: 'xyz' },
      });
      const { addPollShareTool } = await import('../tools/apps/polls.js');
      await addPollShareTool.handler({ pollId: 7, type: 'public' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/share/public', {
        method: 'POST',
        body: {},
      });
    });

    it('add_poll_share user requires userId', async () => {
      const { addPollShareTool } = await import('../tools/apps/polls.js');
      const result = await addPollShareTool.handler({ pollId: 7, type: 'user' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('userId');
      expect(mockFetchPollsAPI).not.toHaveBeenCalled();
    });

    it('add_poll_share email requires userId and displayName', async () => {
      const { addPollShareTool } = await import('../tools/apps/polls.js');
      const result = await addPollShareTool.handler({
        pollId: 7,
        type: 'email',
        userId: 'bob@example.com',
      });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('displayName');
    });

    it('add_poll_share email success', async () => {
      mockFetchPollsAPI.mockResolvedValue({
        share: { id: 3, pollId: 7, type: 'external', token: 'e1' },
      });
      const { addPollShareTool } = await import('../tools/apps/polls.js');
      await addPollShareTool.handler({
        pollId: 7,
        type: 'email',
        userId: 'bob@example.com',
        displayName: 'Bob',
      });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/share/email', {
        method: 'POST',
        body: { emailAddress: 'bob@example.com', displayName: 'Bob' },
      });
    });

    it('delete_poll_share uses token', async () => {
      mockFetchPollsAPI.mockResolvedValue(undefined);
      const { deletePollShareTool } = await import('../tools/apps/polls.js');
      await deletePollShareTool.handler({ token: 'abc' });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/share/abc', { method: 'DELETE' });
    });
  });

  describe('set_poll_subscription', () => {
    it('subscribe uses PUT', async () => {
      mockFetchPollsAPI.mockResolvedValue({ pollId: 7, subscribed: true });
      const { setPollSubscriptionTool } = await import('../tools/apps/polls.js');
      const result = await setPollSubscriptionTool.handler({ pollId: 7, subscribe: true });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/subscription', { method: 'PUT' });
      expect(result.content[0].text).toContain('Subscribed');
    });

    it('unsubscribe uses DELETE', async () => {
      mockFetchPollsAPI.mockResolvedValue({ pollId: 7, subscribed: false });
      const { setPollSubscriptionTool } = await import('../tools/apps/polls.js');
      const result = await setPollSubscriptionTool.handler({ pollId: 7, subscribe: false });
      expect(mockFetchPollsAPI).toHaveBeenCalledWith('/poll/7/subscription', {
        method: 'DELETE',
      });
      expect(result.content[0].text).toContain('Unsubscribed');
    });

    it('reports a subscription polls refused', async () => {
      // Polls answers subscribed=false when the account has no email address
      mockFetchPollsAPI.mockResolvedValue({ pollId: 7, subscribed: false });
      const { setPollSubscriptionTool } = await import('../tools/apps/polls.js');
      const result = await setPollSubscriptionTool.handler({ pollId: 7, subscribe: true });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('email address');
    });
  });

  describe('pollsTools array', () => {
    it('exports 21 tools with valid shape', async () => {
      const { pollsTools } = await import('../tools/apps/polls.js');
      expect(pollsTools).toHaveLength(21);
      for (const tool of pollsTools) {
        expect(tool.name).toMatch(/^[a-z_]+$/);
        expect(tool.description.length).toBeGreaterThan(0);
        expect(tool.inputSchema).toBeDefined();
        expect(typeof tool.handler).toBe('function');
      }
    });
  });
});
