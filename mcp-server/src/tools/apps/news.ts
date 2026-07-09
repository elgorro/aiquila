// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchNewsAPI, type Feed, type Folder, type Item } from '../../client/news.js';

/**
 * Nextcloud News App Tools
 * Manages RSS feeds, folders, and articles via the News REST API v1-3.
 */

// ── Response shapes ─────────────────────────────────────────────────────────

interface FeedsResponse {
  feeds: Feed[];
  starredCount?: number;
  newestItemId?: number;
}

interface FoldersResponse {
  folders: Folder[];
}

interface ItemsResponse {
  items: Item[];
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function err(action: string, error: unknown) {
  return {
    content: [
      {
        type: 'text' as const,
        text: `Error ${action}: ${error instanceof Error ? error.message : String(error)}`,
      },
    ],
    isError: true,
  };
}

function text(body: string) {
  return { content: [{ type: 'text' as const, text: body }] };
}

/** Strip HTML tags and collapse whitespace, then truncate. */
function stripHtml(html: string, max = 280): string {
  const plain = html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  return plain.length > max ? `${plain.slice(0, max)}…` : plain;
}

function formatFeed(f: Feed): string {
  const parts = [`- **${f.title || '(untitled)'}** — ${f.url}`];
  parts.push(`  ID: ${f.id} | Folder: ${f.folderId} | Unread: ${f.unreadCount}`);
  if (f.lastUpdateError) parts.push(`  ⚠ Update error: ${f.lastUpdateError}`);
  return parts.join('\n');
}

function formatItem(i: Item): string {
  const flags = [i.unread ? 'unread' : 'read', i.starred ? 'starred' : null]
    .filter(Boolean)
    .join(', ');
  const lines = [`- **${i.title || '(untitled)'}**  (ID: ${i.id}, feed: ${i.feedId}, ${flags})`];
  lines.push(`  ${i.url}`);
  if (i.author) lines.push(`  by ${i.author} — ${new Date(i.pubDate * 1000).toISOString()}`);
  else lines.push(`  ${new Date(i.pubDate * 1000).toISOString()}`);
  if (i.body) lines.push(`  ${stripHtml(i.body)}`);
  return lines.join('\n');
}

// ── Feed tools ──────────────────────────────────────────────────────────────

export const listFeedsTool = {
  name: 'list_feeds',
  title: 'List News Feeds',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all RSS feeds subscribed in the Nextcloud News app, with unread counts.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchNewsAPI<FeedsResponse>('/feeds');
      if (!result.feeds || result.feeds.length === 0) {
        return text('No feeds subscribed.');
      }
      const formatted = result.feeds.map(formatFeed).join('\n');
      return text(`Feeds (${result.feeds.length}):\n\n${formatted}`);
    } catch (error) {
      return err('listing feeds', error);
    }
  },
};

export const addFeedTool = {
  name: 'add_feed',
  title: 'Add News Feed',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: true,
  },
  description: 'Subscribe to a new RSS feed by URL, optionally placing it in a folder.',
  inputSchema: z.object({
    url: z.string().describe('The RSS/Atom feed URL to subscribe to'),
    folderId: z.number().optional().describe('Folder ID to place the feed in (omit for root)'),
  }),
  handler: async (args: { url: string; folderId?: number }) => {
    try {
      const body: Record<string, unknown> = { url: args.url };
      if (args.folderId !== undefined) body.folderId = args.folderId;
      const result = await fetchNewsAPI<FeedsResponse>('/feeds', { method: 'POST', body });
      const feed = result.feeds?.[0];
      return text(
        feed
          ? `Subscribed to feed "${feed.title}" (ID: ${feed.id}).`
          : `Subscribed to feed: ${args.url}`
      );
    } catch (error) {
      return err('adding feed', error);
    }
  },
};

export const deleteFeedTool = {
  name: 'delete_feed',
  title: 'Delete News Feed',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete (unsubscribe from) a feed and all its items.',
  inputSchema: z.object({
    feedId: z.number().describe('The feed ID to delete'),
  }),
  handler: async (args: { feedId: number }) => {
    try {
      await fetchNewsAPI(`/feeds/${args.feedId}`, { method: 'DELETE' });
      return text(`Deleted feed ${args.feedId}.`);
    } catch (error) {
      return err('deleting feed', error);
    }
  },
};

export const moveFeedTool = {
  name: 'move_feed',
  title: 'Move News Feed',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Move a feed into a different folder.',
  inputSchema: z.object({
    feedId: z.number().describe('The feed ID to move'),
    folderId: z.number().describe('Destination folder ID (0 for root)'),
  }),
  handler: async (args: { feedId: number; folderId: number }) => {
    try {
      await fetchNewsAPI(`/feeds/${args.feedId}/move`, {
        method: 'POST',
        body: { folderId: args.folderId },
      });
      return text(`Moved feed ${args.feedId} to folder ${args.folderId}.`);
    } catch (error) {
      return err('moving feed', error);
    }
  },
};

