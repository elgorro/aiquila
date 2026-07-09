// SPDX-License-Identifier: MIT

import { z } from 'zod';

/**
 * Shared type definitions for MCP tools
 */

/**
 * MCP tool behaviour hints, surfaced to clients via `tools/list`.
 *
 * All four are required here (the MCP spec makes them optional) so that
 * `tsc` rejects any tool that forgets to classify itself. Claude uses
 * `readOnlyHint` and `destructiveHint` to decide whether a call needs
 * per-invocation confirmation.
 */
export interface ToolAnnotations {
  /** Tool only reads data; it never mutates Nextcloud state. */
  readOnlyHint: boolean;
  /** Tool overwrites or removes existing data (as opposed to purely additive). */
  destructiveHint: boolean;
  /** Repeating the call with identical arguments has no further effect. */
  idempotentHint: boolean;
  /** Tool reaches an unbounded external world rather than just this Nextcloud. */
  openWorldHint: boolean;
}

/**
 * The shape every entry in a `*Tools` array must satisfy. Enforced structurally
 * where the arrays are placed into `TOOL_REGISTRY` in `../tool-registry.ts`.
 */
export interface Tool {
  name: string;
  /** Human-readable display name, e.g. 'Get Out-of-Office Status'. */
  title: string;
  description: string;
  annotations: ToolAnnotations;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  inputSchema: any;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  handler: (...args: any[]) => any;
}

/**
 * Zod schemas for common parameters
 */

export const PathSchema = z.object({
  path: z.string().describe("The path in Nextcloud (e.g., '/Documents/file.txt')"),
});

export const FilePathSchema = z.object({
  path: z.string().describe('The file path in Nextcloud'),
});

export const FolderPathSchema = z.object({
  path: z.string().describe('The folder path to create in Nextcloud'),
});

const MAX_FILE_SIZE = parseInt(process.env.MCP_MAX_FILE_SIZE || '', 10) || 1024 * 1024 * 1024;

export const FileContentSchema = z.object({
  path: z.string().describe('The file path in Nextcloud'),
  content: z
    .string()
    .max(MAX_FILE_SIZE, `File content exceeds maximum size of ${MAX_FILE_SIZE} bytes`)
    .describe('The content to write to the file'),
});

/**
 * Environment variable configuration
 */
export interface NextcloudConfig {
  url: string;
  user: string;
  password: string;
}

/**
 * Get Nextcloud configuration from environment variables
 */
export function getNextcloudConfig(): NextcloudConfig {
  const url = process.env.NEXTCLOUD_URL;
  const user = process.env.NEXTCLOUD_USER;
  const password = process.env.NEXTCLOUD_PASSWORD;

  if (!url || !user || !password) {
    throw new Error(
      'NEXTCLOUD_URL, NEXTCLOUD_USER, and NEXTCLOUD_PASSWORD environment variables must be set'
    );
  }

  // Strip trailing slashes to prevent double-slash URLs in CalDAV paths
  let normalizedUrl = url.replace(/\/+$/, '');
  // Prepend https:// if no scheme is present (bare domain)
  if (!normalizedUrl.startsWith('http://') && !normalizedUrl.startsWith('https://')) {
    normalizedUrl = 'https://' + normalizedUrl;
  }
  return { url: normalizedUrl, user, password };
}
