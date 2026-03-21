import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Talk (Spreed) Tools
 *
 * Bridges MCP to Nextcloud's Talk API for managing conversations,
 * messages, participants, polls, and reactions.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const API_V4 = '/ocs/v2.php/apps/spreed/api/v4';
const API_V1 = '/ocs/v2.php/apps/spreed/api/v1';

const CONVERSATION_TYPE_LABELS: Record<number, string> = {
  1: 'one-on-one',
  2: 'group',
  3: 'public',
  4: 'changelog',
  5: 'former one-on-one',
};

const PARTICIPANT_TYPE_LABELS: Record<number, string> = {
  1: 'owner',
  2: 'moderator',
  3: 'user',
  4: 'guest',
  5: 'public link user',
  6: 'guest moderator',
};

const ROOM_TYPE_MAP: Record<string, number> = {
  'one-on-one': 1,
  group: 2,
  public: 3,
};

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Conversation {
  token: string;
  name: string;
  displayName: string;
  type: number;
  unreadMessages: number;
  lastActivity: number;
  status?: string;
  statusMessage?: string;
}

interface ChatMessage {
  id: number;
  actorId: string;
  actorDisplayName: string;
  message: string;
  messageParameters: Record<string, { type: string; id: string; name: string }>;
  timestamp: number;
  systemMessage: string;
}

interface Participant {
  actorId: string;
  displayName: string;
  participantType: number;
  attendeeId: number;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function resolveRichMessage(
  message: string,
  params: Record<string, { type: string; id: string; name: string }>
): string {
  if (!params) return message;
  let resolved = message;
  for (const [key, param] of Object.entries(params)) {
    resolved = resolved.replace(`{${key}}`, param.name ?? param.id);
  }
  return resolved;
}

function formatTimestamp(ts: number): string {
  const d = new Date(ts * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(msg: string) {
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function wrapError(action: string, err: unknown) {
  return error(`Error ${action}: ${err instanceof Error ? err.message : String(err)}`);
}

// ---------------------------------------------------------------------------
// list_conversations
// ---------------------------------------------------------------------------

export const listConversationsTool = {
  name: 'talk_list_conversations',
  description:
    'List all Talk conversations the user has access to. Returns conversation tokens, names, types, and unread message counts.',
  inputSchema: z.object({
    includeStatus: z
      .boolean()
      .optional()
      .describe('Include user status information (default false)'),
  }),
  handler: async (args: { includeStatus?: boolean }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.includeStatus) queryParams.includeStatus = 'true';

      const data = await fetchOCS<Conversation[]>(`${API_V4}/room`, { queryParams });
      const rooms = data.ocs.data ?? [];

      if (rooms.length === 0) return text('No conversations found.');

      const lines = rooms.map((r) => {
        const type = CONVERSATION_TYPE_LABELS[r.type] ?? `type-${r.type}`;
        const last = r.lastActivity ? formatTimestamp(r.lastActivity) : 'never';
        let line = `[${r.token}] ${r.displayName || r.name} (${type}, unread: ${r.unreadMessages}) — last: ${last}`;
        if (args.includeStatus && r.status) {
          line += ` — status: ${r.status}${r.statusMessage ? ` "${r.statusMessage}"` : ''}`;
        }
        return line;
      });
      return text(lines.join('\n'));
    } catch (err) {
      return wrapError('listing conversations', err);
    }
  },
};

// ---------------------------------------------------------------------------
// list_messages
// ---------------------------------------------------------------------------

export const listMessagesTool = {
  name: 'talk_list_messages',
  description:
    'List recent messages in a Talk conversation. Returns message content with timestamps and authors.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token (from list_conversations)'),
    limit: z.number().optional().describe('Number of messages to retrieve (default 50, max 200)'),
    lastKnownMessageId: z
      .number()
      .optional()
      .describe('Message ID to start from for pagination (returns messages before this ID)'),
    includeSystemMessages: z
      .boolean()
      .optional()
      .describe('Include system messages like join/leave notifications (default false)'),
  }),
  handler: async (args: {
    token: string;
    limit?: number;
    lastKnownMessageId?: number;
    includeSystemMessages?: boolean;
  }) => {
    try {
      const queryParams: Record<string, string> = {
        lookIntoFuture: '0',
        limit: String(Math.min(args.limit ?? 50, 200)),
      };
      if (args.lastKnownMessageId) {
        queryParams.lastKnownMessageId = String(args.lastKnownMessageId);
      }

      const data = await fetchOCS<ChatMessage[]>(`${API_V4}/chat/${args.token}`, { queryParams });
      let messages = data.ocs.data ?? [];

      if (!args.includeSystemMessages) {
        messages = messages.filter((m) => !m.systemMessage);
      }

      if (messages.length === 0) return text('No messages found.');

      const lines = messages.map((m) => {
        const ts = formatTimestamp(m.timestamp);
        const resolved = resolveRichMessage(m.message, m.messageParameters);
        return `[${ts}] ${m.actorDisplayName}: ${resolved}`;
      });
      return text(lines.join('\n'));
    } catch (err) {
      return wrapError('listing messages', err);
    }
  },
};

