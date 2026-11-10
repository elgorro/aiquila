# Text Tools

Work with the rich workspace of a folder, the `Readme.md` that [Nextcloud Text](https://apps.nextcloud.com/apps/text) shows above the file list, and with the inline comments in Markdown documents.

## Prerequisites

- Text enabled (app ID: `text`), which ships with Nextcloud
- Rich workspaces turned on, which is the default
- For writing inline comments: Nextcloud 35, whose Text is the first to show them as comments

## API

Uses the Text OCS API at `/ocs/v2.php/apps/text/workspace` to find a folder's workspace file and to open a direct editing session, and WebDAV to read and write its content. The app is documented only through its [source](https://github.com/nextcloud/text).

## Available Tools

### Workspaces

The workspace tools take `path` (string, required): the folder, relative to the user's root, such as `/Projects/Acme`.

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

### Inline comments

Text attaches comment threads to a spot in a Markdown document. It has no API for them: a thread is part of the file. The spot carries a footnote reference whose label starts with `comment-`, and the footnote holds the thread, one list item per comment, with author and time:

```markdown
The budget is final.[^comment-1]

[^comment-1]:
    - @[Alice](mention://user/alice) *(2026-11-10T09:00:00.000Z)*
      Is it? Finance has not signed off.
    - @[Bob](mention://user/bob) *(2026-11-10T09:30:00.000Z)*
      They did this morning.
```

So these tools read and rewrite the file, in the format Text writes. A write sends the ETag of the version it read in `If-Match`. If the file changed in between, nothing is saved and the tool says so. Someone who has the document open in Text at that moment gets the change like any other edit made outside the editor.

They take `path` (string, required): a `.md` file. Thread IDs look like `comment-3`. Before Nextcloud 35, Text shows these threads as ordinary footnotes, so writing them is refused there; listing and deleting work on any version.

#### `list_text_comments`
List the threads with their ID, the text they are attached to, and each comment's author, time and text.

#### `add_text_comment`
Start a thread as the signed-in user.

**Parameters:**
- `anchor` (string, required) — text in the document that the comment follows. It must be on one line and occur exactly once outside code blocks and other threads; quote more of it if it occurs more often.
- `message` (string, required) — Markdown.

**Example:** "Ask on the delivery date in the plan whether Friday is realistic."

#### `reply_text_comment`
Reply to a thread as the signed-in user.

**Parameters:**
- `commentId` (string, required)
- `message` (string, required)

#### `delete_text_comment`
Delete a thread, which is how Text resolves one, or a single comment in it. The document text stays.

**Parameters:**
- `commentId` (string, required)
- `reply` (integer, optional) — the position of the one comment to delete, where 2 is the first reply. Leave it out, or pass 1, to delete the thread.

Anyone who can edit the file can delete any thread, since it is part of the document.

File comments, the ones in the Files sidebar, are covered by the [File Comments tools](comments.md).