export const renameFeedTool = {
  name: 'rename_feed',
  title: 'Rename News Feed',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Rename a feed.',
  inputSchema: z.object({
    feedId: z.number().describe('The feed ID to rename'),
    feedTitle: z.string().describe('The new feed title'),
  }),
  handler: async (args: { feedId: number; feedTitle: string }) => {
    try {
      await fetchNewsAPI(`/feeds/${args.feedId}/rename`, {
        method: 'POST',
        body: { feedTitle: args.feedTitle },
      });
      return text(`Renamed feed ${args.feedId} to "${args.feedTitle}".`);
    } catch (error) {
      return err('renaming feed', error);
    }
  },
};

export const markFeedReadTool = {
  name: 'mark_feed_read',
  title: 'Mark Feed as Read',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Mark all items in a feed as read up to (and including) the given newest item ID.',
  inputSchema: z.object({
    feedId: z.number().describe('The feed ID'),
    newestItemId: z.number().describe('Mark all items with ID <= this value as read'),
  }),
  handler: async (args: { feedId: number; newestItemId: number }) => {
    try {
      await fetchNewsAPI(`/feeds/${args.feedId}/read`, {
        method: 'POST',
        body: { newestItemId: args.newestItemId },
      });
      return text(`Marked feed ${args.feedId} read up to item ${args.newestItemId}.`);
    } catch (error) {
      return err('marking feed read', error);
    }
  },
};

// ── Folder tools ────────────────────────────────────────────────────────────

export const listNewsFoldersTool = {
  name: 'list_news_folders',
  title: 'List News Folders',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all folders used to organize feeds in the Nextcloud News app.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchNewsAPI<FoldersResponse>('/folders');
      if (!result.folders || result.folders.length === 0) {
        return text('No folders found.');
      }
      const formatted = result.folders.map((f) => `- **${f.name}** (ID: ${f.id})`).join('\n');
      return text(`News folders (${result.folders.length}):\n\n${formatted}`);
    } catch (error) {
      return err('listing folders', error);
    }
  },
};

export const createNewsFolderTool = {
  name: 'create_news_folder',
  title: 'Create News Folder',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Create a new folder for organizing feeds in the News app.',
  inputSchema: z.object({
    name: z.string().describe('The folder name'),
  }),
  handler: async (args: { name: string }) => {
    try {
      const result = await fetchNewsAPI<FoldersResponse>('/folders', {
        method: 'POST',
        body: { name: args.name },
      });
      const folder = result.folders?.[0];
      return text(
        folder
          ? `Created folder "${folder.name}" (ID: ${folder.id}).`
          : `Created folder "${args.name}".`
      );
    } catch (error) {
      return err('creating folder', error);
    }
  },
};

export const renameNewsFolderTool = {
  name: 'rename_news_folder',
  title: 'Rename News Folder',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Rename a News folder.',
  inputSchema: z.object({
    folderId: z.number().describe('The folder ID to rename'),
    name: z.string().describe('The new folder name'),
  }),
  handler: async (args: { folderId: number; name: string }) => {
    try {
      await fetchNewsAPI(`/folders/${args.folderId}`, {
        method: 'PUT',
        body: { name: args.name },
      });
      return text(`Renamed folder ${args.folderId} to "${args.name}".`);
    } catch (error) {
      return err('renaming folder', error);
    }
  },
};

export const deleteNewsFolderTool = {
  name: 'delete_news_folder',
  title: 'Delete News Folder',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a News folder and all feeds it contains.',
  inputSchema: z.object({
    folderId: z.number().describe('The folder ID to delete'),
  }),
  handler: async (args: { folderId: number }) => {
    try {
      await fetchNewsAPI(`/folders/${args.folderId}`, { method: 'DELETE' });
      return text(`Deleted folder ${args.folderId}.`);
    } catch (error) {
      return err('deleting folder', error);
    }
  },
};

export const markNewsFolderReadTool = {
  name: 'mark_news_folder_read',
  title: 'Mark News Folder as Read',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Mark all items in a folder as read up to (and including) the given newest item ID.',
  inputSchema: z.object({
    folderId: z.number().describe('The folder ID'),
    newestItemId: z.number().describe('Mark all items with ID <= this value as read'),
  }),
  handler: async (args: { folderId: number; newestItemId: number }) => {
    try {
      await fetchNewsAPI(`/folders/${args.folderId}/read`, {
        method: 'POST',
        body: { newestItemId: args.newestItemId },
      });
      return text(`Marked folder ${args.folderId} read up to item ${args.newestItemId}.`);
    } catch (error) {
      return err('marking folder read', error);
    }
  },
};

// ── Item / article tools ────────────────────────────────────────────────────

