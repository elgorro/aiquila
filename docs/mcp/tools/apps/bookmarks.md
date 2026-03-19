# Nextcloud Bookmarks Tools

Integration with Nextcloud Bookmarks app. Manage bookmarks, folders, and tags through Claude.

## Prerequisites

- Nextcloud Bookmarks app must be installed and enabled
- Bookmarks API v2 must be available (Bookmarks app v4+)

## Available Tools

| Tool | Description |
|------|-------------|
| `list_bookmarks` | List and search bookmarks |
| `get_bookmark` | Get full bookmark details |
| `create_bookmark` | Create a new bookmark |
| `update_bookmark` | Update an existing bookmark |
| `delete_bookmark` | Delete a bookmark |
| `list_bookmark_folders` | List folder hierarchy |
| `get_bookmark_folder_contents` | Get folder contents |
| `create_bookmark_folder` | Create a new folder |
| `update_bookmark_folder` | Rename or move a folder |
| `delete_bookmark_folder` | Delete a folder and contents |
| `list_bookmark_tags` | List all tags |
| `rename_bookmark_tag` | Rename a tag across all bookmarks |
| `delete_bookmark_tag` | Delete a tag from all bookmarks |

---

## Bookmark Tools

### list_bookmarks

List bookmarks from Nextcloud Bookmarks app. Supports search, tag filtering, folder filtering, sorting, and pagination.

**Parameters:**
- `search` (string, optional): Search term to filter by title/URL/description
- `tags` (string[], optional): Filter by tags
- `folder` (number, optional): Filter by folder ID (-1 for root)
- `page` (number, optional): Page number for pagination (starts at 0)
- `limit` (number, optional): Max bookmarks to return
- `sortby` (enum, optional): Sort field — `lastmodified`, `title`, `clickcount`, `url`, `added`
- `untagged` (boolean, optional): Only show bookmarks without tags
- `unavailable` (boolean, optional): Only show unavailable bookmarks

**Returns:**
List of bookmarks with title, URL, description, tags, and folder assignments.

**Example Usage:**
```
Ask Claude: "List my bookmarks"
Ask Claude: "Search bookmarks for 'nextcloud'"
Ask Claude: "Show bookmarks tagged 'work' sorted by title"
```

---

### get_bookmark

Get full details of a single bookmark by its ID.

**Parameters:**
- `id` (number, required): The bookmark ID

**Returns:**
Complete bookmark details including URL, title, description, tags, folders, and timestamps.

**Example Usage:**
```
Ask Claude: "Get details for bookmark 42"
```

---

### create_bookmark

Create a new bookmark in Nextcloud Bookmarks.

**Parameters:**
- `url` (string, required): The URL to bookmark
- `title` (string, optional): Bookmark title
- `description` (string, optional): Bookmark description
- `tags` (string[], optional): Tags to assign
- `folders` (number[], optional): Folder IDs to place the bookmark in

**Returns:**
Newly created bookmark with ID.

**Example Usage:**
```
Ask Claude: "Bookmark https://docs.nextcloud.com with title 'NC Docs' and tag 'reference'"
Ask Claude: "Save this URL as a bookmark: https://example.com"
```

---

### update_bookmark

Update an existing bookmark. Only provided fields are changed.

**Parameters:**
- `id` (number, required): The bookmark ID to update
- `url` (string, optional): New URL
- `title` (string, optional): New title
- `description` (string, optional): New description
- `tags` (string[], optional): New tags (replaces existing)
- `folders` (number[], optional): New folder IDs (replaces existing)

**Returns:**
Updated bookmark details.

**Example Usage:**
```
Ask Claude: "Update bookmark 42 with tags 'dev' and 'docs'"
Ask Claude: "Change the title of bookmark 15 to 'New Title'"
```

---

### delete_bookmark

Delete a bookmark by its ID. This action is irreversible.

**Parameters:**
- `id` (number, required): The bookmark ID to delete

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete bookmark 42"
```

---

## Folder Tools

### list_bookmark_folders

List the bookmark folder hierarchy. Returns a tree structure of all folders.

**Parameters:**
- `root` (number, optional): Root folder ID to start from (-1 for top level)
- `layers` (number, optional): Maximum depth of folder levels to return

**Returns:**
Folder tree structure with IDs, titles, and children.

**Example Usage:**
```
Ask Claude: "Show my bookmark folders"
Ask Claude: "List folder hierarchy starting from folder 5"
```

---

### get_bookmark_folder_contents

Get the contents of a bookmark folder (bookmarks and subfolders).

**Parameters:**
- `id` (number, required): The folder ID (-1 for root)
- `layers` (number, optional): Depth of subfolder levels to include

**Returns:**
Folder contents with items (bookmarks and subfolders).

**Example Usage:**
```
Ask Claude: "Show what's in my 'Development' bookmark folder"
Ask Claude: "List contents of bookmark folder 10"
```

---

### create_bookmark_folder

Create a new bookmark folder.

**Parameters:**
- `title` (string, required): Folder name
- `parent_folder` (number, optional): Parent folder ID (-1 for root)

**Returns:**
Created folder with ID.

**Example Usage:**
```
Ask Claude: "Create a bookmark folder called 'Research'"
Ask Claude: "Create a subfolder 'Frontend' inside folder 5"
```

---

### update_bookmark_folder

Update a bookmark folder (rename or move to a different parent).

**Parameters:**
- `id` (number, required): The folder ID to update
- `title` (string, optional): New folder name
- `parent_folder` (number, optional): New parent folder ID (-1 for root)

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Rename bookmark folder 10 to 'Old Projects'"
Ask Claude: "Move folder 10 into folder 5"
```

---

### delete_bookmark_folder

Delete a bookmark folder and all its contents. This action is irreversible.

**Parameters:**
- `id` (number, required): The folder ID to delete

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete bookmark folder 10"
```

---

## Tag Tools

### list_bookmark_tags

List all tags used across bookmarks.

**Parameters:**
None

**Returns:**
List of all bookmark tags.

**Example Usage:**
```
Ask Claude: "What bookmark tags do I have?"
Ask Claude: "List all my bookmark tags"
```

---

### rename_bookmark_tag

Rename a bookmark tag. All bookmarks with the old tag will be updated.

**Parameters:**
- `old_name` (string, required): Current tag name
- `new_name` (string, required): New tag name

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Rename bookmark tag 'dev' to 'development'"
```

---

### delete_bookmark_tag

Delete a bookmark tag. The tag will be removed from all bookmarks.

**Parameters:**
- `name` (string, required): Tag name to delete

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete the bookmark tag 'old'"
```

---

## Workflow Examples

### Organizing Bookmarks
```
User: "Create a folder 'Work Resources' and move all bookmarks tagged 'work' into it"
Claude: Creates folder -> lists bookmarks with tag 'work' -> updates each bookmark's folders
```

### Tag Cleanup
```
User: "Show me all my bookmark tags and rename 'misc' to 'uncategorized'"
Claude: Lists tags -> renames the tag
```

## Development

To extend bookmark tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/bookmarks.ts](../../../../mcp-server/src/tools/apps/bookmarks.ts)

## References

- [Nextcloud Bookmarks App](https://apps.nextcloud.com/apps/bookmarks)
- [Bookmarks API Documentation](https://nextcloud-bookmarks.readthedocs.io/)
