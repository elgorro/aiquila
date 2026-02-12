import { z } from 'zod';
import { fetchBookmarksAPI } from '../../client/bookmarks.js';

/**
 * Nextcloud Bookmarks App Tools
 * Manages bookmarks, bookmark folders, and tags via the Bookmarks REST API.
 */

// ── Interfaces ──────────────────────────────────────────────────────────────

interface Bookmark {
  id: number;
  url: string;
  target: string;
  title: string;
  description: string;
  added: number;
  userId: string;
  tags: string[];
  folders: number[];
  clickcount: number;
  available: boolean;
  archivedFile: string | null;
}

interface BookmarkFolder {
  id: number;
  title: string;
  parent_folder: number;
  userId: string;
  children?: (BookmarkFolder | Bookmark)[];
}

interface BookmarksListResponse {
  status: string;
  data: Bookmark[];
}

interface BookmarkItemResponse {
  status: string;
  item: Bookmark;
}

interface FolderListResponse {
  status: string;
  data: BookmarkFolder[];
}

interface FolderItemResponse {
  status: string;
  item: BookmarkFolder;
}

interface FolderChildrenResponse {
  status: string;
  data: (BookmarkFolder | Bookmark)[];
}

interface TagListResponse {
  status: string;
  data: string[]; // tag list is just an array of tag name strings
}

interface StatusResponse {
  status: string;
}

// ── Formatters ──────────────────────────────────────────────────────────────

function formatBookmarkSummary(b: Bookmark): string {
  const parts = [`- **${b.title || '(untitled)'}** — ${b.url}`];
  if (b.tags.length > 0) parts.push(`  Tags: ${b.tags.join(', ')}`);
  if (b.folders.length > 0) parts.push(`  Folders: ${b.folders.join(', ')}`);
  parts.push(`  ID: ${b.id}`);
  return parts.join('\n');
}

function formatBookmarkDetail(b: Bookmark): string {
  const lines: string[] = [];
  lines.push(`# ${b.title || '(untitled)'}`);
  lines.push('');
  lines.push(`- URL: ${b.url}`);
  lines.push(`- ID: ${b.id}`);
  if (b.description) lines.push(`- Description: ${b.description}`);
  if (b.tags.length > 0) lines.push(`- Tags: ${b.tags.join(', ')}`);
  if (b.folders.length > 0) lines.push(`- Folders: ${b.folders.join(', ')}`);
  lines.push(`- Added: ${new Date(b.added * 1000).toISOString()}`);
  lines.push(`- Clicks: ${b.clickcount}`);
  lines.push(`- Available: ${b.available}`);
  if (b.target) lines.push(`- Target: ${b.target}`);
  return lines.join('\n');
}

function formatFolderTree(folder: BookmarkFolder, indent = 0): string {
  const prefix = '  '.repeat(indent);
  const lines = [`${prefix}- **${folder.title}** (ID: ${folder.id})`];
  if (folder.children) {
    for (const child of folder.children) {
      if ('url' in child) {
        lines.push(
          `${prefix}  - ${(child as Bookmark).title || '(untitled)'} — ${(child as Bookmark).url} (ID: ${child.id})`
        );
      } else {
        lines.push(formatFolderTree(child as BookmarkFolder, indent + 1));
      }
    }
  }
  return lines.join('\n');
}

// ── Bookmark Tools ──────────────────────────────────────────────────────────

