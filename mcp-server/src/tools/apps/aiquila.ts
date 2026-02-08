import { z } from "zod";
import { executeOCC } from "../../client/aiquila.js";

/**
 * AIquila Internal Tools
 * Provides configuration and testing for AIquila OCC commands
 */

/**
 * Helper function to run OCC commands via the AIquila app API
 */
async function runOCC(command: string, args: string[] = []): Promise<string> {
  const result = await executeOCC(command, args);

  if (!result.success) {
    const errorMsg = result.stderr || result.error || "Unknown error";
    throw new Error(
      `OCC command failed (exit ${result.exitCode}): ${errorMsg}`
    );
  }

  return result.stdout || "";
}

/**
 * Show current AIquila configuration
 */
export const showConfigTool = {
  name: "aiquila_show_config",
  description: "Show current AIquila configuration",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const output = await runOCC("aiquila:show");
      return {
        content: [
          {
            type: "text",
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Configure AIquila settings
 */
export const configureTool = {
  name: "aiquila_configure",
  description: "Configure AIquila settings (API key, model, tokens, timeout)",
  inputSchema: z.object({
    apiKey: z.string().optional().describe("Anthropic API key"),
    model: z
      .string()
      .optional()
      .describe("Claude model to use (e.g., 'claude-3-5-sonnet-20241022')"),
    maxTokens: z
      .number()
      .optional()
      .describe("Maximum tokens for responses (default: 4096)"),
    timeout: z
      .number()
      .optional()
      .describe("Request timeout in seconds (default: 60)"),
  }),
  handler: async (args: {
    apiKey?: string;
    model?: string;
    maxTokens?: number;
    timeout?: number;
  }) => {
    try {
      const configArgs: string[] = [];

      if (args.apiKey) {
        configArgs.push("--api-key", args.apiKey);
      }
      if (args.model) {
        configArgs.push("--model", args.model);
      }
      if (args.maxTokens) {
        configArgs.push("--max-tokens", args.maxTokens.toString());
      }
      if (args.timeout) {
        configArgs.push("--timeout", args.timeout.toString());
      }

      const output = await runOCC("aiquila:configure", configArgs);
      return {
        content: [
          {
            type: "text",
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Test AIquila Claude API integration
 */
export const testTool = {
  name: "aiquila_test",
  description: "Test AIquila Claude API integration with a simple prompt",
  inputSchema: z.object({
    prompt: z
      .string()
      .default("Hello, Claude!")
      .describe("Test prompt to send to Claude API"),
  }),
  handler: async (args: { prompt: string }) => {
    try {
      const output = await runOCC("aiquila:test", ["--prompt", args.prompt]);
      return {
        content: [
          {
            type: "text",
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text",
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Export all AIquila internal tools
 */
export const aiquilaTools = [showConfigTool, configureTool, testTool];
