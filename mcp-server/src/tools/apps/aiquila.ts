// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { executeOCC } from '../../client/aiquila.js';

/** Effort levels `occ aiquila:configure` accepts; each model takes a subset. */
const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'] as const;

/** Thinking modes; 'auto' follows the model's own default. */
const THINKING_MODES = ['auto', 'on', 'off'] as const;

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
    const errorMsg = result.stderr || result.error || 'Unknown error';
    throw new Error(`OCC command failed (exit ${result.exitCode}): ${errorMsg}`);
  }

  return result.stdout || '';
}

/**
 * Show current AIquila configuration
 */
export const showConfigTool = {
  name: 'aiquila_show_config',
  title: 'Show AIquila Configuration',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Show current AIquila configuration',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const output = await runOCC('aiquila:configure', ['--show']);
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
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
  name: 'aiquila_configure',
  title: 'Configure AIquila',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Configure AIquila settings (API key, model, tokens, timeout, effort and thinking for chat and background tasks)',
  inputSchema: z.object({
    apiKey: z.string().optional().describe('Anthropic API key'),
    model: z
      .string()
      .optional()
      .describe(
        "Claude model to use (e.g., 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5')"
      ),
    maxTokens: z.number().optional().describe('Maximum tokens for responses (default: 4096)'),
    timeout: z.number().optional().describe('Request timeout in seconds (default: 60)'),
    effort: z
      .enum(['', ...EFFORTS])
      .optional()
      .describe('Default effort level; empty string resets to the model default'),
    thinking: z
      .enum(THINKING_MODES)
      .optional()
      .describe(
        "Default thinking: 'auto' follows the model (Fable, Opus 5.x and Sonnet 5 think on their own), 'on' or 'off'. Fable and Opus 5.5 always think."
      ),
    taskEffort: z
      .enum(['', ...EFFORTS])
      .optional()
      .describe('Effort for Assistant tasks and coworkers; empty string uses the default effort'),
    taskThinking: z
      .enum(['', ...THINKING_MODES])
      .optional()
      .describe(
        'Thinking for Assistant tasks and coworkers; empty string uses the default thinking'
      ),
  }),
  handler: async (args: {
    apiKey?: string;
    model?: string;
    maxTokens?: number;
    timeout?: number;
    effort?: string;
    thinking?: string;
    taskEffort?: string;
    taskThinking?: string;
  }) => {
    try {
      const configArgs: string[] = [];

      if (args.apiKey) {
        configArgs.push('--api-key', args.apiKey);
      }
      if (args.model) {
        configArgs.push('--model', args.model);
      }
      if (args.maxTokens) {
        configArgs.push('--max-tokens', args.maxTokens.toString());
      }
      if (args.timeout) {
        configArgs.push('--timeout', args.timeout.toString());
      }
      // Empty strings are meaningful here (reset), so test for undefined.
      if (args.effort !== undefined) {
        configArgs.push('--effort', args.effort);
      }
      if (args.thinking !== undefined) {
        configArgs.push('--thinking', args.thinking);
      }
      if (args.taskEffort !== undefined) {
        configArgs.push('--task-effort', args.taskEffort);
      }
      if (args.taskThinking !== undefined) {
        configArgs.push('--task-thinking', args.taskThinking);
      }

      const output = await runOCC('aiquila:configure', configArgs);
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
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
  name: 'aiquila_test',
  title: 'Test AIquila Claude Integration',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description: 'Test AIquila Claude API integration with a simple prompt',
  inputSchema: z.object({
    prompt: z.string().default('Hello, Claude!').describe('Test prompt to send to Claude API'),
  }),
  handler: async (args: { prompt: string }) => {
    try {
      const output = await runOCC('aiquila:test', ['--prompt', args.prompt]);
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
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
