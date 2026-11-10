# Users Tools

List user accounts, read their details, and enable or disable them.

## Prerequisites

- An administrator account, or a group administrator for the users of their groups. `get_user_info` also works for a user's own account.

## API

Uses the [user provisioning OCS API](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/instruction_set_for_users.html) at `/ocs/v2.php/cloud/users`. The category is always registered.

## Available Tools

#### `list_users`
List user IDs.

**Parameters:**
- `search` (string, optional) — filter by user ID, display name or email.
- `limit` (number, optional)
- `offset` (number, optional)

#### `get_user_info`
Get an account's details: display name, email, groups, quota, language, last login and whether it is enabled.

**Parameters:**
- `userId` (string, required)

#### `enable_user`
Enable a disabled account.

**Parameters:**
- `userId` (string, required)

#### `disable_user`
Disable an account. The user can no longer sign in, and their data is kept.

**Parameters:**
- `userId` (string, required)

**Example:** "Disable the account of the intern who left last week."
