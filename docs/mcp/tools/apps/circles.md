# Nextcloud Teams Tools

Integration with Nextcloud Teams, which the API calls Circles. Manage teams and their members, and find out what a team owns: its workspace folder, and the boards and conversations attached to it.

## Prerequisites

- The Teams app (app ID: `circles`), which ships with Nextcloud
- For workspaces: Nextcloud 35 and a team folder provider, such as the Team folders app (`groupfolders`)

## API

Uses the Circles OCS API at `/ocs/v2.php/apps/circles/`, and the core Teams API at `/ocs/v2.php/teams/` for a team's resources.

## Available Tools

### Teams

#### `circles_list`
List the teams the user can access.

#### `circles_get`
Get a team's details, including its description and settings.

#### `circles_create`
Create a team.

#### `circles_delete`
Delete a team. Requires owner rights.

#### `circles_search`
Search teams by name.

### Members

#### `circles_list_members`
List the members of a team with their roles and member IDs.

#### `circles_add_member`
Add a member. Requires moderator rights or higher.

#### `circles_remove_member`
Remove a member by the member ID from `circles_list_members`, not the user ID.

### Workspaces

A workspace is a shared folder that belongs to the team. It appears in every member's files at its mount point, so `list_files`, `read_file` and `write_file` work on the path `circles_get_workspace` reports.

#### `circles_get_workspace`
Show what a team owns.

**Parameters:**
- `circleId` (string, required)

**Returns:** the workspace path and folder ID, or `none`, and one line per resource attached to the team, such as a Deck board or Talk conversation.

**Example:** "What does the Marketing team have, and where are its files?"

#### `circles_create_workspace`
Give a team its workspace folder. Calling it again for a team that has one changes nothing. Requires owner or admin rights.

**Parameters:**
- `circleId` (string, required)
- `name` (string, optional) — folder name. Default: the team name.

#### `circles_set_workspace_quota`
Set the workspace's storage quota. Requires owner or admin rights. The API does not report a quota back, so `circles_get_workspace` does not show it. Administrators can see it in `occ groupfolders:list`.

**Parameters:**
- `circleId` (string, required)
- `quota` (integer, required) — bytes.

#### `circles_remove_workspace`
Unlink a team's workspace. Requires owner or admin rights.

**Parameters:**
- `circleId` (string, required)
- `deleteFolder` (boolean, optional) — also delete the folder and its contents, which cannot be undone. Default `false`, which keeps the folder without a team.

## Team ownership of Deck boards

`deck_create_board` takes `teamId` on Nextcloud 35. The board belongs to the team, and all members can edit it. `deck_get_board` shows the team, and the board appears in `circles_get_workspace`.

Attaching a team to a board that already exists, and team ownership of Collectives, are not exposed by these tools: Deck only sets it at creation, and Collectives is not integrated.
