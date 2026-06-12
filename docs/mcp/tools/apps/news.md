# Nextcloud News Tools

Integration with the Nextcloud News app. Manage RSS/Atom feeds, organize them into folders, and read and triage articles through Claude.

## Prerequisites

- Nextcloud News app must be installed and enabled
- Uses the News REST API v1-3

## Available Tools

| Tool | Description |
|------|-------------|
| `list_feeds` | List all subscribed feeds with unread counts |
| `add_feed` | Subscribe to a new feed by URL |
| `delete_feed` | Delete (unsubscribe from) a feed |
| `move_feed` | Move a feed into a folder |
| `rename_feed` | Rename a feed |
| `mark_feed_read` | Mark all items in a feed as read |
| `list_news_folders` | List feed folders |
| `create_news_folder` | Create a folder |
| `rename_news_folder` | Rename a folder |
| `delete_news_folder` | Delete a folder and its feeds |
| `mark_news_folder_read` | Mark all items in a folder as read |
| `list_news_items` | List/filter articles (feed, folder, starred, all) |
| `mark_item_read` | Mark an article read |
| `mark_item_unread` | Mark an article unread |
| `star_item` | Star an article |
| `unstar_item` | Unstar an article |
| `mark_items_read` | Mark multiple articles read in one call |

---

## Feed Tools

### list_feeds
List all RSS feeds subscribed in the Nextcloud News app, with unread counts. No parameters.

### add_feed
Subscribe to a new RSS feed by URL, optionally placing it in a folder.

**Parameters:**
- `url` (string, required): The RSS/Atom feed URL
- `folderId` (number, optional): Folder ID to place the feed in (omit for root)

### delete_feed
Delete (unsubscribe from) a feed and all its items.

**Parameters:**
- `feedId` (number, required): The feed ID

### move_feed
Move a feed into a different folder.

**Parameters:**
- `feedId` (number, required): The feed ID
- `folderId` (number, required): Destination folder ID (0 for root)

### rename_feed
Rename a feed.

**Parameters:**
- `feedId` (number, required): The feed ID
- `feedTitle` (string, required): The new feed title

### mark_feed_read
Mark all items in a feed as read up to (and including) the given newest item ID.

**Parameters:**
- `feedId` (number, required): The feed ID
- `newestItemId` (number, required): Mark all items with ID ≤ this value as read

---

## Folder Tools

### list_news_folders
List all folders used to organize feeds. No parameters.

### create_news_folder
Create a new folder.

**Parameters:**
- `name` (string, required): The folder name

### rename_news_folder
Rename a folder.

**Parameters:**
- `folderId` (number, required): The folder ID
- `name` (string, required): The new folder name

### delete_news_folder
Delete a folder and all feeds it contains.

**Parameters:**
- `folderId` (number, required): The folder ID

### mark_news_folder_read
Mark all items in a folder as read up to (and including) the given newest item ID.

**Parameters:**
- `folderId` (number, required): The folder ID
- `newestItemId` (number, required): Mark all items with ID ≤ this value as read

---

## Item Tools

### list_news_items
List news articles. Filter by feed, folder, starred, or all; supports pagination and read/unread filtering.

**Parameters:**
- `type` (`feed` | `folder` | `starred` | `all`, optional): Scope (default `all`)
- `id` (number, optional): Feed or folder ID when `type` is `feed` or `folder` (default 0)
- `batchSize` (number, optional): Max items to return (default 20, -1 for all)
- `offset` (number, optional): Return items older than this item ID (pagination)
- `getRead` (boolean, optional): Include already-read items (default false)
- `oldestFirst` (boolean, optional): Return oldest items first (default newest first)

### mark_item_read / mark_item_unread
Mark a single article read or unread.

**Parameters:**
- `itemId` (number, required): The item ID

### star_item / unstar_item
Star or unstar a single article.

**Parameters:**
- `itemId` (number, required): The item ID

### mark_items_read
Mark multiple articles as read in one call.

**Parameters:**
- `itemIds` (number[], required): The item IDs to mark as read
