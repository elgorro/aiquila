// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { _resetCapabilitiesCache } from '../client/capabilities.js';

// Mock fetch for OCS
global.fetch = vi.fn();

describe('Talk Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
    _resetCapabilitiesCache();
  });

  function okResponse(data: unknown) {
    const body = { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
    return {
      ok: true,
      status: 200,
      json: () => Promise.resolve(body),
      text: () => Promise.resolve(JSON.stringify(body)),
    };
  }

  const TALK_25_FEATURES = [
    'classified-conversations',
    'announcement-preset',
    'message-expiration',
  ];
  const PRESETS = [
    { identifier: 'default', parameters: { readOnly: 0, listable: 0, permissions: 0 } },
    { identifier: 'forced', parameters: [] },
    {
      identifier: 'classified',
      parameters: { roomType: 2, listable: 0, sipEnabled: 0, messageExpiration: 604800 },
    },
    { identifier: 'channel', parameters: { listable: 1, permissions: 257 } },
  ];

  /** Answers capabilities and presets, and records writes to the room API. */
  function mockTalkServer(features: string[] | null, createdRoom: unknown = {}) {
    const writes: { url: string; method: string; body: string }[] = [];
    (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(
      async (url: string, init: { method: string; body: string }) => {
        if (url.includes('/cloud/capabilities')) {
          if (features === null) throw new Error('unreachable');
          return okResponse({ capabilities: { spreed: { features } } });
        }
        if (url.includes('/presets/room')) return okResponse(PRESETS);
        writes.push({ url, method: init.method, body: init.body });
        return okResponse(createdRoom);
      }
    );
    return writes;
  }

  function mockOCS<T>(data: T) {
    const ocsResponse = {
      ocs: {
        meta: { status: 'ok', statuscode: 200, message: 'OK' },
        data,
      },
    };
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: true,
      status: 200,
      json: () => Promise.resolve(ocsResponse),
      text: () => Promise.resolve(JSON.stringify(ocsResponse)),
    });
  }

  function mockAPIError(status = 500) {
    (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
      ok: false,
      status,
      statusText: 'Internal Server Error',
      text: () => Promise.resolve('Server error'),
    });
  }

  // ── list_conversations ──────────────────────────────────────────────

  describe('list_conversations', () => {
    it('names classified, channel and announcement conversations', async () => {
      const room = { name: 'r', displayName: 'r', type: 2, unreadMessages: 0, lastActivity: 0 };
      mockOCS([
        { ...room, token: 'a', attributes: 4 },
        { ...room, token: 'b', attributes: 8 },
        { ...room, token: 'c', attributes: 24 },
        { ...room, token: 'd', attributes: 2 },
      ]);

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const lines = (await listConversationsTool.handler({})).content[0].text.split('\n');

      expect(lines[0]).toContain('(group, classified,');
      expect(lines[1]).toContain('(group, channel,');
      expect(lines[2]).toContain('(group, announcement,');
      expect(lines[3]).toContain('(group, unread');
    });

    it('should list conversations', async () => {
      mockOCS([
        {
          token: 'abc123',
          name: 'General',
          displayName: 'General',
          type: 2,
          unreadMessages: 3,
          lastActivity: 1700000000,
        },
        {
          token: 'def456',
          name: 'DM',
          displayName: 'Alice',
          type: 1,
          unreadMessages: 0,
          lastActivity: 1700001000,
        },
      ]);

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.content[0].text).toContain('[abc123] General');
      expect(result.content[0].text).toContain('group');
      expect(result.content[0].text).toContain('unread: 3');
      expect(result.content[0].text).toContain('[def456] Alice');
      expect(result.content[0].text).toContain('one-on-one');
    });

    it('should return empty message when no conversations', async () => {
      mockOCS([]);

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.content[0].text).toBe('No conversations found.');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing conversations');
    });
  });

  // ── list_messages ───────────────────────────────────────────────────

  describe('list_messages', () => {
    it('should list messages with resolved rich text', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello {mention-user1}!',
          messageParameters: { 'mention-user1': { type: 'user', id: 'bob', name: 'Bob' } },
          timestamp: 1700000000,
          systemMessage: '',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Alice: Hello Bob!');
    });

    it('should resolve a placeholder that repeats in one message', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: '{user1} added {user1} to the call',
          messageParameters: { user1: { type: 'user', id: 'bob', name: 'Bob' } },
          timestamp: 1700000000,
          systemMessage: '',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Bob added Bob to the call');
      expect(result.content[0].text).not.toContain('{user1}');
    });

    it('should filter system messages by default', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello',
          messageParameters: {},
          timestamp: 1700000000,
          systemMessage: '',
        },
        {
          id: 2,
          actorId: 'system',
          actorDisplayName: '',
          message: 'Alice joined',
          messageParameters: {},
          timestamp: 1700000001,
          systemMessage: 'user_added',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('Hello');
      expect(result.content[0].text).not.toContain('Alice joined');
    });

    it('should include system messages when requested', async () => {
      mockOCS([
        {
          id: 1,
          actorId: 'alice',
          actorDisplayName: 'Alice',
          message: 'Hello',
          messageParameters: {},
          timestamp: 1700000000,
          systemMessage: '',
        },
        {
          id: 2,
          actorId: 'system',
          actorDisplayName: '',
          message: 'Alice joined',
          messageParameters: {},
          timestamp: 1700000001,
          systemMessage: 'user_added',
        },
      ]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({
        token: 'abc123',
        includeSystemMessages: true,
      });

      expect(result.content[0].text).toContain('Hello');
      expect(result.content[0].text).toContain('Alice joined');
    });

    it('should return empty message when no messages', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toBe('No messages found.');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      const result = await listMessagesTool.handler({ token: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing messages');
    });

    it('does not advance the read marker', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      await listMessagesTool.handler({ token: 'abc123' });

      const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(new URL(String(url)).searchParams.get('setReadMarker')).toBe('0');
    });
  });

  // ── send_message ────────────────────────────────────────────────────

  describe('send_message', () => {
    it('should send a message', async () => {
      mockOCS({
        id: 42,
        actorId: 'testuser',
        actorDisplayName: 'Test User',
        message: 'Hello everyone',
        messageParameters: {},
        timestamp: 1700000000,
        systemMessage: '',
      });

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      const result = await sendMessageTool.handler({
        token: 'abc123',
        message: 'Hello everyone',
      });

      expect(result.content[0].text).toContain('Message sent (ID: 42)');
      expect(result.content[0].text).toContain('Hello everyone');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      const result = await sendMessageTool.handler({
        token: 'abc123',
        message: 'Hello',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error sending message');
    });
  });

  // ── create_conversation ─────────────────────────────────────────────

  describe('create_conversation', () => {
    it('should create a group conversation', async () => {
      mockOCS({
        token: 'new123',
        name: 'Project X',
        displayName: 'Project X',
        type: 2,
      });

      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'Project X',
      });

      expect(result.content[0].text).toContain('[new123]');
      expect(result.content[0].text).toContain('Project X');
      expect(result.content[0].text).toContain('group');
    });

    it('should require invite for one-on-one', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'one-on-one',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('invite is required');
    });

    it('should require roomName for group', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('roomName is required');
    });

    it('should require roomName for public', async () => {
      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'public',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('roomName is required');
    });

    it('sends group and team invites as participants', async () => {
      const writes = mockTalkServer([], { token: 'x', name: 'n', type: 2 });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      await createConversationTool.handler({ roomType: 'group', roomName: 'n', invite: 'staff' });
      await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        invite: 'team1',
        source: 'circles',
      });

      expect(JSON.parse(writes[0].body).participants).toEqual({ groups: ['staff'] });
      expect(JSON.parse(writes[1].body).participants).toEqual({ teams: ['team1'] });
    });

    it('keeps invite for one-on-one', async () => {
      const writes = mockTalkServer([], { token: 'x', name: 'n', type: 1 });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      await createConversationTool.handler({ roomType: 'one-on-one', invite: 'alice' });

      expect(JSON.parse(writes[0].body)).toEqual({ roomType: 1, invite: 'alice' });
    });

    it('refuses settings Talk would ignore on one-on-one', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'one-on-one',
        invite: 'alice',
        preset: 'classified',
        messageExpiration: 60,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('preset, messageExpiration');
      expect(writes).toEqual([]);
    });

    it('maps settings to the Talk parameters', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES, { token: 'x', name: 'n', type: 2 });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        description: 'About',
        readOnly: true,
        listable: 'everyone',
        lobby: true,
        messageExpiration: 3600,
      });

      expect(JSON.parse(writes[0].body)).toEqual({
        roomType: 2,
        roomName: 'n',
        description: 'About',
        readOnly: 1,
        listable: 2,
        lobbyState: 1,
        messageExpiration: 3600,
      });
    });

    it('applies default and preset parameters, and sends the identifier', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES, {
        token: 'x',
        name: 'Secret',
        type: 2,
        attributes: 4,
        messageExpiration: 604800,
      });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'Secret',
        preset: 'classified',
      });

      expect(JSON.parse(writes[0].body)).toEqual({
        preset: 'classified',
        roomType: 2,
        roomName: 'Secret',
        readOnly: 0,
        listable: 0,
        permissions: 0,
        sipEnabled: 0,
        messageExpiration: 604800,
      });
      expect(result.content[0].text).toContain('(group, classified)');
      expect(result.content[0].text).toContain('expire after 604800 seconds');
    });

    it('lets an explicit setting override the preset', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES, { token: 'x', name: 'n', type: 2 });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        preset: 'channel',
        listable: 'none',
      });

      const body = JSON.parse(writes[0].body);
      expect(body.listable).toBe(0);
      expect(body.permissions).toBe(257);
    });

    it('refuses a public classified conversation', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'public',
        roomName: 'n',
        preset: 'classified',
      });

      expect(result.isError).toBe(true);
      expect(writes).toEqual([]);
    });

    it('refuses a preset the server has no capability for', async () => {
      const writes = mockTalkServer(['conversation-presets']);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        preset: 'channel',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('preset requires Talk 25');
      expect(writes).toEqual([]);
    });

    it('refuses a preset when capabilities cannot be read', async () => {
      const writes = mockTalkServer(null);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        preset: 'classified',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('nothing was sent');
      expect(writes).toEqual([]);
    });

    it('needs no capabilities without a gated parameter', async () => {
      const writes = mockTalkServer(null, { token: 'x', name: 'n', type: 2 });
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({ roomType: 'group', roomName: 'n' });

      expect(result.isError).toBeUndefined();
      expect(writes).toHaveLength(1);
    });

    it('refuses messageExpiration without cron background jobs', async () => {
      const writes = mockTalkServer(['classified-conversations']);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        messageExpiration: 60,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('messageExpiration requires');
      expect(writes).toEqual([]);
    });

    it('explains that announcements are for administrators', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES);
      const { createConversationTool } = await import('../tools/apps/talk.js');

      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'n',
        preset: 'announcement',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('administrators');
      expect(writes).toEqual([]);
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { createConversationTool } = await import('../tools/apps/talk.js');
      const result = await createConversationTool.handler({
        roomType: 'group',
        roomName: 'Test',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating conversation');
    });
  });

  // ── update_conversation ─────────────────────────────────────────────

  describe('update_conversation', () => {
    it('calls one endpoint per setting', async () => {
      const writes = mockTalkServer(TALK_25_FEATURES);
      const { updateConversationTool } = await import('../tools/apps/talk.js');

      const result = await updateConversationTool.handler({
        token: 'abc',
        description: 'New',
        readOnly: true,
        listable: 'users',
        lobby: false,
        messageExpiration: 0,
      });

      expect(result.isError).toBeUndefined();
      const room = 'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v4/room/abc';
      expect(writes).toEqual([
        { url: `${room}/description`, method: 'PUT', body: 'description=New' },
        { url: `${room}/read-only`, method: 'PUT', body: 'state=1' },
        { url: `${room}/listable`, method: 'PUT', body: 'scope=1' },
        { url: `${room}/webinar/lobby`, method: 'PUT', body: 'state=0' },
        { url: `${room}/message-expiration`, method: 'POST', body: 'seconds=0' },
      ]);
    });

    it('needs at least one setting', async () => {
      const { updateConversationTool } = await import('../tools/apps/talk.js');
      const result = await updateConversationTool.handler({ token: 'abc' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('at least one setting');
    });

    it('refuses messageExpiration without changing anything', async () => {
      const writes = mockTalkServer([]);
      const { updateConversationTool } = await import('../tools/apps/talk.js');

      const result = await updateConversationTool.handler({
        token: 'abc',
        description: 'New',
        messageExpiration: 60,
      });

      expect(result.isError).toBe(true);
      expect(writes).toEqual([]);
    });

    it('reports what was applied when a setting fails', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(async (url: string) =>
        url.endsWith('/read-only')
          ? { ok: false, status: 400, statusText: 'Bad Request', text: async () => 'denied' }
          : okResponse({})
      );
      const { updateConversationTool } = await import('../tools/apps/talk.js');

      const result = await updateConversationTool.handler({
        token: 'abc',
        readOnly: true,
        lobby: true,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('readOnly: OCS API error: 400');
      expect(result.content[0].text).toContain('Applied: lobby.');
    });
  });

  // ── list_participants ───────────────────────────────────────────────

  describe('list_participants', () => {
    it('should list participants with roles', async () => {
      mockOCS([
        { actorId: 'alice', displayName: 'Alice', participantType: 1, attendeeId: 10 },
        { actorId: 'bob', displayName: 'Bob', participantType: 3, attendeeId: 11 },
      ]);

      const { listParticipantsTool } = await import('../tools/apps/talk.js');
      const result = await listParticipantsTool.handler({ token: 'abc123' });

      expect(result.content[0].text).toContain('alice (Alice) — owner');
      expect(result.content[0].text).toContain('bob (Bob) — user');
      expect(result.content[0].text).toContain('attendeeId: 10');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { listParticipantsTool } = await import('../tools/apps/talk.js');
      const result = await listParticipantsTool.handler({ token: 'abc123' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error listing participants');
    });
  });

  // ── add_participant ─────────────────────────────────────────────────

  describe('add_participant', () => {
    it('should add a participant', async () => {
      mockOCS({});

      const { addParticipantTool } = await import('../tools/apps/talk.js');
      const result = await addParticipantTool.handler({
        token: 'abc123',
        newParticipant: 'charlie',
      });

      expect(result.content[0].text).toContain('"charlie" added');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { addParticipantTool } = await import('../tools/apps/talk.js');
      const result = await addParticipantTool.handler({
        token: 'abc123',
        newParticipant: 'charlie',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error adding participant');
    });
  });

  // ── remove_participant ──────────────────────────────────────────────

  describe('remove_participant', () => {
    it('should remove a participant', async () => {
      mockOCS({});

      const { removeParticipantTool } = await import('../tools/apps/talk.js');
      const result = await removeParticipantTool.handler({
        token: 'abc123',
        attendeeId: 10,
      });

      expect(result.content[0].text).toContain('attendeeId: 10');
      expect(result.content[0].text).toContain('removed');

      const [url, init] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(url).toContain('/api/v4/room/abc123/attendees?attendeeId=10');
      expect(init.method).toBe('DELETE');
      expect(init.body).toBeUndefined();
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { removeParticipantTool } = await import('../tools/apps/talk.js');
      const result = await removeParticipantTool.handler({
        token: 'abc123',
        attendeeId: 10,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error removing participant');
    });
  });

  // ── delete_message ──────────────────────────────────────────────────

  describe('delete_message', () => {
    it('should delete a message', async () => {
      mockOCS({});

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      const result = await deleteMessageTool.handler({
        token: 'abc123',
        messageId: 42,
      });

      expect(result.content[0].text).toContain('Message 42 deleted');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      const result = await deleteMessageTool.handler({
        token: 'abc123',
        messageId: 42,
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error deleting message');
    });
  });

  // ── create_poll ─────────────────────────────────────────────────────

  describe('create_poll', () => {
    it('should create a poll', async () => {
      mockOCS({});

      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Best language?',
        options: ['TypeScript', 'Rust', 'Go'],
      });

      expect(result.content[0].text).toContain('Poll created');
      expect(result.content[0].text).toContain('Best language?');
      expect(result.content[0].text).toContain('3 options');
    });

    it('should reject polls with fewer than 2 options', async () => {
      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Yes?',
        options: ['Yes'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('at least 2 options');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { createPollTool } = await import('../tools/apps/talk.js');
      const result = await createPollTool.handler({
        token: 'abc123',
        question: 'Best?',
        options: ['A', 'B'],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error creating poll');
    });
  });

  // ── react_to_message ────────────────────────────────────────────────

  describe('react_to_message', () => {
    it('should add a reaction', async () => {
      mockOCS({});

      const { reactToMessageTool } = await import('../tools/apps/talk.js');
      const result = await reactToMessageTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.content[0].text).toContain('👍');
      expect(result.content[0].text).toContain('message 42');
    });

    it('should handle API errors', async () => {
      mockAPIError();

      const { reactToMessageTool } = await import('../tools/apps/talk.js');
      const result = await reactToMessageTool.handler({
        token: 'abc123',
        messageId: 42,
        reaction: '👍',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error reacting to message');
    });
  });

  // ── Chat endpoint version ───────────────────────────────────────────
  // Rooms and participants live under api/v4, but the chat endpoints are
  // api/v1 — calling them under v4 yields a 404 "Invalid query" from Talk.

  describe('chat endpoint version', () => {
    const chatV1 = 'https://cloud.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc123';

    function calledUrl(): string {
      const [url] = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      return String(url);
    }

    it('lists messages from the v1 chat endpoint', async () => {
      mockOCS([]);

      const { listMessagesTool } = await import('../tools/apps/talk.js');
      await listMessagesTool.handler({ token: 'abc123' });

      expect(calledUrl().split('?')[0]).toBe(chatV1);
    });

    it('sends messages to the v1 chat endpoint', async () => {
      mockOCS({
        id: 1,
        actorId: 'testuser',
        actorDisplayName: 'Test User',
        message: 'hi',
        messageParameters: {},
        timestamp: 1700000000,
        systemMessage: '',
      });

      const { sendMessageTool } = await import('../tools/apps/talk.js');
      await sendMessageTool.handler({ token: 'abc123', message: 'hi' });

      expect(calledUrl()).toBe(chatV1);
    });

    it('deletes messages via the v1 chat endpoint', async () => {
      mockOCS({});

      const { deleteMessageTool } = await import('../tools/apps/talk.js');
      await deleteMessageTool.handler({ token: 'abc123', messageId: 42 });

      expect(calledUrl()).toBe(`${chatV1}/42`);
    });
  });

  // ── Network errors ──────────────────────────────────────────────────

  describe('network errors', () => {
    it('should handle network errors gracefully', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { listConversationsTool } = await import('../tools/apps/talk.js');
      const result = await listConversationsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });
});
