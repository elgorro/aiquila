import { z } from "zod";
import { executeOCC } from "../../client/aiquila.js";

/**
 * Nextcloud OCC Command Execution Tools
 * Execute any OCC command on the Nextcloud server via the AIquila app API
 */

export const runOccTool = {
  name: "run_occ",
  description:
    "Execute a Nextcloud OCC command on the server and return the output. " +
    "Can run any occ command such as app:list, config:list, maintenance:mode, " +
    "setupchecks, integrity:check-core, user:list, etc.",
  inputSchema: z.object({
    command: z
      .string()
      .describe(
        'The OCC command to execute (e.g., "app:list", "config:list", "setupchecks")'
      ),
    args: z
      .array(z.string())
      .optional()
      .describe(
        'Optional arguments for the command (e.g., ["--shipped=false", "--output=json"])'
      ),
    timeout: z
      .number()
      .optional()
      .describe("Timeout in seconds (default: 120, max: 600)"),
  }),
  handler: async (args: {
    command: string;
    args?: string[];
    timeout?: number;
  }) => {
    try {
      const result = await executeOCC(
        args.command,
        args.args ?? [],
        args.timeout
      );

      let output = "";

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
            type: "text" as const,
            text: output.trim(),
          },
        ],
        isError: !result.success,
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error executing OCC command: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const occTools = [runOccTool];