export const listNewsItemsTool = {
  name: 'list_news_items',
  title: 'List News Items',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List news articles (items). Filter by feed, folder, starred, or all; supports pagination and read/unread filtering.',
  inputSchema: z.object({
    type: z
      .enum(['feed', 'folder', 'starred', 'all'])
      .optional()
      .describe('Scope of items to return (default "all")'),
    id: z
      .number()
      .optional()
      .describe('Feed or folder ID when type is "feed" or "folder" (default 0)'),
    batchSize: z.number().optional().describe('Max items to return (default 20, -1 for all)'),
    offset: z.number().optional().describe('Return items older than this item ID (pagination)'),
    getRead: z.boolean().optional().describe('Include already-read items (default false)'),
    oldestFirst: z
      .boolean()
      .optional()
      .describe('Return oldest items first (default newest first)'),
  }),
  handler: async (args: {
    type?: 'feed' | 'folder' | 'starred' | 'all';
    id?: number;
    batchSize?: number;
    offset?: number;
    getRead?: boolean;
    oldestFirst?: boolean;
  }) => {
    try {
      const typeMap = { feed: 0, folder: 1, starred: 2, all: 3 } as const;
      const queryParams: Record<string, string | number | boolean | undefined> = {
        type: typeMap[args.type ?? 'all'],
        id: args.id ?? 0,
        batchSize: args.batchSize ?? 20,
        getRead: args.getRead ?? false,
        oldestFirst: args.oldestFirst ?? false,
      };
      if (args.offset !== undefined) queryParams.offset = args.offset;

      const result = await fetchNewsAPI<ItemsResponse>('/items', { queryParams });
      if (!result.items || result.items.length === 0) {
        return text('No items found.');
      }
      const formatted = result.items.map(formatItem).join('\n\n');
      return text(`Items (${result.items.length}):\n\n${formatted}`);
    } catch (error) {
      return err('listing items', error);
    }
  },
};

export const markItemReadTool = {
  name: 'mark_item_read',
  title: 'Mark News Item as Read',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Mark a single news item as read.',
  inputSchema: z.object({
    itemId: z.number().describe('The item ID'),
  }),
  handler: async (args: { itemId: number }) => {
    try {
      await fetchNewsAPI(`/items/${args.itemId}/read`, { method: 'POST' });
      return text(`Marked item ${args.itemId} read.`);
    } catch (error) {
      return err('marking item read', error);
    }
  },
};

export const markItemUnreadTool = {
  name: 'mark_item_unread',
  title: 'Mark News Item as Unread',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Mark a single news item as unread.',
  inputSchema: z.object({
    itemId: z.number().describe('The item ID'),
  }),
  handler: async (args: { itemId: number }) => {
    try {
      await fetchNewsAPI(`/items/${args.itemId}/unread`, { method: 'POST' });
      return text(`Marked item ${args.itemId} unread.`);
    } catch (error) {
      return err('marking item unread', error);
    }
  },
};

export const starItemTool = {
  name: 'star_item',
  title: 'Star News Item',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Star (favorite) a single news item.',
  inputSchema: z.object({
    itemId: z.number().describe('The item ID'),
  }),
  handler: async (args: { itemId: number }) => {
    try {
      await fetchNewsAPI(`/items/${args.itemId}/star`, { method: 'POST' });
      return text(`Starred item ${args.itemId}.`);
    } catch (error) {
      return err('starring item', error);
    }
  },
};

export const unstarItemTool = {
  name: 'unstar_item',
  title: 'Unstar News Item',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Remove the star from a single news item.',
  inputSchema: z.object({
    itemId: z.number().describe('The item ID'),
  }),
  handler: async (args: { itemId: number }) => {
    try {
      await fetchNewsAPI(`/items/${args.itemId}/unstar`, { method: 'POST' });
      return text(`Unstarred item ${args.itemId}.`);
    } catch (error) {
      return err('unstarring item', error);
    }
  },
};

export const markItemsReadTool = {
  name: 'mark_items_read',
  title: 'Mark News Items as Read',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Mark multiple news items as read in one call.',
  inputSchema: z.object({
    itemIds: z.array(z.number()).describe('The item IDs to mark as read'),
  }),
  handler: async (args: { itemIds: number[] }) => {
    try {
      await fetchNewsAPI('/items/read/multiple', {
        method: 'POST',
        body: { itemIds: args.itemIds },
      });
      return text(`Marked ${args.itemIds.length} item(s) read.`);
    } catch (error) {
      return err('marking items read', error);
    }
  },
};

// ── Export ──────────────────────────────────────────────────────────────────

export const newsTools = [
  listFeedsTool,
  addFeedTool,
  deleteFeedTool,
  moveFeedTool,
  renameFeedTool,
  markFeedReadTool,
  listNewsFoldersTool,
  createNewsFolderTool,
  renameNewsFolderTool,
  deleteNewsFolderTool,
  markNewsFolderReadTool,
  listNewsItemsTool,
  markItemReadTool,
  markItemUnreadTool,
  starItemTool,
  unstarItemTool,
  markItemsReadTool,
];
