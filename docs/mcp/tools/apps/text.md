# Text Tools

Work with the rich workspace of a folder: the `Readme.md` that [Nextcloud Text](https://apps.nextcloud.com/apps/text) shows above the file list.

## Prerequisites

- Text enabled (app ID: `text`), which ships with Nextcloud
- Rich workspaces turned on, which is the default

## API

Uses the Text OCS API at `/ocs/v2.php/apps/text/workspace` to find a folder's workspace file and to open a direct editing session, and WebDAV to read and write its content. The app is documented only through its [source](https://github.com/nextcloud/text).

## Available Tools

All tools take `path` (string, required): the folder, relative to the user's root, such as `/Projects/Acme`.

#### `get_text_workspace`
Get the workspace file's ID, name, MIME type and path, or report that the folder has none.

#### `read_text_workspace`
Read the workspace as Markdown.

#### `write_text_workspace`
Create or overwrite the workspace.

**Parameters:**
- `content` (string, required) — Markdown.

An existing workspace keeps its file name. Otherwise `Readme.md` is created.

**Example:** "Write a short overview of this project into the folder's workspace."

#### `delete_text_workspace`
Delete the workspace file. The folder is kept. A folder without a workspace is left as it is.

#### `get_text_workspace_edit_url`
Get a one-time URL that opens the workspace in the collaborative editor, creating `Readme.md` first if needed. The URL is for a person to open in a browser; the MCP server does not take part in the editing session.
