# Nextcloud Polls Tools

Integration with the [Nextcloud Polls](https://apps.nextcloud.com/apps/polls) app. Create text or date polls, add options, vote, comment, share, and manage subscriptions — all via MCP.

## Prerequisites

- Nextcloud Polls app installed and enabled (app ID: `polls`)
- The MCP user has permission to create/read polls on the target Nextcloud

## API

Uses the documented Polls REST API at `/index.php/apps/polls/api/v1.0/…`. Note: the Polls API documentation is marked as DRAFT by upstream, so response shapes may shift in future Polls releases.

## Available Tools

### Polls

#### `list_polls`
List every poll the current user can access (owned, shared, or public).

**Parameters:** none.

**Returns:** one line per poll with id, title, type, owner, and status.

**Example:** "Show me my active polls."

#### `get_poll`
Get full details for a single poll: configuration, owner, status, and the current user's role/vote count.

**Parameters:**
- `pollId` (number, required) — poll ID from `list_polls`.

#### `create_poll`
Create a new poll.

**Parameters:**
- `title` (string, required)
- `type` (enum, required) — `textPoll` for text choices (e.g. lunch options), `datePoll` for date/time choices (e.g. meeting scheduling).

**Example:** "Create a poll called 'Team lunch' with text options."

#### `update_poll`
Update any subset of a poll's configuration. Only the fields you pass are changed.

**Parameters (all optional except `pollId`):**
- `pollId` (number, required)
- `title`, `description` (string)
- `expire` (number) — unix timestamp; `0` = no expiration; negative = close immediately
- `access` (`open` | `private`)
- `anonymous`, `allowComment`, `allowMaybe`, `allowProposals`, `autoReminder`, `hideBookedUp`, `useNo` (boolean)
- `showResults` (`never` | `always` | `closed`)
- `maxVotesPerOption`, `maxVotesPerUser` (number, ≥ 0)

#### `delete_poll`
Permanently delete a poll.

**Parameters:** `pollId` (number, required).

#### `close_poll` / `reopen_poll`
Close or reopen voting on a poll.

**Parameters:** `pollId` (number, required).

#### `clone_poll`
Duplicate an existing poll (configuration + options) into a new poll.

**Parameters:** `pollId` (number, required).

### Options

#### `list_poll_options`
List all options for a poll, with vote tallies per option.

**Parameters:** `pollId` (number, required).

#### `add_text_poll_option`
Add a text option to a `textPoll`.

**Parameters:** `pollId` (number), `text` (string).

#### `add_date_poll_option`
Add a date/time option to a `datePoll`.

**Parameters:**
- `pollId` (number)
- `startAt` (string) — ISO-8601 timestamp, e.g. `2026-05-12T14:00:00Z`. The handler converts to the unix seconds that the API expects.
- `durationSeconds` (number, ≥ 0) — `0` for a single point in time.

#### `delete_poll_option`
Delete an option. Parameters: `optionId` (number).

### Votes

#### `list_poll_votes`
List all votes on a poll. Anonymous polls redact voter identities.

**Parameters:** `pollId` (number, required).

#### `vote_on_poll`
Cast or change your vote on a single option.

**Parameters:**
- `optionId` (number) — from `list_poll_options`.
- `setTo` (`yes` | `no` | `maybe`).

### Comments

#### `list_poll_comments`
List all comments on a poll. Parameters: `pollId` (number).

#### `add_poll_comment`
Post a comment.

**Parameters:** `pollId` (number), `message` (string).

#### `delete_poll_comment`
Delete one of your comments.

**Parameters:** `commentId` (number).

### Shares

#### `list_poll_shares`
List all shares on a poll (public link, user invitations, email invitations).

**Parameters:** `pollId` (number).

#### `add_poll_share`
Add a share.

**Parameters:**
- `pollId` (number)
- `type` (`public` | `user` | `email`)
- `userId` (string) — required when `type=user` (Nextcloud user ID) or `type=email` (the email address)
- `displayName` (string) — required when `type=email`

#### `delete_poll_share`
Revoke a share. Parameters: `token` (string) — obtained from `list_poll_shares` or `add_poll_share`.

### Subscription

#### `set_poll_subscription`
Subscribe or unsubscribe yourself from poll notifications (new votes, comments).

**Parameters:**
- `pollId` (number)
- `subscribe` (boolean)

Current subscription status is exposed on `get_poll` via `currentUserStatus.isSubscribed`, so there is no separate getter.

## Example Workflow

```
User: "Create a lunch poll with three options and invite bob and charlie."
Claude:
  1. create_poll(title: "Lunch", type: "textPoll")         → pollId = 42
  2. add_text_poll_option(pollId: 42, text: "Pizza")
  3. add_text_poll_option(pollId: 42, text: "Sushi")
  4. add_text_poll_option(pollId: 42, text: "Ramen")
  5. add_poll_share(pollId: 42, type: "user", userId: "bob")
  6. add_poll_share(pollId: 42, type: "user", userId: "charlie")
```

## Limitations / Not Implemented

These endpoints exist in the Polls API but are not exposed as MCP tools (can be added later):

- `trash_poll` — soft-delete/restore via `/poll/{id}/trash`
- `update_poll_option` — edit an existing option's text/time
- `confirm_poll_option` — confirm the winning option
- `reorder_poll_options` — reorder text-poll options
- `delete_poll_user` — kick a user's votes from a poll
- `delete_orphaned_votes` — purge votes for deleted options

## References

- [Nextcloud Polls app](https://apps.nextcloud.com/apps/polls)
- [Polls API v1.0 documentation](https://github.com/nextcloud/polls/blob/main/docs/API_v1.0.md) (DRAFT)
- Source: [`mcp-server/src/tools/apps/polls.ts`](../../../../mcp-server/src/tools/apps/polls.ts)
- Client: [`mcp-server/src/client/polls.ts`](../../../../mcp-server/src/client/polls.ts)
