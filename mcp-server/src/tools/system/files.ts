// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { getWebDAVClient } from '../../client/webdav.js';
import { ApiError, fetchAiquilaAPI } from '../../client/aiquila.js';
import {
  fileContent,
  fileMimeType,
  fileUri,
  isBinaryType,
  isEmbedded,
  maxEmbeddedFileSize,
  oversizedContent,
  TEXT_PROBE_LENGTH,
} from '../file-content.js';
import { PathSchema, FilePathSchema, FileContentSchema, FolderPathSchema } from '../types.js';
import { bool, int, str, structuredResult } from '../structured.js';

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
  description:
    'Read a file from Nextcloud. A text file is returned as text and an image as image content. Any other file (PDF, Word, Excel, PowerPoint, OpenDocument, archive, audio) is returned whole as an embedded resource holding its bytes.',
  inputSchema: FilePathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    // Ask first: a binary file over the limit is refused without being
    // downloaded. The media type Nextcloud reports tells whether it is one;
    // where it does not, the first bytes do, fetched alone.
    const stat = await client.stat(args.path).catch(() => undefined);
    const name = stat?.basename || args.path.split('/').filter(Boolean).pop() || args.path;
    if (stat && stat.size > maxEmbeddedFileSize()) {
      const declared = { name, bytes: new Uint8Array(), mimeType: stat.mime };
      const known = fileMimeType(declared);
      const start =
        isBinaryType(known) || known.startsWith('image/')
          ? declared.bytes
          : await client.getFileStart(args.path, TEXT_PROBE_LENGTH);
      const probe = { ...declared, bytes: start };
      if (isEmbedded(probe)) {
        const refused = oversizedContent(`File: ${name}`, fileMimeType(probe), stat.size);
        if (refused) return { content: refused };
      }
    }
    const bytes = await client.getFileContents(args.path, { format: 'binary' });
    return {
      content: fileContent(
        { name, bytes, mimeType: stat?.mime, uri: fileUri(args.path) },
        { header: false }
      ),
    };
  },
};

/**
 * The bytes a base64 string stands for, or undefined when it is not base64.
 * RFC 4648 §3.3: encoded data with characters outside the alphabet (§4) MUST
 * be rejected, line feeds included (§3.1); §3.2: padding MUST be present.
 * Buffer.from() skips what it cannot decode, so the check is made here, and
 * the round trip rejects pad bits that are not zero (§3.5).
 */
export function decodeBase64(value: string): Uint8Array<ArrayBuffer> | undefined {
  if (value.length % 4 !== 0 || !/^[A-Za-z0-9+/]*={0,2}$/.test(value)) return undefined;
  const bytes = Buffer.from(value, 'base64');
  if (bytes.toString('base64') !== value) return undefined;
  return new Uint8Array(bytes);
}

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
    'Create or update a file in Nextcloud. Give text as it is; for a binary file (PDF, image, office document) give its bytes in base64 and set encoding to "base64". File size is limited (default 1 GB, configurable via MCP_MAX_FILE_SIZE). The limit counts the characters of content, so a base64 file can be about three quarters of it.',
  inputSchema: FileContentSchema,
  handler: async (args: { path: string; content: string; encoding?: 'text' | 'base64' }) => {
    const client = getWebDAVClient();
    if (args.encoding !== 'base64') {
      await client.putFileContents(args.path, args.content, { overwrite: true });
      return {
        content: [{ type: 'text' as const, text: `File written successfully to ${args.path}` }],
      };
    }

    const bytes = decodeBase64(args.content);
    if (!bytes) {
      return {
        content: [
          {
            type: 'text' as const,
            text: 'Error writing file: content is not valid base64. Use the standard alphabet (A-Z, a-z, 0-9, +, /) with = padding and no line breaks, or omit encoding to write text.',
          },
        ],
        isError: true,
      };
    }
    await client.putFileContents(args.path, bytes, { overwrite: true });
    return {
      content: [
        {
          type: 'text' as const,
          text: `File written successfully to ${args.path} (${bytes.byteLength} bytes, decoded from base64)`,
        },
      ],
    };
  },
};

const CreatedFolderSchema = z.object({ path: z.string().describe('Path of the created folder') });

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
  outputSchema: CreatedFolderSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    await client.createDirectory(args.path);
    return structuredResult(
      CreatedFolderSchema,
      { path: args.path },
      `Folder created successfully at ${args.path}`
    );
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
 * A file or folder as the AIquila app's /files/info returns it (`FileNode` in
 * nextcloud-app/openapi.json).
 */
