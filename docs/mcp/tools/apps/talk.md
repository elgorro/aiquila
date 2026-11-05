# Nextcloud Talk Tools

Integration with [Nextcloud Talk](https://apps.nextcloud.com/apps/spreed). Manage conversations and their settings, read and send messages, and work with participants, polls and reactions.

## Prerequisites

- Nextcloud Talk app installed and enabled (app ID: `spreed`)
- Moderator rights in a conversation to change its settings

## API

Uses the Talk OCS API at `/ocs/v2.php/apps/spreed/api/`, documented in the
[Talk API reference](https://nextcloud-talk.readthedocs.io/en/latest/).

## Available Tools

### Conversations

#### `talk_list_conversations`
List the conversations the user has access to.

**Parameters:**
- `includeStatus` (boolean, optional) — include user status information.

**Returns:** one line per conversation with token, name, kind, unread count and last activity. The kind names the type (`one-on-one`, `group`, `public`, `changelog`, `former one-on-one`, `note to self`), followed by `classified`, `channel`, `announcement` or `voice room` where that applies.

#### `talk_create_conversation`
Create a conversation.

**Parameters:**
- `roomType` (enum, required) — `one-on-one`, `group` or `public`.
- `roomName` (string) — required for `group` and `public`.
- `invite` (string) — who to invite, see `source`. Required for `one-on-one`.
- `source` (enum, optional) — what `invite` is: `users`, `groups` or `circles` (a Team). Default `groups`.
- `preset` (enum, optional) — `classified`, `channel` or `announcement`, see [Presets](#presets).
- `description` (string, optional)
- `readOnly` (boolean, optional) — only moderators can post or call.
- `listable` (enum, optional) — who can find and join it: `none`, `users` (regular users) or `everyone` (guest accounts too).
- `lobby` (boolean, optional) — keep non-moderators in the lobby until it is opened.
- `messageExpiration` (integer, optional) — seconds after which messages disappear, `0` to keep them.

A one-on-one conversation takes only `invite`. Passing any other setting is an error; change them afterwards with `talk_update_conversation`.

**Example:** "Create a classified conversation called Audit 2026 with the finance group."

#### `talk_update_conversation`
Change the settings of an existing conversation. Requires moderator rights. Only the settings passed are changed.

**Parameters:**
- `token` (string, required)
- `description`, `readOnly`, `listable`, `lobby`, `messageExpiration` — as in `talk_create_conversation`.

Talk has a separate endpoint for each setting. If one fails, the others are still applied, and the error lists what failed and what was applied.

**Example:** "Make messages in this conversation disappear after a day."

### Messages

#### `talk_list_messages`
List recent messages in a conversation. Reading does not mark the conversation as read.

**Parameters:**
- `token` (string, required)
- `limit` (number, optional) — default 50, maximum 200.
- `lastKnownMessageId` (number, optional) — return messages before this ID.
- `includeSystemMessages` (boolean, optional) — include join and leave notices.

#### `talk_send_message`
Send a message.

**Parameters:**
- `token` (string, required)
- `message` (string, required)
- `replyTo` (number, optional) — message ID to reply to.
- `silent` (boolean, optional) — send without notifications.

#### `talk_delete_message`
Delete a message from a conversation.

#### `talk_react_to_message`
Add an emoji reaction to a message.

#### `talk_create_poll`
Create a poll in a conversation.

### Participants

#### `talk_list_participants`
List the participants of a conversation with their roles and attendee IDs.

#### `talk_add_participant`
Add a participant.

**Parameters:**
- `token` (string, required)
- `newParticipant` (string, required) — user ID, group ID, Team ID or email address.
- `source` (enum, optional) — `users` (default), `groups`, `circles` (a Team) or `emails`.

#### `talk_remove_participant`
Remove a participant by the attendee ID from `talk_list_participants`.

## Presets

A preset fills in the settings of a conversation type and marks the conversation as that type. The server decides what a preset contains, so an administrator's defaults are respected. Other parameters passed to `talk_create_conversation` override the preset's values, and values an administrator forced always win.

| Preset | What it does | Needs |
|---|---|---|
| `classified` | No public link, guests, SIP or recording, forced sensitive for everyone. Messages expire after 7 days by default. Cannot be `public`. | Talk 25 (Nextcloud 35) |
| `channel` | Only moderators post, participants can react, no calls. Regular users can find and join it. | Talk 25 (Nextcloud 35) |
| `announcement` | A channel with a fixed audience that non-moderators cannot leave. Not listable. Administrators only. | Talk 25 (Nextcloud 35) |

## Version-dependent parameters

These fail with a message naming the requirement, and nothing is sent, when the server lacks them. See [Adding New Tools](../../development/adding-tools.md).

- `preset` needs Talk 25.
- `messageExpiration` needs Nextcloud background jobs in cron mode, because a background job removes the expired messages. A `classified` preset still sets its 7 days on such a server.
