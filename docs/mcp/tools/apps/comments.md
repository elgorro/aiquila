# File Comments Tools

Read and write the comments on a file or folder: the Comments tab of the Files sidebar.

## Prerequisites

- Comments enabled (app ID: `comments`), which ships with Nextcloud and is on by default. The app announces itself through the `files.comments` capability, which is how the tools are found for accounts that are not administrators.
- Access to the file. Anyone who can see a file can read and add comments; only a comment's author can change or delete it.

## API

The comments app has no OCS API. The tools use the WebDAV collection of a file's comments at `/remote.php/dav/comments/files/<fileId>`, as the Files app does, implemented in Nextcloud's [`apps/dav/lib/Comments`](https://github.com/nextcloud/server/tree/master/apps/dav/lib/Comments):

| Tool | Request |
|---|---|
| `list_file_comments` | `REPORT` with `oc:filter-comments` |
| `add_file_comment` | `POST` of a JSON body |
| `update_file_comment` | `PROPPATCH` of `oc:message` on the comment |
| `delete_file_comment` | `DELETE` on the comment |
| `mark_file_comments_read` | `PROPPATCH` of `oc:readMarker` on the collection |

## Available Tools

Every tool takes the file as `path` (string), such as `/Documents/report.odt`, or as `fileId` (integer, from `get_file_info`). One of them is required.

#### `list_file_comments`
List the comments, newest first, with ID, author, time and whether the signed-in user has read them.

**Parameters:**
- `limit` (integer, optional) — at most 100. Default 50.
- `offset` (integer, optional) — skip this many of the newest comments.

**Example:** "What did people say about the Q3 report?"

#### `add_file_comment`
Comment as the signed-in user, in plain text of at most 1000 characters. Writing `@userid` mentions someone, and Nextcloud notifies them.

**Parameters:**
- `message` (string, required)

#### `update_file_comment`
Change the text of one of your own comments.

**Parameters:**
- `commentId` (string, required) — from `list_file_comments`.
- `message` (string, required)

#### `delete_file_comment`
Delete one of your own comments.

**Parameters:**
- `commentId` (string, required)

#### `mark_file_comments_read`
Mark every comment on the file as read, as opening its Comments tab does.

## Comments inside documents

Comments placed inside the text of a document are a different thing. Those in Markdown files that Nextcloud Text edits are covered by the [Text tools](text.md#inline-comments).