const FileNodeSchema = z.object({
  path: z
    .string()
    .describe(
      'As Nextcloud reports it (internal, e.g. /alice/files/a.pdf); else the requested path'
    ),
  id: z.number().int().nullable().describe('Nextcloud file id'),
  name: z.string().nullable(),
  type: z.enum(['file', 'folder']).nullable(),
  size: z.number().int().nullable().describe('Size in bytes'),
  mimeType: z.string().nullable(),
  mtime: z.number().int().nullable().describe('Last modified, Unix seconds'),
  etag: z.string().nullable(),
  permissions: z.number().int().nullable().describe('Nextcloud permission bitmask'),
  isEncrypted: z.boolean().nullable(),
});

function fileNodeData(info: Record<string, unknown>, path: string): z.input<typeof FileNodeSchema> {
  return {
    path: str(info.path) ?? path,
    id: int(info.id),
    name: str(info.name),
    type: info.type === 'file' || info.type === 'folder' ? info.type : null,
    size: int(info.size),
    mimeType: str(info.mimeType),
    mtime: int(info.mtime),
    etag: str(info.etag),
    permissions: int(info.permissions),
    isEncrypted: bool(info.isEncrypted),
  };
}

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
  outputSchema: FileNodeSchema,
  handler: async (args: { path: string }) => {
    try {
      const info = await fetchAiquilaAPI<Record<string, unknown>>('/files/info', {
        queryParams: { path: args.path },
      });
      return structuredResult(
        FileNodeSchema,
        fileNodeData(info, args.path),
        JSON.stringify(info, null, 2)
      );
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

/** A file's content: `text` when the AIquila app decoded it, else its bytes. */
type LoadedFile = { name: string; mimeType: string; size: number } & (
  { text: string; bytes?: undefined } | { text?: undefined; bytes: Uint8Array }
);

/**
 * Fetch a file's content through the AIquila app, or over WebDAV when the app
 * answers 404: it is not installed on this Nextcloud, or the file is one it
 * does not see. WebDAV needs no app, and reports a missing file itself.
 */
async function loadFile(path: string): Promise<LoadedFile> {
  try {
    const result = await fetchAiquilaAPI<{
      name: string;
      mimeType: string;
      size: number;
      encoding: string;
      content: string;
    }>('/files/content', { queryParams: { path } });
    const { name, mimeType, size } = result;
    return result.encoding === 'text'
      ? { name, mimeType, size, text: result.content }
      : { name, mimeType, size, bytes: Buffer.from(result.content, 'base64') };
  } catch (error) {
    if (!(error instanceof ApiError) || error.statusCode !== 404) throw error;
  }

  const client = getWebDAVClient();
  const stat = await client.stat(path);
  if (stat.type === 'directory') throw new Error(`'${path}' is a folder, not a file`);
  // The app refuses larger files with 413; keep one limit whichever way the file is read.
  const limit = maxEmbeddedFileSize();
  if (stat.size > limit) throw new Error(`File too large: ${stat.size} bytes (max ${limit})`);
  const bytes = await client.getFileContents(path, { format: 'binary' });
  return {
    name: stat.basename,
    mimeType: stat.mime || 'application/octet-stream',
    size: bytes.byteLength,
    bytes,
  };
}

/**
 * Read file content: text, an image block, or the file itself as an embedded resource
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
    'Read file content from Nextcloud with mime type info. Returns text for text files and MCP image content for images (Claude vision). Any other file (PDF, Word, Excel, PowerPoint, OpenDocument, archive, audio) is returned whole as an embedded resource holding its bytes.',
  inputSchema: z.object({
    path: z.string().describe('The file path in Nextcloud'),
  }),
  handler: async (args: { path: string }) => {
    try {
      const file = await loadFile(args.path);

      if (file.text !== undefined) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `File: ${file.name} (${file.mimeType}, ${file.size} bytes)\n\n${file.text}`,
            },
          ],
        };
      }

      return {
        content: fileContent(
          { name: file.name, bytes: file.bytes, mimeType: file.mimeType, uri: fileUri(args.path) },
          { header: true }
        ),
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
      const results = await Promise.all(imagePaths.map((p) => loadFile(p)));

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
          data: Buffer.from(r.bytes ?? Buffer.from(r.text, 'utf8')).toString('base64'),
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
