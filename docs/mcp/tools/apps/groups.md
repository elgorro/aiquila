# Groups Tools

List groups, see their members, and change memberships.

## Prerequisites

- An administrator account, or a group administrator for the groups they manage. Adding and removing members needs an administrator, or a group administrator of that group.

## API

Uses the [group provisioning OCS API](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/instruction_set_for_groups.html) at `/ocs/v2.php/cloud/groups`, and the user endpoints at `/ocs/v2.php/cloud/users/<userId>/groups` for memberships. The category is always registered.

## Available Tools

#### `list_groups`
List group IDs.

**Parameters:**
- `search` (string, optional)
- `limit` (number, optional)
- `offset` (number, optional)

#### `get_group_info`
List a group's members.

**Parameters:**
- `groupId` (string, required)

#### `add_user_to_group`
**Parameters:**
- `userId` (string, required)
- `groupId` (string, required)

**Example:** "Add Maria to the finance group."

#### `remove_user_from_group`
**Parameters:**
- `userId` (string, required)
- `groupId` (string, required)
