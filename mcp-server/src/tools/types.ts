// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { currentIdentity, isIdentityRequired, SignInRequiredError } from '../nc-context.js';

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
 * The Nextcloud account a request acts as.
 */
export interface NextcloudConfig {
  url: string;
  /** The uid, for DAV paths such as /remote.php/dav/files/<user>. */
  user: string;
  /** The name to authenticate with; can differ from the uid. */
  loginName: string;
  password: string;
}

/** NEXTCLOUD_URL, normalized: no trailing slash, https:// if no scheme is given. */
export function nextcloudBaseUrl(): string {
  const url = process.env.NEXTCLOUD_URL;
  if (!url) throw new Error('NEXTCLOUD_URL environment variable must be set');
  // Strip trailing slashes to prevent double-slash URLs in CalDAV paths
  let normalizedUrl = url.replace(/\/+$/, '');
  // Prepend https:// if no scheme is present (bare domain)
  if (!normalizedUrl.startsWith('http://') && !normalizedUrl.startsWith('https://')) {
    normalizedUrl = 'https://' + normalizedUrl;
  }
  return normalizedUrl;
}

/**
 * The account from NEXTCLOUD_USER / NEXTCLOUD_PASSWORD.
 *
 * @deprecated under OAuth: there it serves only MCP_INTERNAL_TOKEN, and that
 * service identity is being replaced. stdio and unauthenticated HTTP keep it.
 */
function serviceAccountConfig(): NextcloudConfig {
  const user = process.env.NEXTCLOUD_USER;
  const password = process.env.NEXTCLOUD_PASSWORD;
  if (!process.env.NEXTCLOUD_URL || !user || !password) {
    throw new Error(
      'NEXTCLOUD_URL, NEXTCLOUD_USER, and NEXTCLOUD_PASSWORD environment variables must be set'
    );
  }
  return { url: nextcloudBaseUrl(), user, loginName: user, password };
}

/** True when NEXTCLOUD_USER or NEXTCLOUD_PASSWORD is set. */
export function serviceAccountConfigured(): boolean {
  return !!(process.env.NEXTCLOUD_USER || process.env.NEXTCLOUD_PASSWORD);
}

/**
 * The Nextcloud account of the request being served: the signed-in user's
 * app password under OAuth, the environment account otherwise. An OAuth
 * request never falls back to the environment account.
 */
export function getNextcloudConfig(): NextcloudConfig {
  const identity = currentIdentity();
  if (identity?.kind === 'user') {
    const { userId, loginName, appPassword } = identity.user;
    return { url: nextcloudBaseUrl(), user: userId, loginName, password: appPassword };
  }
  if (identity?.kind === 'anonymous') throw new SignInRequiredError();
  if (!identity && isIdentityRequired()) {
    throw new Error('Nextcloud was called outside a request identity context');
  }
  return serviceAccountConfig();
}

/** The base64 Basic credential for `config`, without the "Basic " prefix. */
export function basicAuth(config: NextcloudConfig): string {
  return Buffer.from(`${config.loginName}:${config.password}`).toString('base64');
}
