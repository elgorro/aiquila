// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  fetchTextAPI,
  type TextWorkspaceResponse,
  type TextWorkspaceFile,
  type TextDirectEditResponse,
} from '../../client/text.js';
import { ApiError } from '../../client/aiquila.js';
import { getWebDAVClient } from '../../client/webdav.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Text App Tools — workspaces (per-folder Readme.md) and direct-edit URLs.
 *
 * Uses the Text OCS API (/ocs/v2.php/apps/text/workspace) plus WebDAV for content I/O.
 * Collaborative editing sessions are intentionally out of scope: the live editor runs
 * in the user's browser via the direct-edit URL.
 */

const textStatusMap: Record<number, string> = {
  400: 'Bad request — check the folder path.',
  403: 'Access denied to this folder.',
  404: 'No workspace exists for this folder.',
};

const FolderPathArg = z
  .string()
  .describe("Folder path relative to the user's root (e.g. '/Projects/Acme')");

function normaliseFolder(path: string): string {
  if (!path || path === '/') return '/';
  return path.replace(/\/+$/, '');
}

function joinPath(folder: string, name: string): string {
  const f = normaliseFolder(folder);
  return f === '/' ? `/${name}` : `${f}/${name}`;
}

function formatWorkspace(file: TextWorkspaceFile): string {
  return `[${file.id}] ${file.name} (${file.mimetype}) at ${file.path}`;
}

async function resolveWorkspaceFile(folder: string): Promise<TextWorkspaceFile | null> {
  try {
    const data = await fetchTextAPI<TextWorkspaceResponse>('/workspace', {
      queryParams: { path: folder },
    });
    return data?.file ?? null;
  } catch (error) {
    if (error instanceof ApiError && error.statusCode === 404) {
      return null;
    }
    throw error;
  }
}

export const getTextWorkspaceTool = {
  name: 'get_text_workspace',
  description:
    "Get metadata of the Text workspace file (Readme.md) for a folder. Returns the file's id, name, mimetype and path, or reports that no workspace exists yet.",
  inputSchema: z.object({ path: FolderPathArg }),
  handler: async (args: { path: string }) => {
    try {
      const file = await resolveWorkspaceFile(args.path);
      if (!file) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No workspace file in ${normaliseFolder(args.path) || '/'}.`,
            },
          ],
        };
      }
      return {
        content: [{ type: 'text' as const, text: `Workspace: ${formatWorkspace(file)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error getting workspace', textStatusMap);
    }
  },
};

export const readTextWorkspaceTool = {
  name: 'read_text_workspace',
  description:
    "Read the content of a folder's Text workspace file (Readme.md). Returns markdown text, or a 'no workspace' message if none exists.",
  inputSchema: z.object({ path: FolderPathArg }),
  handler: async (args: { path: string }) => {
    try {
      const file = await resolveWorkspaceFile(args.path);
      if (!file) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No workspace file in ${normaliseFolder(args.path) || '/'}.`,
            },
          ],
        };
      }
      const client = getWebDAVClient();
      const content = (await client.getFileContents(file.path, { format: 'text' })) as string;
      return { content: [{ type: 'text' as const, text: content }] };
    } catch (error) {
      return handleAppError(error, 'Error reading workspace', textStatusMap);
    }
  },
};

export const writeTextWorkspaceTool = {
  name: 'write_text_workspace',
  description:
    "Create or overwrite a folder's Text workspace file. If a workspace already exists, its existing filename is reused; otherwise Readme.md is created at the folder root.",
  inputSchema: z.object({
    path: FolderPathArg,
    content: z.string().describe('Markdown content to write'),
  }),
  handler: async (args: { path: string; content: string }) => {
    try {
      const existing = await resolveWorkspaceFile(args.path);
      const targetPath = existing?.path ?? joinPath(args.path, 'Readme.md');
      const client = getWebDAVClient();
      await client.putFileContents(targetPath, args.content, { overwrite: true });
      return {
        content: [
          {
            type: 'text' as const,
            text: existing
              ? `Workspace updated at ${targetPath}.`
              : `Workspace created at ${targetPath}.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error writing workspace', textStatusMap);
    }
  },
};

export const deleteTextWorkspaceTool = {
  name: 'delete_text_workspace',
  description:
    'Delete the Text workspace file (Readme.md) for a folder. The folder itself is kept. No-op if the folder has no workspace.',
  inputSchema: z.object({ path: FolderPathArg }),
  handler: async (args: { path: string }) => {
    try {
      const existing = await resolveWorkspaceFile(args.path);
      if (!existing) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No workspace file in ${normaliseFolder(args.path) || '/'}.`,
            },
          ],
        };
      }
      const client = getWebDAVClient();
      await client.deleteFile(existing.path);
      return {
        content: [{ type: 'text' as const, text: `Workspace deleted at ${existing.path}.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting workspace', textStatusMap);
    }
  },
};

export const getTextWorkspaceEditUrlTool = {
  name: 'get_text_workspace_edit_url',
  description:
    "Get a one-shot direct-edit URL for a folder's Text workspace. Opens the live collaborative editor in a browser. The Readme.md is created automatically if it does not exist yet. Hand the URL to a human collaborator — the MCP server does not participate in the editing session.",
  inputSchema: z.object({ path: FolderPathArg }),
  handler: async (args: { path: string }) => {
    try {
      const data = await fetchTextAPI<TextDirectEditResponse>('/workspace/direct', {
        method: 'POST',
        body: { path: args.path },
      });
      return { content: [{ type: 'text' as const, text: data.url }] };
    } catch (error) {
      return handleAppError(error, 'Error getting workspace edit URL', textStatusMap);
    }
  },
};

export const textTools = [
  getTextWorkspaceTool,
  readTextWorkspaceTool,
  writeTextWorkspaceTool,
  deleteTextWorkspaceTool,
  getTextWorkspaceEditUrlTool,
];
