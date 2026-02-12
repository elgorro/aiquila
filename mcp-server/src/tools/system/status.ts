import { z } from "zod";
import { fetchOCS, fetchStatus } from "../../client/ocs.js";
import { executeOCC, formatOccError } from "../../client/aiquila.js";

/**
 * Nextcloud System Status Tools
 * Provides system diagnostics and monitoring via OCS API
 */

/**
 * Get overall system status
 */
export const systemStatusTool = {
  name: 'system_status',
  description: 'Get Nextcloud system status including version, installation path, and configuration',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const [status, capabilities] = await Promise.all([
        fetchStatus(),
        fetchOCS<Record<string, unknown>>("/ocs/v2.php/cloud/capabilities"),
      ]);

      const result = {
        status,
        capabilities: capabilities.ocs.data,
      };

      return {
        content: [{
          type: 'text' as const,
          text: JSON.stringify(result, null, 2),
        }],
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error fetching system status: ${error instanceof Error ? error.message : String(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Run setup checks to verify system configuration
 */
export const setupChecksTool = {
  name: 'run_setup_checks',
  description: 'Run Nextcloud setup checks to verify system configuration (security, performance, PHP modules, etc.)',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await executeOCC("setupchecks");

      let output = "";
      if (result.success) {
        output += "Setup checks completed successfully.\n\n";
      } else {
        output += `Setup checks found issues (exit code: ${result.exitCode}).\n\n`;
      }

      if (result.stdout) {
        output += result.stdout;
      }
      if (result.stderr) {
        output += `\n--- stderr ---\n${result.stderr}`;
      }

      return {
        content: [{
          type: 'text' as const,
          text: output.trim(),
        }],
        isError: !result.success,
      };
    } catch (error) {
      return {
        content: [{
          type: 'text' as const,
          text: `Error running setup checks: ${formatOccError(error)}`,
        }],
        isError: true,
      };
    }
  },
};

/**
 * Export all System Status tools
 */
export const statusTools = [
  systemStatusTool,
  setupChecksTool,
];
