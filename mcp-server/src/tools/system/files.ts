import { z } from 'zod';
import { getWebDAVClient } from '../../client/webdav.js';
import { fetchAiquilaAPI } from '../../client/aiquila.js';
import { PathSchema, FilePathSchema, FileContentSchema, FolderPathSchema } from '../types.js';

/**
 * File System Tools for Nextcloud
 * Provides basic file operations via WebDAV
 */

/**
 * List files and folders in a Nextcloud directory
 */
export const listFilesTool = {
  name: 'list_files',
  description: 'List files and folders in a Nextcloud directory',
  inputSchema: z.object({
    path: z.string().default('/').describe("The directory path to list (default: '/')"),
  }),
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    const items = await client.getDirectoryContents(args.path);
    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(items, null, 2),
        },
      ],
    };
  },
};

/**
 * Read the contents of a file from Nextcloud
 */
export const readFileTool = {
  name: 'read_file',
  description: 'Read the contents of a file from Nextcloud',
  inputSchema: FilePathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    const content = await client.getFileContents(args.path, {
      format: 'text',
    });
    return {
      content: [
        {
          type: 'text',
          text: content as string,
        },
      ],
    };
  },
};

/**
 * Create or update a file in Nextcloud
 */
export const writeFileTool = {
  name: 'write_file',
  description: 'Create or update a file in Nextcloud',
  inputSchema: FileContentSchema,
  handler: async (args: { path: string; content: string }) => {
    const client = getWebDAVClient();
    await client.putFileContents(args.path, args.content, {
      overwrite: true,
    });
    return {
      content: [
        {
          type: 'text',
          text: `File written successfully to ${args.path}`,
        },
      ],
    };
  },
};

/**
 * Create a folder in Nextcloud
 */
export const createFolderTool = {
  name: 'create_folder',
  description: 'Create a folder in Nextcloud',
  inputSchema: FolderPathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    await client.createDirectory(args.path);
    return {
      content: [
        {
          type: 'text',
          text: `Folder created successfully at ${args.path}`,
        },
      ],
    };
  },
};

/**
 * Delete a file or folder from Nextcloud
 */
export const deleteTool = {
  name: 'delete',
  description: 'Delete a file or folder from Nextcloud',
  inputSchema: PathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    await client.deleteFile(args.path);
    return {
      content: [
        {
          type: 'text',
          text: `Deleted successfully: ${args.path}`,
        },
      ],
    };
  },
};

/**
 * Get detailed file metadata via AIquila REST API
 */
export const getFileInfoTool = {
  name: 'get_file_info',
  description:
    'Get detailed file metadata from Nextcloud (name, size, mime type, modified date, permissions, etc.)',
  inputSchema: z.object({
    path: z
      .string()
      .describe("The file or folder path in Nextcloud (e.g., '/Documents/report.pdf')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const info = await fetchAiquilaAPI<Record<string, unknown>>('/files/info', {
        queryParams: { path: args.path },
      });
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(info, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting file info: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Search for files by name pattern and/or mime type
 */
export const searchFilesTool = {
  name: 'search_files',
  description: 'Search for files in Nextcloud by name pattern and/or mime type',
  inputSchema: z.object({
    query: z.string().describe('Search query (file name pattern)'),
    mime: z
      .string()
      .optional()
      .describe("Filter by mime type prefix (e.g., 'image/', 'text/plain')"),
    path: z.string().optional().default('/').describe('Base directory to search in'),
  }),
  handler: async (args: { query: string; mime?: string; path?: string }) => {
    try {
      const queryParams: Record<string, string> = { query: args.query };
      if (args.mime) queryParams.mime = args.mime;
      if (args.path) queryParams.path = args.path;

      const results = await fetchAiquilaAPI<Record<string, unknown>>('/files/search', {
        queryParams,
      });
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(results, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error searching files: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Read file content with proper encoding (text or base64 for binary/images)
 */
export const getFileContentTool = {
  name: 'get_file_content',
  description:
    'Read file content from Nextcloud with mime type info. Returns text for text files, base64 for binary files (images, PDFs, etc.). Images are returned as MCP image content for Claude vision.',
  inputSchema: z.object({
    path: z.string().describe('The file path in Nextcloud'),
  }),
  handler: async (args: { path: string }) => {
    try {
      const result = await fetchAiquilaAPI<{
        name: string;
        mimeType: string;
        size: number;
        encoding: string;
        content: string;
      }>('/files/content', { queryParams: { path: args.path } });

      if (result.encoding === 'text') {
        return {
          content: [
            {
              type: 'text' as const,
              text: `File: ${result.name} (${result.mimeType}, ${result.size} bytes)\n\n${result.content}`,
            },
          ],
        };
      }

      // For images, return as MCP image content type for Claude vision
      if (result.mimeType.startsWith('image/')) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `File: ${result.name} (${result.mimeType}, ${result.size} bytes)`,
            },
            {
              type: 'image' as const,
              data: result.content,
              mimeType: result.mimeType,
            },
          ],
        };
      }

      // Other binary types: return base64 as text
      return {
        content: [
          {
            type: 'text' as const,
            text: `File: ${result.name} (${result.mimeType}, ${result.size} bytes)\nEncoding: base64\n\n${result.content}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error reading file: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all file system tools
 */
export const fileSystemTools = [
  listFilesTool,
  readFileTool,
  writeFileTool,
  createFolderTool,
  deleteTool,
  getFileInfoTool,
  searchFilesTool,
  getFileContentTool,
];