export const listBookmarksTool = {
  name: 'list_bookmarks',
  description:
    'List bookmarks from Nextcloud Bookmarks app. Supports search, tag filtering, folder filtering, sorting, and pagination.',
  inputSchema: z.object({
    search: z
      .string()
      .optional()
      .describe('Search term to filter bookmarks by title/URL/description'),
    tags: z.array(z.string()).optional().describe("Filter by tags (e.g. ['tech', 'news'])"),
    folder: z.number().optional().describe('Filter by folder ID (-1 for root)'),
    page: z.number().optional().describe('Page number for pagination (starts at 0)'),
    limit: z.number().optional().describe('Max bookmarks to return (default 10)'),
    sortby: z
      .enum(['lastmodified', 'title', 'clickcount', 'url', 'added'])
      .optional()
      .describe('Sort field'),
    untagged: z.boolean().optional().describe('Only show bookmarks without tags'),
    unavailable: z.boolean().optional().describe('Only show unavailable bookmarks'),
  }),
  handler: async (args: {
    search?: string;
    tags?: string[];
    folder?: number;
    page?: number;
    limit?: number;
    sortby?: string;
    untagged?: boolean;
    unavailable?: boolean;
  }) => {
    try {
      const queryParams: Record<string, string | string[]> = {};
      if (args.search) queryParams['search[]'] = [args.search];
      if (args.tags && args.tags.length > 0) queryParams['tags'] = args.tags;
      if (args.folder !== undefined) queryParams['folder'] = String(args.folder);
      if (args.page !== undefined) queryParams['page'] = String(args.page);
      if (args.limit !== undefined) queryParams['limit'] = String(args.limit);
      if (args.sortby) queryParams['sortby'] = args.sortby;
      if (args.untagged) queryParams['untagged'] = 'true';
      if (args.unavailable) queryParams['unavailable'] = 'true';

      const result = await fetchBookmarksAPI<BookmarksListResponse>('/bookmark', { queryParams });

      if (result.data.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text:
                args.search || args.tags
                  ? 'No bookmarks found matching the given filters.'
                  : 'No bookmarks found.',
            },
          ],
        };
      }

      const formatted = result.data.map((b) => formatBookmarkSummary(b)).join('\n');

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmarks (${result.data.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing bookmarks: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const getBookmarkTool = {
  name: 'get_bookmark',
  description: 'Get full details of a single bookmark by its ID.',
  inputSchema: z.object({
    id: z.number().describe('The bookmark ID'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const result = await fetchBookmarksAPI<BookmarkItemResponse>(`/bookmark/${args.id}`);

      return {
        content: [
          {
            type: 'text' as const,
            text: formatBookmarkDetail(result.item),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting bookmark ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const createBookmarkTool = {
  name: 'create_bookmark',
  description:
    'Create a new bookmark in Nextcloud Bookmarks. URL is required; title, description, tags, and folders are optional.',
  inputSchema: z.object({
    url: z.string().describe('The URL to bookmark'),
    title: z.string().optional().describe('Bookmark title'),
    description: z.string().optional().describe('Bookmark description'),
    tags: z.array(z.string()).optional().describe("Tags to assign (e.g. ['tech', 'reference'])"),
    folders: z.array(z.number()).optional().describe('Folder IDs to place the bookmark in'),
  }),
  handler: async (args: {
    url: string;
    title?: string;
    description?: string;
    tags?: string[];
    folders?: number[];
  }) => {
    try {
      const body: Record<string, unknown> = { url: args.url };
      if (args.title !== undefined) body.title = args.title;
      if (args.description !== undefined) body.description = args.description;
      if (args.tags) body.tags = args.tags;
      if (args.folders) body.folders = args.folders;

      const result = await fetchBookmarksAPI<BookmarkItemResponse>('/bookmark', {
        method: 'POST',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmark created successfully (ID: ${result.item.id}).\n\n${formatBookmarkDetail(result.item)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating bookmark: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const updateBookmarkTool = {
  name: 'update_bookmark',
  description: 'Update an existing bookmark. Only provided fields are changed.',
  inputSchema: z.object({
    id: z.number().describe('The bookmark ID to update'),
    url: z.string().optional().describe('New URL'),
    title: z.string().optional().describe('New title'),
    description: z.string().optional().describe('New description'),
    tags: z.array(z.string()).optional().describe('New tags (replaces existing tags)'),
    folders: z.array(z.number()).optional().describe('New folder IDs (replaces existing folders)'),
  }),
  handler: async (args: {
    id: number;
    url?: string;
    title?: string;
    description?: string;
    tags?: string[];
    folders?: number[];
  }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.url !== undefined) body.url = args.url;
      if (args.title !== undefined) body.title = args.title;
      if (args.description !== undefined) body.description = args.description;
      if (args.tags !== undefined) body.tags = args.tags;
      if (args.folders !== undefined) body.folders = args.folders;

      const result = await fetchBookmarksAPI<BookmarkItemResponse>(`/bookmark/${args.id}`, {
        method: 'PUT',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmark ${args.id} updated successfully.\n\n${formatBookmarkDetail(result.item)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating bookmark ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const deleteBookmarkTool = {
  name: 'delete_bookmark',
  description: 'Delete a bookmark by its ID. This action is irreversible.',
  inputSchema: z.object({
    id: z.number().describe('The bookmark ID to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchBookmarksAPI<StatusResponse>(`/bookmark/${args.id}`, { method: 'DELETE' });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmark ${args.id} deleted successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting bookmark ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Folder Tools ────────────────────────────────────────────────────────────

export const listBookmarkFoldersTool = {
  name: 'list_bookmark_folders',
  description: 'List the bookmark folder hierarchy. Returns a tree structure of all folders.',
  inputSchema: z.object({
    root: z
      .number()
      .optional()
      .describe('Root folder ID to start from (-1 for top level, default)'),
    layers: z.number().optional().describe('Maximum depth of folder levels to return'),
  }),
  handler: async (args: { root?: number; layers?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.root !== undefined) queryParams['root'] = String(args.root);
      if (args.layers !== undefined) queryParams['layers'] = String(args.layers);

      const result = await fetchBookmarksAPI<FolderListResponse>('/folder', { queryParams });

      if (result.data.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No bookmark folders found.' }],
        };
      }

      const formatted = result.data.map((f) => formatFolderTree(f)).join('\n');

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmark folders:\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing bookmark folders: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const getBookmarkFolderContentsTool = {
  name: 'get_bookmark_folder_contents',
  description: 'Get the contents of a bookmark folder (bookmarks and subfolders).',
  inputSchema: z.object({
    id: z.number().describe('The folder ID (-1 for root)'),
    layers: z
      .number()
      .optional()
      .describe('Depth of subfolder levels to include (default 0 = direct children only)'),
  }),
  handler: async (args: { id: number; layers?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.layers !== undefined) queryParams['layers'] = String(args.layers);

      const result = await fetchBookmarksAPI<FolderChildrenResponse>(
        `/folder/${args.id}/children`,
        { queryParams }
      );

      if (result.data.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'Folder is empty.' }],
        };
      }

      const lines: string[] = [];
      for (const item of result.data) {
        if ('url' in item) {
          const b = item as Bookmark;
          lines.push(formatBookmarkSummary(b));
        } else {
          const f = item as BookmarkFolder;
          lines.push(formatFolderTree(f));
        }
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Folder contents (${result.data.length} items):\n\n${lines.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting folder contents: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const createBookmarkFolderTool = {
  name: 'create_bookmark_folder',
  description: 'Create a new bookmark folder.',
  inputSchema: z.object({
    title: z.string().describe('Folder name'),
    parent_folder: z.number().optional().describe('Parent folder ID (-1 for root, default)'),
  }),
  handler: async (args: { title: string; parent_folder?: number }) => {
    try {
      const body: Record<string, unknown> = { title: args.title };
      if (args.parent_folder !== undefined) body.parent_folder = args.parent_folder;

      const result = await fetchBookmarksAPI<FolderItemResponse>('/folder', {
        method: 'POST',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Folder "${args.title}" created successfully (ID: ${result.item.id}).`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating folder: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const updateBookmarkFolderTool = {
  name: 'update_bookmark_folder',
  description: 'Update a bookmark folder (rename or move to a different parent).',
  inputSchema: z.object({
    id: z.number().describe('The folder ID to update'),
    title: z.string().optional().describe('New folder name'),
    parent_folder: z.number().optional().describe('New parent folder ID (-1 for root)'),
  }),
  handler: async (args: { id: number; title?: string; parent_folder?: number }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.title !== undefined) body.title = args.title;
      if (args.parent_folder !== undefined) body.parent_folder = args.parent_folder;

      await fetchBookmarksAPI<FolderItemResponse>(`/folder/${args.id}`, { method: 'PUT', body });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Folder ${args.id} updated successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating folder ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const deleteBookmarkFolderTool = {
  name: 'delete_bookmark_folder',
  description: 'Delete a bookmark folder and all its contents. This action is irreversible.',
  inputSchema: z.object({
    id: z.number().describe('The folder ID to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchBookmarksAPI<StatusResponse>(`/folder/${args.id}`, { method: 'DELETE' });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Folder ${args.id} deleted successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting folder ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Tag Tools ───────────────────────────────────────────────────────────────

export const listBookmarkTagsTool = {
  name: 'list_bookmark_tags',
  description: 'List all tags used across bookmarks.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchBookmarksAPI<TagListResponse>('/tag');

      if (result.data.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No bookmark tags found.' }],
        };
      }

      const formatted = result.data
        .sort()
        .map((t) => `- ${t}`)
        .join('\n');

      return {
        content: [
          {
            type: 'text' as const,
            text: `Bookmark tags (${result.data.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing tags: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const renameBookmarkTagTool = {
  name: 'rename_bookmark_tag',
  description: 'Rename a bookmark tag. All bookmarks with the old tag will be updated.',
  inputSchema: z.object({
    old_name: z.string().describe('Current tag name'),
    new_name: z.string().describe('New tag name'),
  }),
  handler: async (args: { old_name: string; new_name: string }) => {
    try {
      await fetchBookmarksAPI<StatusResponse>(`/tag/${encodeURIComponent(args.old_name)}`, {
        method: 'PUT',
        body: { name: args.new_name },
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Tag "${args.old_name}" renamed to "${args.new_name}" successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error renaming tag "${args.old_name}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const deleteBookmarkTagTool = {
  name: 'delete_bookmark_tag',
  description: 'Delete a bookmark tag. The tag will be removed from all bookmarks.',
  inputSchema: z.object({
    name: z.string().describe('Tag name to delete'),
  }),
  handler: async (args: { name: string }) => {
    try {
      await fetchBookmarksAPI<StatusResponse>(`/tag/${encodeURIComponent(args.name)}`, {
        method: 'DELETE',
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Tag "${args.name}" deleted successfully.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting tag "${args.name}": ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Export ───────────────────────────────────────────────────────────────────

export const bookmarksTools = [
  listBookmarksTool,
  getBookmarkTool,
  createBookmarkTool,
  updateBookmarkTool,
  deleteBookmarkTool,
  listBookmarkFoldersTool,
  getBookmarkFolderContentsTool,
  createBookmarkFolderTool,
  updateBookmarkFolderTool,
  deleteBookmarkFolderTool,
  listBookmarkTagsTool,
  renameBookmarkTagTool,
  deleteBookmarkTagTool,
];
