import { z } from 'zod';
import { executeOCC, formatOccError } from '../../client/aiquila.js';

/**
 * Nextcloud Security & Integrity Tools
 * Verify system and app integrity via OCC commands
 */

/**
 * Check Nextcloud core system integrity
 */
export const checkCoreIntegrityTool = {
  name: 'check_core_integrity',
  description:
    'Verify Nextcloud core system integrity by checking file signatures against the official release',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await executeOCC('integrity:check-core');

      let output = '';
      if (result.success) {
        output += 'Core integrity check passed.\n\n';
      } else {
        output += `Core integrity check found issues (exit code: ${result.exitCode}).\n\n`;
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
            text: `Error checking core integrity: ${formatOccError(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Check integrity of a specific app
 */
export const checkAppIntegrityTool = {
  name: 'check_app_integrity',
  description:
    'Verify integrity of a specific Nextcloud app by checking file signatures against its official release',
  inputSchema: z.object({
    appId: z.string().describe('The app ID to check (e.g., "tasks", "deck", "photos")'),
  }),
  handler: async (args: { appId: string }) => {
    try {
      const result = await executeOCC('integrity:check-app', [args.appId]);

      let output = '';
      if (result.success) {
        output += `App "${args.appId}" integrity check passed.\n\n`;
      } else {
        output += `App "${args.appId}" integrity check found issues (exit code: ${result.exitCode}).\n\n`;
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
            text: `Error checking app integrity for "${args.appId}": ${formatOccError(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Security & Integrity tools
 */
export const securityTools = [checkCoreIntegrityTool, checkAppIntegrityTool];
