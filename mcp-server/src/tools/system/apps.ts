import { z } from "zod";
import { fetchOCS } from "../../client/ocs.js";

/**
 * Nextcloud App Management Tools
 * Provides app management via OCS Provisioning API
 */

/**
 * List all installed apps with their status
 */
export const listAppsTool = {
  name: 'list_apps',
  description: 'List all installed Nextcloud apps with their enabled/disabled status',
  inputSchema: z.object({
    filter: z.enum(['all', 'enabled', 'disabled']).optional()
      .describe('Filter apps by status: "all", "enabled", or "disabled" (default: all)'),
  }),
  handler: async (args: { filter?: string }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.filter === 'enabled') {
        queryParams.filter = 'enabled';
      } else if (args.filter === 'disabled') {
        queryParams.filter = 'disabled';
      }

      const result = await fetchOCS<{ apps: string[] }>(
        "/ocs/v2.php/cloud/apps",
        { queryParams }
      );

      const apps = result.ocs.data.apps;
      const filterLabel = args.filter && args.filter !== 'all' ? ` (${args.filter})` : '';

      return {
        content: [{
          type: 'text' as const,
          text: `Nextcloud apps${filterLabel}:\n${apps.join('\n')}`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error listing apps: ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Get detailed information about a specific app
 */
export const getAppInfoTool = {
  name: 'get_app_info',
  description: 'Get detailed information about a specific Nextcloud app',
  inputSchema: z.object({
    appId: z.string().describe('The app ID (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    try {
      const result = await fetchOCS<Record<string, unknown>>(
        `/ocs/v2.php/cloud/apps/${encodeURIComponent(args.appId)}`
      );

      return {
        content: [{
          type: 'text' as const,
          text: JSON.stringify(result.ocs.data, null, 2),
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error getting app info for "${args.appId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Enable a disabled app
 */
export const enableAppTool = {
  name: 'enable_app',
  description: 'Enable a disabled Nextcloud app',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to enable (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    try {
      await fetchOCS(
        `/ocs/v2.php/cloud/apps/${encodeURIComponent(args.appId)}`,
        { method: 'POST' }
      );

      return {
        content: [{
          type: 'text' as const,
          text: `App "${args.appId}" has been enabled successfully.`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error enabling app "${args.appId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Disable an app
 */
export const disableAppTool = {
  name: 'disable_app',
  description: 'Disable an enabled Nextcloud app (preserves data but removes functionality)',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to disable (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    try {
      await fetchOCS(
        `/ocs/v2.php/cloud/apps/${encodeURIComponent(args.appId)}`,
        { method: 'DELETE' }
      );

      return {
        content: [{
          type: 'text' as const,
          text: `App "${args.appId}" has been disabled successfully. Data is preserved and the app can be re-enabled.`,
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error disabling app "${args.appId}": ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Export all App Management tools
 */
export const appsTools = [
  listAppsTool,
  getAppInfoTool,
  enableAppTool,
  disableAppTool,
];
