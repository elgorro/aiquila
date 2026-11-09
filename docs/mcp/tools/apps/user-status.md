# User Status Tools

Read and set presence: the status type, and a custom message with an emoji.

## Prerequisites

- User status app enabled (app ID: `user_status`), which ships with Nextcloud

## API

Uses the [Status OCS API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-status-api.html) at `/ocs/v2.php/apps/user_status/api/v1/`.

## Available Tools

#### `get_user_status`
Get a user's status type, message and emoji.

**Parameters:**
- `userId` (string, optional) — default: the signed-in user.

#### `set_user_status`
Set the signed-in user's status type.

**Parameters:**
- `statusType` (enum, required) — `online`, `away`, `dnd`, `invisible` or `offline`.

#### `set_user_status_message`
Set the signed-in user's custom message.

**Parameters:**
- `message` (string, required)
- `statusIcon` (string, optional) — an emoji, such as `☕`.
- `clearAt` (number, optional) — Unix timestamp at which the message clears itself. Default: never.

**Example:** "Set my status to 'In a workshop' with a 🛠 until 5 pm."

#### `clear_user_status_message`
Clear the signed-in user's custom message. The status type is kept.

#### `list_user_statuses`
List the statuses of the users the signed-in user can see.

**Parameters:**
- `limit` (number, optional)
- `offset` (number, optional)