// ---------------------------------------------------------------------------
// send_message
// ---------------------------------------------------------------------------

export const sendMessageTool = {
  name: 'talk_send_message',
  description:
    'Send a message to a Talk conversation. Supports replies and silent messages that do not trigger notifications.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    message: z.string().describe('Message text to send'),
    replyTo: z.number().optional().describe('Message ID to reply to'),
    silent: z
      .boolean()
      .optional()
      .describe('Send without triggering notifications (default false)'),
  }),
  handler: async (args: { token: string; message: string; replyTo?: number; silent?: boolean }) => {
    try {
      const body: Record<string, string> = { message: args.message };
      if (args.replyTo !== undefined) body.replyTo = String(args.replyTo);
      if (args.silent) body.silent = 'true';

      const data = await fetchOCS<ChatMessage>(`${API_V4}/chat/${args.token}`, {
        method: 'POST',
        body,
      });
      const msg = data.ocs.data;
      return text(
        `Message sent (ID: ${msg.id}) at ${formatTimestamp(msg.timestamp)}: ${resolveRichMessage(msg.message, msg.messageParameters)}`
      );
    } catch (err) {
      return wrapError('sending message', err);
    }
  },
};

// ---------------------------------------------------------------------------
// create_conversation
// ---------------------------------------------------------------------------

export const createConversationTool = {
  name: 'talk_create_conversation',
  description:
    'Create a new Talk conversation. One-on-one requires an invite user, group/public require a name.',
  inputSchema: z.object({
    roomType: z.enum(['one-on-one', 'group', 'public']).describe('Type of conversation to create'),
    roomName: z
      .string()
      .optional()
      .describe('Name for the conversation (required for group and public)'),
    invite: z.string().optional().describe('User ID to invite (required for one-on-one)'),
  }),
  handler: async (args: {
    roomType: 'one-on-one' | 'group' | 'public';
    roomName?: string;
    invite?: string;
  }) => {
    if (args.roomType === 'one-on-one' && !args.invite) {
      return error('invite is required for one-on-one conversations.');
    }
    if ((args.roomType === 'group' || args.roomType === 'public') && !args.roomName) {
      return error('roomName is required for group and public conversations.');
    }

    try {
      const body: Record<string, string> = {
        roomType: String(ROOM_TYPE_MAP[args.roomType]),
      };
      if (args.roomName) body.roomName = args.roomName;
      if (args.invite) body.invite = args.invite;

      const data = await fetchOCS<Conversation>(`${API_V4}/room`, {
        method: 'POST',
        body,
      });
      const room = data.ocs.data;
      const type = CONVERSATION_TYPE_LABELS[room.type] ?? `type-${room.type}`;
      return text(
        `Conversation created: [${room.token}] ${room.displayName || room.name} (${type})`
      );
    } catch (err) {
      return wrapError('creating conversation', err);
    }
  },
};

// ---------------------------------------------------------------------------
// list_participants
// ---------------------------------------------------------------------------

export const listParticipantsTool = {
  name: 'talk_list_participants',
  description: 'List all participants in a Talk conversation with their roles.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
  }),
  handler: async (args: { token: string }) => {
    try {
      const data = await fetchOCS<Participant[]>(`${API_V4}/room/${args.token}/participants`);
      const participants = data.ocs.data ?? [];

      if (participants.length === 0) return text('No participants found.');

      const lines = participants.map((p) => {
        const role = PARTICIPANT_TYPE_LABELS[p.participantType] ?? `type-${p.participantType}`;
        return `${p.actorId} (${p.displayName}) — ${role}, attendeeId: ${p.attendeeId}`;
      });
      return text(lines.join('\n'));
    } catch (err) {
      return wrapError('listing participants', err);
    }
  },
};

// ---------------------------------------------------------------------------
// add_participant
// ---------------------------------------------------------------------------

