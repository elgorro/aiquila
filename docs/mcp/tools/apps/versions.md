# File Versions Tools

List the earlier versions of a file and roll back to one.

## Prerequisites

- The Versions app (app ID: `files_versions`), which ships with Nextcloud and is enabled by default. The category is always registered.

## API

Uses the [Versions WebDAV API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/versions.html) at `/remote.php/dav/versions/<user>/`. Versions are addressed by the file's numeric ID, which `get_file_info` returns.

## Available Tools

#### `list_file_versions`
List the earlier versions of a file, with their modification time and size.

**Parameters:**
- `fileId` (number, required) — from `get_file_info`.

#### `restore_file_version`
Restore an earlier version. Nextcloud keeps the current content as a version of its own, so the restore can itself be undone.

**Parameters:**
- `fileId` (number, required)
- `versionId` (string, required) — from `list_file_versions`.

**Example:** "Put the meeting notes back to how they were this morning."
