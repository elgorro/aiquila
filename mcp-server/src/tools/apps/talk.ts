// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { checkGatedParams, talkFeature, type CapabilityGate } from '../../client/capabilities.js';

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
  6: 'note to self',
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

// Room::LISTABLE_*
const LISTABLE_MAP: Record<string, number> = { none: 0, users: 1, everyone: 2 };

// RoomAttributes. An announcement also carries the channel bit, so it is
// checked first and the first match names the conversation.
const ROOM_ATTRIBUTE_LABELS: [number, string][] = [
  [4, 'classified'],
  [16, 'announcement'],
  [8, 'channel'],
  [1, 'voice room'],
];

const TALK_25 = 'Talk 25 (Nextcloud 35)';

// Each preset value has its own capability, so the gate depends on the value.
const PRESET_GATES: Record<string, CapabilityGate> = {
  classified: talkFeature('classified-conversations', TALK_25),
  channel: talkFeature('announcement-preset', TALK_25),
  announcement: talkFeature('announcement-preset', TALK_25),
};

// Talk leaves this feature out unless Nextcloud background jobs run from cron,
// because expired messages are removed by a background job.
const MESSAGE_EXPIRATION_GATE = talkFeature(
  'message-expiration',
  'Nextcloud background jobs in cron mode'
);

// Keys of the `participants` argument of POST /room, which replaced the
// deprecated `invite` + `source` pair.
const INVITE_SOURCE_KEYS: Record<string, string> = {
  users: 'users',
  groups: 'groups',
  circles: 'teams',
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
  messageExpiration?: number;
  attributes?: number;
}

interface RoomPreset {
  identifier: string;
  parameters: Record<string, number>;
}

type PresetName = 'classified' | 'channel' | 'announcement';

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
    resolved = resolved.replaceAll(`{${key}}`, param.name ?? param.id);
  }
  return resolved;
}