export const addParticipantTool = {
  name: 'talk_add_participant',
  description: 'Add a user, group, or email participant to a Talk conversation.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    newParticipant: z.string().describe('User ID, group ID, or email address to add'),
    source: z
      .enum(['users', 'groups', 'emails'])
      .optional()
      .describe("Participant source type (default 'users')"),
  }),
  handler: async (args: { token: string; newParticipant: string; source?: string }) => {
    try {
      const body: Record<string, string> = { newParticipant: args.newParticipant };
      if (args.source) body.source = args.source;

      await fetchOCS(`${API_V4}/room/${args.token}/participants`, {
        method: 'POST',
        body,
      });
      return text(`Participant "${args.newParticipant}" added to conversation ${args.token}.`);
    } catch (err) {
      return wrapError('adding participant', err);
    }
  },
};

// ---------------------------------------------------------------------------
// remove_participant
// ---------------------------------------------------------------------------

export const removeParticipantTool = {
  name: 'talk_remove_participant',
  description:
    'Remove a participant from a Talk conversation by their attendee ID (from list_participants).',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    attendeeId: z.number().describe('Attendee ID of the participant to remove'),
  }),
  handler: async (args: { token: string; attendeeId: number }) => {
    try {
      await fetchOCS(`${API_V4}/room/${args.token}/attendees`, {
        method: 'DELETE',
        body: { attendeeId: String(args.attendeeId) },
      });
      return text(
        `Participant (attendeeId: ${args.attendeeId}) removed from conversation ${args.token}.`
      );
    } catch (err) {
      return wrapError('removing participant', err);
    }
  },
};

// ---------------------------------------------------------------------------
// delete_message
// ---------------------------------------------------------------------------

export const deleteMessageTool = {
  name: 'talk_delete_message',
  description: 'Delete a message from a Talk conversation.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    messageId: z.number().describe('ID of the message to delete'),
  }),
  handler: async (args: { token: string; messageId: number }) => {
    try {
      await fetchOCS(`${API_V4}/chat/${args.token}/${args.messageId}`, {
        method: 'DELETE',
      });
      return text(`Message ${args.messageId} deleted from conversation ${args.token}.`);
    } catch (err) {
      return wrapError('deleting message', err);
    }
  },
};

// ---------------------------------------------------------------------------
// create_poll
// ---------------------------------------------------------------------------

export const createPollTool = {
  name: 'talk_create_poll',
  description: 'Create a poll in a Talk conversation. Requires at least 2 options.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    question: z.string().describe('Poll question'),
    options: z.array(z.string()).min(2).describe('Poll options (minimum 2)'),
    resultMode: z
      .enum(['public', 'hidden'])
      .optional()
      .describe("Result visibility: 'public' (default) or 'hidden' until closed"),
    maxVotes: z
      .number()
      .optional()
      .describe('Maximum number of votes per participant (0 = unlimited, default 1)'),
  }),
  handler: async (args: {
    token: string;
    question: string;
    options: string[];
    resultMode?: string;
    maxVotes?: number;
  }) => {
    if (args.options.length < 2) {
      return error('A poll requires at least 2 options.');
    }

    try {
      const jsonBody: Record<string, unknown> = {
        question: args.question,
        options: args.options,
        resultMode: args.resultMode === 'hidden' ? 1 : 0,
        maxVotes: args.maxVotes ?? 1,
      };

      await fetchOCS(`${API_V1}/poll/${args.token}`, {
        method: 'POST',
        jsonBody,
      });
      return text(
        `Poll created in conversation ${args.token}: "${args.question}" with ${args.options.length} options.`
      );
    } catch (err) {
      return wrapError('creating poll', err);
    }
  },
};

// ---------------------------------------------------------------------------
// react_to_message
// ---------------------------------------------------------------------------

export const reactToMessageTool = {
  name: 'talk_react_to_message',
  description: 'Add an emoji reaction to a message in a Talk conversation.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    messageId: z.number().describe('ID of the message to react to'),
    reaction: z.string().describe("Emoji reaction (e.g. '👍', '❤️', '🎉')"),
  }),
  handler: async (args: { token: string; messageId: number; reaction: string }) => {
    try {
      await fetchOCS(`${API_V1}/reaction/${args.token}/${args.messageId}`, {
        method: 'POST',
        body: { reaction: args.reaction },
      });
      return text(
        `Reaction "${args.reaction}" added to message ${args.messageId} in conversation ${args.token}.`
      );
    } catch (err) {
      return wrapError('reacting to message', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const talkTools = [
  listConversationsTool,
  listMessagesTool,
  sendMessageTool,
  createConversationTool,
  listParticipantsTool,
  addParticipantTool,
  removeParticipantTool,
  deleteMessageTool,
  createPollTool,
  reactToMessageTool,
];
