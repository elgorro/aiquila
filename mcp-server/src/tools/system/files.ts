import { z } from "zod";
import { getWebDAVClient } from "../../client/webdav.js";
import { PathSchema, FilePathSchema, FileContentSchema, FolderPathSchema } from "../types.js";

/**
 * File System Tools for Nextcloud
 * Provides basic file operations via WebDAV
 */

/**
 * List files and folders in a Nextcloud directory
 */
export const listFilesTool = {
  name: "list_files",
  description: "List files and folders in a Nextcloud directory",
  inputSchema: z.object({
    path: z
      .string()
      .default("/")
      .describe("The directory path to list (default: '/')"),
  }),
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    const items = await client.getDirectoryContents(args.path);
    return {
      content: [
        {
          type: "text",
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
  name: "read_file",
  description: "Read the contents of a file from Nextcloud",
  inputSchema: FilePathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    const content = await client.getFileContents(args.path, {
      format: "text",
    });
    return {
      content: [
        {
          type: "text",
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
  name: "write_file",
  description: "Create or update a file in Nextcloud",
  inputSchema: FileContentSchema,
  handler: async (args: { path: string; content: string }) => {
    const client = getWebDAVClient();
    await client.putFileContents(args.path, args.content, {
      overwrite: true,
    });
    return {
      content: [
        {
          type: "text",
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
  name: "create_folder",
  description: "Create a folder in Nextcloud",
  inputSchema: FolderPathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    await client.createDirectory(args.path);
    return {
      content: [
        {
          type: "text",
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
  name: "delete",
  description: "Delete a file or folder from Nextcloud",
  inputSchema: PathSchema,
  handler: async (args: { path: string }) => {
    const client = getWebDAVClient();
    await client.deleteFile(args.path);
    return {
      content: [
        {
          type: "text",
          text: `Deleted successfully: ${args.path}`,
        },
      ],
    };
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
];
