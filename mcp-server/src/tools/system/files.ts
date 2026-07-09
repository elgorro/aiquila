// SPDX-License-Identifier: MIT

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
  title: 'List Files',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Read File',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Write File',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Create or update a file in Nextcloud. File size is limited (default 1 GB, configurable via MCP_MAX_FILE_SIZE).',
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
  title: 'Create Folder',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Delete File or Folder',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Get File Info',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Search Files',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
  title: 'Get File Content',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
 * Analyze one or more images stored in Nextcloud using Claude vision.
 *
 * Fetches images via the AIquila API and returns them as MCP image
 * content blocks alongside the user's prompt so Claude can answer questions
 * about them (OCR, visual Q&A, document analysis, comparison, etc.).
 */
export const analyzeImageTool = {
  name: 'analyze_image',
  title: 'Analyze Image',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    'Analyze one or more images stored in Nextcloud using Claude vision. Ask questions about image content, extract text (OCR), describe visuals, compare images, or analyse documents. Supports up to 20 images for multi-image comparison.',
  inputSchema: z.object({
    path: z
      .string()
      .optional()
      .describe(
        "Single image file path in Nextcloud (e.g., '/Photos/receipt.jpg'). Use 'paths' for multiple images."
      ),
    paths: z
      .array(z.string())
      .max(20)
      .optional()
      .describe(
        "Multiple image file paths for comparison (max 20). E.g., ['/Photos/before.jpg', '/Photos/after.jpg']"
      ),
    prompt: z
      .string()
      .describe(
        'What to ask about the image(s) (e.g., "What text is in this image?", "Compare these images")'
      ),
  }),
  handler: async (args: { path?: string; paths?: string[]; prompt: string }) => {
    const imagePaths = args.paths ?? (args.path ? [args.path] : []);

    if (imagePaths.length === 0) {
      return {
        content: [
          {
            type: 'text' as const,
            text: "Error: No image path provided. Specify either 'path' (single image) or 'paths' (multiple images).",
          },
        ],
        isError: true,
      };
    }

    try {
      // Fetch all images in parallel
      const results = await Promise.all(
        imagePaths.map((p) =>
          fetchAiquilaAPI<{
            name: string;
            mimeType: string;
            size: number;
            encoding: string;
            content: string;
          }>('/files/content', { queryParams: { path: p } })
        )
      );

      // Validate all files are images
      const nonImages = results
        .map((r, i) => ({ ...r, path: imagePaths[i] }))
        .filter((r) => !r.mimeType.startsWith('image/'));

      if (nonImages.length > 0) {
        const list = nonImages.map((r) => `${r.path} (${r.mimeType})`).join(', ');
        return {
          content: [
            {
              type: 'text' as const,
              text: `Error: Non-image file(s): ${list}. Use get_file_content for non-image files.`,
            },
          ],
          isError: true,
        };
      }

      // Build file info header
      const fileInfo = results.map((r) => `${r.name} (${r.mimeType}, ${r.size} bytes)`).join(', ');

      // Return prompt as text + all images for Claude vision analysis
      const content: Array<
        { type: 'text'; text: string } | { type: 'image'; data: string; mimeType: string }
      > = [
        {
          type: 'text' as const,
          text:
            results.length === 1
              ? `File: ${fileInfo}\n\n${args.prompt}`
              : `Analyzing ${results.length} images: ${fileInfo}\n\n${args.prompt}`,
        },
        ...results.map((r) => ({
          type: 'image' as const,
          data: r.content,
          mimeType: r.mimeType,
        })),
      ];

      return { content };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error fetching image(s): ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Move or rename a file or folder in Nextcloud
 */
export const moveFileTool = {
  name: 'move_file',
  title: 'Move File',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Move or rename a file or folder in Nextcloud',
  inputSchema: z.object({
    source: z.string().describe('The source path of the file or folder to move'),
    destination: z.string().describe('The destination path'),
    overwrite: z
      .boolean()
      .optional()
      .default(false)
      .describe('Whether to overwrite if the destination already exists'),
  }),
  handler: async (args: { source: string; destination: string; overwrite: boolean }) => {
    try {
      const client = getWebDAVClient();
      await client.moveFile(args.source, args.destination, { overwrite: args.overwrite });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Moved successfully: ${args.source} → ${args.destination}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error moving file: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Copy a file or folder in Nextcloud
 */
export const copyFileTool = {
  name: 'copy_file',
  title: 'Copy File',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Copy a file or folder in Nextcloud',
  inputSchema: z.object({
    source: z.string().describe('The source path of the file or folder to copy'),
    destination: z.string().describe('The destination path for the copy'),
    overwrite: z
      .boolean()
      .optional()
      .default(false)
      .describe('Whether to overwrite if the destination already exists'),
  }),
  handler: async (args: { source: string; destination: string; overwrite: boolean }) => {
    try {
      const client = getWebDAVClient();
      await client.copyFile(args.source, args.destination, { overwrite: args.overwrite });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Copied successfully: ${args.source} → ${args.destination}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error copying file: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Perform multiple file operations (move, copy, delete) in a single call
 */
export const bulkFileOperationsTool = {
  name: 'bulk_file_operations',
  title: 'Bulk File Operations',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Execute multiple file operations (move, copy, delete) sequentially in a single call. Returns per-item results.',
  inputSchema: z.object({
    operations: z
      .array(
        z.object({
          action: z.enum(['move', 'copy', 'delete']).describe('The operation to perform'),
          source: z.string().describe('Source file/folder path'),
          destination: z.string().optional().describe('Destination path (required for move/copy)'),
        })
      )
      .min(1)
      .max(100)
      .describe('Array of file operations to execute sequentially'),
  }),
  handler: async (args: {
    operations: Array<{ action: 'move' | 'copy' | 'delete'; source: string; destination?: string }>;
  }) => {
    const client = getWebDAVClient();
    const results: string[] = [];
    let succeeded = 0;

    for (const op of args.operations) {
      try {
        if ((op.action === 'move' || op.action === 'copy') && !op.destination) {
          results.push(`✗ ${op.action} ${op.source} — missing destination`);
          continue;
        }

        switch (op.action) {
          case 'move':
            await client.moveFile(op.source, op.destination!);
            results.push(`✓ move ${op.source} → ${op.destination}`);
            succeeded++;
            break;
          case 'copy':
            await client.copyFile(op.source, op.destination!);
            results.push(`✓ copy ${op.source} → ${op.destination}`);
            succeeded++;
            break;
          case 'delete':
            await client.deleteFile(op.source);
            results.push(`✓ delete ${op.source}`);
            succeeded++;
            break;
        }
      } catch (error) {
        const msg = error instanceof Error ? error.message : String(error);
        results.push(`✗ ${op.action} ${op.source} — ${msg}`);
      }
    }

    return {
      content: [
        {
          type: 'text' as const,
          text: `Bulk operations: ${succeeded}/${args.operations.length} succeeded\n${results.join('\n')}`,
        },
      ],
      ...(succeeded < args.operations.length ? { isError: true } : {}),
    };
  },
};

/**
 * Create a zip archive from one or more files/folders
 */
export const createArchiveTool = {
  name: 'create_archive',
  title: 'Create Archive',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Create a zip archive in Nextcloud from one or more files and/or folders. Runs server-side; not bound by MCP file-size limits.',
  inputSchema: z.object({
    sources: z
      .array(z.string())
      .min(1)
      .describe("Paths of files/folders to include (e.g., ['/Documents/a.txt', '/Photos'])"),
    destination: z
      .string()
      .describe("Path for the resulting .zip file (e.g., '/Documents/backup.zip')"),
    overwrite: z
      .boolean()
      .optional()
      .default(false)
      .describe('Whether to overwrite the destination if it already exists'),
  }),
  handler: async (args: { sources: string[]; destination: string; overwrite: boolean }) => {
    try {
      const result = await fetchAiquilaAPI<Record<string, unknown>>('/files/zip', {
        method: 'POST',
        body: {
          sources: args.sources,
          destination: args.destination,
          overwrite: args.overwrite,
        },
      });
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating archive: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Extract a zip archive into a destination folder
 */
export const extractArchiveTool = {
  name: 'extract_archive',
  title: 'Extract Archive',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Extract a zip archive in Nextcloud into a destination folder. Runs server-side.',
  inputSchema: z.object({
    archive: z.string().describe("Path to the .zip file (e.g., '/Documents/backup.zip')"),
    destination: z.string().describe("Folder to extract into (e.g., '/Documents/restored')"),
    overwrite: z
      .boolean()
      .optional()
      .default(false)
      .describe('Whether to overwrite entries that already exist'),
  }),
  handler: async (args: { archive: string; destination: string; overwrite: boolean }) => {
    try {
      const result = await fetchAiquilaAPI<Record<string, unknown>>('/files/unzip', {
        method: 'POST',
        body: {
          archive: args.archive,
          destination: args.destination,
          overwrite: args.overwrite,
        },
      });
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error extracting archive: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List the contents of a zip archive without extracting
 */
export const listArchiveTool = {
  name: 'list_archive',
  title: 'List Archive Contents',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List the contents of a zip archive in Nextcloud without extracting it.',
  inputSchema: z.object({
    archive: z.string().describe("Path to the .zip file (e.g., '/Documents/backup.zip')"),
  }),
  handler: async (args: { archive: string }) => {
    try {
      const result = await fetchAiquilaAPI<Record<string, unknown>>('/files/zip/list', {
        queryParams: { archive: args.archive },
      });
      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing archive: ${error instanceof Error ? error.message : String(error)}`,
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
  moveFileTool,
  copyFileTool,
  getFileInfoTool,
  searchFilesTool,
  getFileContentTool,
  analyzeImageTool,
  bulkFileOperationsTool,
  createArchiveTool,
  extractArchiveTool,
  listArchiveTool,
];
