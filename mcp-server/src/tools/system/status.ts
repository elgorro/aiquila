import { z } from 'zod';
import { fetchOCS, fetchStatus } from '../../client/ocs.js';
import { executeOCC, formatOccError } from '../../client/aiquila.js';

/**
 * Nextcloud System Status Tools
 * Provides system diagnostics and monitoring via OCS API
 */

/**
 * Get overall system status
 */
export const systemStatusTool = {
  name: 'system_status',
  description:
    'Get Nextcloud system status including version, installation path, and configuration',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const [status, capabilities] = await Promise.all([
        fetchStatus(),
        fetchOCS<Record<string, unknown>>('/ocs/v2.php/cloud/capabilities'),
      ]);

      const result = {
        status,
        capabilities: capabilities.ocs.data,
      };

      return {
        content: [
          {
            type: 'text' as const,
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error fetching system status: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
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
  description:
    'Run Nextcloud setup checks to verify system configuration (security, performance, PHP modules, etc.)',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await executeOCC('setupchecks');

      let output = '';
      if (result.success) {
        output += 'Setup checks completed successfully.\n\n';
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
        content: [
          {
            type: 'text' as const,
            text: output.trim(),
          },
        ],
        isError: !result.success,
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error running setup checks: ${formatOccError(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get the current local time and timezone of the MCP server
 */
export const getLocalTimeTool = {
  name: 'get_local_time',
  description:
    'Get the current local time and timezone of the MCP server. ' +
    'Call this to establish a time reference before creating or listing calendar events, ' +
    'or to resolve scheduling ambiguities with the user.',
  inputSchema: z.object({}),
  handler: async () => {
    const now = new Date();
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offsetMin = now.getTimezoneOffset(); // positive = behind UTC, negative = ahead
    const sign = offsetMin <= 0 ? '+' : '-';
    const abs = Math.abs(offsetMin);
    const h = String(Math.floor(abs / 60)).padStart(2, '0');
    const m = String(abs % 60).padStart(2, '0');
    const utcOffset = `${sign}${h}:${m}`;

    return {
      content: [
        {
          type: 'text' as const,
          text: JSON.stringify(
            {
              localTime: now.toLocaleString(process.env.MCP_LOCALE, { timeZone: tz }),
              utcTime: now.toISOString(),
              timezone: tz, // e.g. "Europe/Berlin"
              utcOffset, // e.g. "+01:00"
            },
            null,
            2
          ),
        },
      ],
    };
  },
};

/**
 * Export all System Status tools
 */
export const statusTools = [systemStatusTool, setupChecksTool, getLocalTimeTool];
