import { z } from "zod";

/**
 * Shared type definitions for MCP tools
 */

/**
 * Standard response format for tool operations
 */
export interface ToolResponse {
  success: boolean;
  message?: string;
  data?: unknown;
  error?: string;
}

/**
 * Zod schemas for common parameters
 */

export const PathSchema = z.object({
  path: z.string().describe("The path in Nextcloud (e.g., '/Documents/file.txt')"),
});

export const FilePathSchema = z.object({
  path: z.string().describe("The file path in Nextcloud"),
});

export const FolderPathSchema = z.object({
  path: z.string().describe("The folder path to create in Nextcloud"),
});

export const FileContentSchema = z.object({
  path: z.string().describe("The file path in Nextcloud"),
  content: z.string().describe("The content to write to the file"),
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
      "NEXTCLOUD_URL, NEXTCLOUD_USER, and NEXTCLOUD_PASSWORD environment variables must be set"
    );
  }

  return { url, user, password };
}