function formatTimestamp(ts: number): string {
  const d = new Date(ts * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function conversationKind(room: Conversation): string {
  const type = CONVERSATION_TYPE_LABELS[room.type] ?? `type-${room.type}`;
  const attribute = ROOM_ATTRIBUTE_LABELS.find(([bit]) => ((room.attributes ?? 0) & bit) !== 0);
  return attribute ? `${type}, ${attribute[1]}` : type;
}

/**
 * The parameters a preset stands for. Talk does not expand them: `preset` only
 * sets the conversation's attributes, and clients send the rest themselves.
 * Talk's documented order is default preset, selected preset, user selection,
 * forced preset; the server applies the last one. Returns null when the server
 * does not offer the preset to this account.
 */
async function presetParameters(name: PresetName): Promise<Record<string, number> | null> {
  const data = await fetchOCS<RoomPreset[]>(`${API_V1}/presets/room`);
  const presets = data.ocs.data ?? [];
  const selected = presets.find((p) => p.identifier === name);
  if (!selected) return null;
  const defaults = presets.find((p) => p.identifier === 'default');
  return { ...defaults?.parameters, ...selected.parameters };
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
  title: 'List Talk Conversations',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
        const type = conversationKind(r);
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
  title: 'List Talk Messages',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List recent messages in a Talk conversation. Returns message content with timestamps and authors. Reading does not mark the conversation as read.',
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
        // Talk defaults setReadMarker=1; keep this tool read-only so listing
        // messages does not clear the conversation's unread badge.
        setReadMarker: '0',
      };
      if (args.lastKnownMessageId) {
        queryParams.lastKnownMessageId = String(args.lastKnownMessageId);
      }

      // Talk answers 304 Not Modified when no further messages are available,
      // which is a normal end-of-pagination result rather than a failure.
      const data = await fetchOCS<ChatMessage[]>(`${API_V1}/chat/${args.token}`, {
        queryParams,
        allowNotModified: true,
      });
      let messages = data?.ocs.data ?? [];

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
  title: 'Send Talk Message',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
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

      const data = await fetchOCS<ChatMessage>(`${API_V1}/chat/${args.token}`, {
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
  title: 'Create Talk Conversation',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new Talk conversation. One-on-one requires an invite user, group/public require a name. ' +
    'A preset creates a classified conversation (locked down: no public link, guests, SIP or recording, messages expire after 7 days), ' +
    'a channel (only moderators post, no calls, anyone can find and join it) or an announcement (a channel with a fixed audience, administrators only).',
  inputSchema: z.object({
    roomType: z.enum(['one-on-one', 'group', 'public']).describe('Type of conversation to create'),
    roomName: z
      .string()
      .optional()
      .describe('Name for the conversation (required for group and public)'),
    invite: z
      .string()
      .optional()
      .describe('User, group or team to invite, see source (required for one-on-one)'),
    source: z
      .enum(['users', 'groups', 'circles'])
      .optional()
      .describe(
        "What invite is: 'users', 'groups' or 'circles' (a Team). Default 'groups', except one-on-one, which is always a user"
      ),
    preset: z
      .enum(['classified', 'channel', 'announcement'])
      .optional()
      .describe('Conversation preset (Talk 25). Other parameters override its settings'),
    description: z.string().optional().describe('Conversation description'),
    readOnly: z.boolean().optional().describe('Only moderators can post or call (default false)'),
    listable: z
      .enum(['none', 'users', 'everyone'])
      .optional()
      .describe(
        "Who can find and join the conversation: 'users' for regular users, 'everyone' also includes guest accounts (default 'none')"
      ),
    lobby: z
      .boolean()
      .optional()
      .describe('Keep non-moderators in the lobby until it is opened (default false)'),
    messageExpiration: z
      .number()
      .int()
      .min(0)
      .optional()
      .describe('Seconds after which messages disappear, 0 to keep them (default 0)'),
  }),
  handler: async (args: {
    roomType: 'one-on-one' | 'group' | 'public';
    roomName?: string;
    invite?: string;
    source?: 'users' | 'groups' | 'circles';
    preset?: PresetName;
    description?: string;
    readOnly?: boolean;
    listable?: 'none' | 'users' | 'everyone';
    lobby?: boolean;
    messageExpiration?: number;
  }) => {
    const { roomType, roomName, invite, source, preset, ...settings } = args;

    if (roomType === 'one-on-one') {
      if (!invite) return error('invite is required for one-on-one conversations.');
      // Talk ignores everything but the invite here; refused so nothing asked
      // for is dropped. talk_update_conversation covers an existing one.
      const unsupported = Object.entries({ source, preset, ...settings })
        .filter(([, value]) => value !== undefined)
        .map(([name]) => name);
      if (unsupported.length > 0) {
        return error(
          `Not available when creating a one-on-one conversation: ${unsupported.join(', ')}.`
        );
      }
    } else if (!roomName) {
      return error('roomName is required for group and public conversations.');
    }

    // Talk would quietly make it a group conversation instead.
    if (preset === 'classified' && roomType === 'public') {
      return error('A classified conversation cannot be public. Use roomType group.');
    }

    try {
      const gates: Record<string, CapabilityGate> = {
        messageExpiration: MESSAGE_EXPIRATION_GATE,
      };
      if (preset) gates.preset = PRESET_GATES[preset];
      const refused = await checkGatedParams(args, gates);
      if (refused) return error(refused);

      const body: Record<string, unknown> = {};
      if (preset) {
        const parameters = await presetParameters(preset);
        if (!parameters) {
          return error(
            `This account cannot use the ${preset} preset` +
              (preset === 'announcement' ? ': it is for administrators.' : '.')
          );
        }
        Object.assign(body, parameters, { preset });
      }
      body.roomType = ROOM_TYPE_MAP[roomType];
      if (roomName) body.roomName = roomName;
      if (invite) {
        if (roomType === 'one-on-one') body.invite = invite;
        else body.participants = { [INVITE_SOURCE_KEYS[source ?? 'groups']]: [invite] };
      }
      if (settings.description !== undefined) body.description = settings.description;
      if (settings.readOnly !== undefined) body.readOnly = settings.readOnly ? 1 : 0;
      if (settings.listable !== undefined) body.listable = LISTABLE_MAP[settings.listable];
      if (settings.lobby !== undefined) body.lobbyState = settings.lobby ? 1 : 0;
      if (settings.messageExpiration !== undefined) {
        body.messageExpiration = settings.messageExpiration;
      }

      const data = await fetchOCS<Conversation>(`${API_V4}/room`, {
        method: 'POST',
        jsonBody: body,
      });
      const room = data.ocs.data;
      const expiry = room.messageExpiration
        ? ` — messages expire after ${room.messageExpiration} seconds`
        : '';
      return text(
        `Conversation created: [${room.token}] ${room.displayName || room.name} (${conversationKind(room)})${expiry}`
      );
    } catch (err) {
      return wrapError('creating conversation', err);
    }
  },
};

// ---------------------------------------------------------------------------
// update_conversation
// ---------------------------------------------------------------------------

export const updateConversationTool = {
  name: 'talk_update_conversation',
  title: 'Update Talk Conversation',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Change the settings of an existing Talk conversation: description, read-only state, who can find it, lobby and message expiration. Requires moderator rights. Only the settings passed are changed.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    description: z.string().optional().describe('New description, empty to clear it'),
    readOnly: z.boolean().optional().describe('Only moderators can post or call'),
    listable: z
      .enum(['none', 'users', 'everyone'])
      .optional()
      .describe(
        "Who can find and join the conversation: 'users' for regular users, 'everyone' also includes guest accounts"
      ),
    lobby: z.boolean().optional().describe('Keep non-moderators in the lobby until it is opened'),
    messageExpiration: z
      .number()
      .int()
      .min(0)
      .optional()
      .describe('Seconds after which messages disappear, 0 to keep them'),
  }),
  handler: async (args: {
    token: string;
    description?: string;
    readOnly?: boolean;
    listable?: 'none' | 'users' | 'everyone';
    lobby?: boolean;
    messageExpiration?: number;
  }) => {
    const room = `${API_V4}/room/${args.token}`;
    // Talk has one endpoint per setting, so a call can partly succeed.
    const changes: { name: string; path: string; method: string; body: Record<string, string> }[] =
      [];
    if (args.description !== undefined) {
      changes.push({
        name: 'description',
        path: `${room}/description`,
        method: 'PUT',
        body: { description: args.description },
      });
    }
    if (args.readOnly !== undefined) {
      changes.push({
        name: 'readOnly',
        path: `${room}/read-only`,
        method: 'PUT',
        body: { state: args.readOnly ? '1' : '0' },
      });
    }
    if (args.listable !== undefined) {
      changes.push({
        name: 'listable',
        path: `${room}/listable`,
        method: 'PUT',
        body: { scope: String(LISTABLE_MAP[args.listable]) },
      });
    }
    if (args.lobby !== undefined) {
      changes.push({
        name: 'lobby',
        path: `${room}/webinar/lobby`,
        method: 'PUT',
        body: { state: args.lobby ? '1' : '0' },
      });
    }
    if (args.messageExpiration !== undefined) {
      changes.push({
        name: 'messageExpiration',
        path: `${room}/message-expiration`,
        method: 'POST',
        body: { seconds: String(args.messageExpiration) },
      });
    }
    if (changes.length === 0) return error('Pass at least one setting to change.');

    try {
      const refused = await checkGatedParams(args, { messageExpiration: MESSAGE_EXPIRATION_GATE });
      if (refused) return error(refused);
    } catch (err) {
      return wrapError('updating conversation', err);
    }

    const applied: string[] = [];
    const failed: string[] = [];
    for (const { name, path, method, body } of changes) {
      try {
        await fetchOCS(path, { method, body });
        applied.push(name);
      } catch (err) {
        failed.push(`${name}: ${err instanceof Error ? err.message : String(err)}`);
      }
    }
    if (failed.length === 0) {
      return text(`Conversation ${args.token} updated: ${applied.join(', ')}.`);
    }
    const done = applied.length > 0 ? `\nApplied: ${applied.join(', ')}.` : '';
    return error(`Error updating conversation ${args.token}: ${failed.join('; ')}${done}`);
  },
};

// ---------------------------------------------------------------------------
// list_participants
// ---------------------------------------------------------------------------

export const listParticipantsTool = {
  name: 'talk_list_participants',
  title: 'List Talk Participants',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Add Talk Participant',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Add a user, group, team, or email participant to a Talk conversation.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    newParticipant: z.string().describe('User ID, group ID, team ID, or email address to add'),
    source: z
      .enum(['users', 'groups', 'circles', 'emails'])
      .optional()
      .describe("Participant source type, 'circles' for a Team (default 'users')"),
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
  title: 'Remove Talk Participant',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
        // spreed declares attendeeId as a query parameter; content on a DELETE
        // has no defined semantics (RFC 9110 §9.3.5).
        queryParams: { attendeeId: String(args.attendeeId) },
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
  title: 'Delete Talk Message',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a message from a Talk conversation.',
  inputSchema: z.object({
    token: z.string().describe('Conversation token'),
    messageId: z.number().describe('ID of the message to delete'),
  }),
  handler: async (args: { token: string; messageId: number }) => {
    try {
      await fetchOCS(`${API_V1}/chat/${args.token}/${args.messageId}`, {
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
  title: 'Create Talk Poll',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
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
  title: 'React to Talk Message',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  updateConversationTool,
  listParticipantsTool,
  addParticipantTool,
  removeParticipantTool,
  deleteMessageTool,
  createPollTool,
  reactToMessageTool,
];
