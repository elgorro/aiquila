# Trash Tools

List, restore and empty the deleted files of the signed-in user.

## Prerequisites

- The Deleted files app (app ID: `files_trashbin`), which ships with Nextcloud and is enabled by default. The category is always registered.

## API

Uses the [Trashbin WebDAV API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/trashbin.html) at `/remote.php/dav/trashbin/<user>/`.

## Available Tools

#### `list_trash`
List the files and folders in the trash.

**Returns:** for each item its name, size, original location, deletion time and **Key**. The key identifies the item in the trash and is what `restore_from_trash` takes.

#### `restore_from_trash`
Restore an item to its original location.

**Parameters:**
- `trashKey` (string, required) — the Key from `list_trash`.

**Example:** "Restore the budget spreadsheet I deleted yesterday."

#### `empty_trash`
Permanently delete everything in the trash. This cannot be undone.
