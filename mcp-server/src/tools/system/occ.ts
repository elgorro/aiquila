import { z } from 'zod';
import { executeOCC, formatOccError } from '../../client/aiquila.js';

/**
 * Nextcloud OCC Command Execution Tools
 * Execute OCC commands on the Nextcloud server via the AIquila app API.
 * Only allowlisted commands may be executed (configurable via MCP_OCC_ALLOWLIST).
 */

const DEFAULT_OCC_ALLOWLIST = [
  'status',
  'app:list',
  'app:check-code',
  'config:list',
  'config:app:get',
  'setupchecks',
  'integrity:check-core',
  'integrity:check-app',
  'user:list',
  'user:info',
  'group:list',
  'maintenance:mode',
  'maintenance:mimetype:update-db',
  'db:add-missing-indices',
  'db:add-missing-columns',
  'db:add-missing-primary-keys',
  'files:scan',
  'files:cleanup',
  'trashbin:cleanup',
  'update:check',
  'notification:generate',
  'theming:config',
];

export function getOccAllowlist(): string[] {
  const envList = process.env.MCP_OCC_ALLOWLIST;
  if (envList) {
    return envList
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
  }
  return DEFAULT_OCC_ALLOWLIST;
}

export const runOccTool = {
  name: 'run_occ',
  description:
    'Execute an allowlisted Nextcloud OCC command on the server and return the output. ' +
    'Only commands in the allowlist may be run (configurable via MCP_OCC_ALLOWLIST env var). ' +
    'Allowed by default: status, app:list, app:check-code, config:list, config:app:get, ' +
    'setupchecks, integrity:check-core, integrity:check-app, user:list, user:info, group:list, ' +
    'maintenance:mode, maintenance:mimetype:update-db, db:add-missing-indices, ' +
    'db:add-missing-columns, db:add-missing-primary-keys, files:scan, files:cleanup, ' +
    'trashbin:cleanup, update:check, notification:generate, theming:config.',
  inputSchema: z.object({
    command: z
      .string()
      .describe('The OCC command to execute (e.g., "app:list", "config:list", "setupchecks")'),
    args: z
      .array(z.string())
      .optional()
      .describe('Optional arguments for the command (e.g., ["--shipped=false", "--output=json"])'),
    timeout: z.number().optional().describe('Timeout in seconds (default: 120, max: 600)'),
  }),
  handler: async (args: { command: string; args?: string[]; timeout?: number }) => {
    const allowlist = getOccAllowlist();

    if (!allowlist.includes(args.command)) {
      return {
        content: [
          {
            type: 'text' as const,
            text:
              `Command "${args.command}" is not in the OCC allowlist.\n\n` +
              `Allowed commands: ${allowlist.join(', ')}\n\n` +
              `Set MCP_OCC_ALLOWLIST env var to customize the allowlist.`,
          },
        ],
        isError: true,
      };
    }

    try {
      const result = await executeOCC(args.command, args.args ?? [], args.timeout);

      let output = '';

      if (result.success) {
        output += `Command completed successfully (exit code: ${result.exitCode})\n\n`;
      } else {
        output += `Command failed (exit code: ${result.exitCode})\n\n`;
      }

      if (result.stdout) {
        output += `--- stdout ---\n${result.stdout}\n`;
      }

      if (result.stderr) {
        output += `--- stderr ---\n${result.stderr}\n`;
      }

      if (result.error) {
        output += `Error: ${result.error}\n`;
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
            text: `Error executing OCC command: ${formatOccError(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const occTools = [runOccTool];
