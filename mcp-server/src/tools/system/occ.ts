// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { executeOCC, formatOccError } from '../../client/aiquila.js';
import { redactSensitiveOutput } from './occ-redact.js';

/**
 * Nextcloud OCC Command Execution Tools
 * Execute OCC commands on the Nextcloud server via the AIquila app API.
 * Only allowlisted commands may be executed (configurable via MCP_OCC_ALLOWLIST).
 *
 * An allowlist entry is a command followed by the exact arguments it permits:
 * `cmd` allows the command with no arguments, `cmd a b` exactly `a b`, and a
 * trailing `*` any further arguments (`cmd *` allows any). The command name
 * itself must match exactly, so Symfony's command abbreviations are refused.
 */

/**
 * Read-only by default: these report state and change nothing. Commands that
 * write, delete or message users (`maintenance:mode --on`, `files:scan`,
 * `trashbin:cleanup`, `notification:generate`, `theming:config <key> <value>`,
 * `config:app:get`, which can read app secrets …) are opt-in via MCP_OCC_ALLOWLIST.
 */
export const DEFAULT_OCC_ALLOWLIST = [
  'status *',
  'app:list *',
  'setupchecks *',
  'integrity:check-core *',
  'integrity:check-app *',
  'user:list *',
  'user:info *',
  'group:list *',
  'update:check',
  'maintenance:mode',
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

/** Whether `command` with `args` matches an allowlist entry. */
export function isOccAllowed(allowlist: string[], command: string, args: string[]): boolean {
  return allowlist.some((entry) => {
    const [name, ...pattern] = entry.split(/\s+/);
    if (name !== command) return false;
    const wildcard = pattern[pattern.length - 1] === '*';
    const fixed = wildcard ? pattern.slice(0, -1) : pattern;
    if (wildcard ? args.length < fixed.length : args.length !== fixed.length) return false;
    return fixed.every((arg, i) => args[i] === arg);
  });
}

export const runOccTool = {
  name: 'run_occ',
  title: 'Run OCC Command',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Execute an allowlisted Nextcloud OCC command on the server and return the output. ' +
    'Only command and argument combinations in the allowlist may be run (configurable via ' +
    'MCP_OCC_ALLOWLIST env var). The default allows read-only commands only: status, app:list, ' +
    'setupchecks, integrity:check-core, integrity:check-app, user:list, user:info and group:list ' +
    'with any arguments; update:check, maintenance:mode and theming:config without arguments.',
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

    if (!isOccAllowed(allowlist, args.command, args.args ?? [])) {
      const invocation = [args.command, ...(args.args ?? [])].join(' ');
      return {
        content: [
          {
            type: 'text' as const,
            text:
              `Command "${invocation}" is not in the OCC allowlist.\n\n` +
              `Allowed (\`*\` = any arguments): ${allowlist.join(', ')}\n\n` +
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
        output += `--- stdout ---\n${redactSensitiveOutput(result.stdout)}\n`;
      }

      if (result.stderr) {
        output += `--- stderr ---\n${redactSensitiveOutput(result.stderr)}\n`;
      }

      if (result.error) {
        output += `Error: ${redactSensitiveOutput(result.error)}\n`;
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
