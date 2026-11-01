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
    // aiquila:* commands report failures on stdout, not stderr
    const errorMsg = result.stderr || result.stdout?.trim() || result.error || 'Unknown error';
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
  description:
    "Show current AIquila configuration. With targetProvider, list that provider's settings by id (the ids aiquila_configure's providerSettings takes); keys are never shown",
  inputSchema: z.object({
    targetProvider: z
      .string()
      .optional()
      .describe("Provider to show settings for (e.g. 'local'); omitted shows the instance default"),
  }),
  handler: async (args: { targetProvider?: string } = {}) => {
    try {
      const showArgs = ['--show'];
      if (args.targetProvider) {
        showArgs.push('--target-provider', args.targetProvider);
      }
      const output = await runOCC('aiquila:configure', showArgs);
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
        isError: true,
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
    'Configure AIquila settings (API key, model, tokens, timeout, effort and thinking for chat and background tasks), ' +
    'and set up any LLM provider. Use targetProvider to configure a provider without making it the instance default; ' +
    'provider makes it the default. Run aiquila_show_config with targetProvider to list the setting ids a provider accepts.',
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
    provider: z
      .string()
      .optional()
      .describe(
        "Make this provider the instance default for every user (e.g. 'anthropic', 'mistral', 'hetzner', 'local'). Applied last, after its settings are accepted"
      ),
    targetProvider: z
      .string()
      .optional()
      .describe(
        'Provider that providerKey, providerModel and providerSettings apply to, without changing the instance default. Defaults to provider, else anthropic'
      ),
    providerKey: z.string().optional().describe('API key for the target provider'),
    providerModel: z.string().optional().describe('Model for the target provider'),
    providerSettings: z
      .record(z.string(), z.string())
      .optional()
      .describe(
        "Other settings of the target provider by setting id or app config key, e.g. { local_base_url: 'http://llama:8080', timeout: '600' }. Validated like the admin settings page; access lists take comma-separated ids"
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
    provider?: string;
    targetProvider?: string;
    providerKey?: string;
    providerModel?: string;
    providerSettings?: Record<string, string>;
  }) => {
    try {
      const configArgs: string[] = [];

      if (args.provider) {
        configArgs.push('--provider', args.provider);
      }
      if (args.targetProvider) {
        configArgs.push('--target-provider', args.targetProvider);
      }
      if (args.providerKey) {
        configArgs.push('--provider-key', args.providerKey);
      }
      if (args.providerModel) {
        configArgs.push('--provider-model', args.providerModel);
      }
      for (const [id, value] of Object.entries(args.providerSettings ?? {})) {
        configArgs.push('--provider-setting', `${id}=${value}`);
      }

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
        isError: true,
      };
    }
  },
};

/**
 * Test AIquila Claude API integration
 */
export const testTool = {
  name: 'aiquila_test',
  title: 'Test AIquila Provider',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    'Send a test prompt through the provider that serves a user (or the instance default), or through a named provider',
  inputSchema: z.object({
    prompt: z.string().default('Hello, Claude!').describe('Test prompt to send'),
    provider: z
      .string()
      .optional()
      .describe("Provider to test (e.g. 'local') instead of the one that would serve the user"),
    user: z
      .string()
      .optional()
      .describe('Nextcloud user to test as; their personal provider, key and model apply'),
  }),
  handler: async (args: { prompt: string; provider?: string; user?: string }) => {
    try {
      const testArgs = ['--prompt', args.prompt];
      if (args.provider) {
        testArgs.push('--provider', args.provider);
      }
      if (args.user) {
        testArgs.push('--user', args.user);
      }
      const output = await runOCC('aiquila:test', testArgs);
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
        isError: true,
      };
    }
  },
};

/**
 * Export all AIquila internal tools
 */
export const aiquilaTools = [showConfigTool, configureTool, testTool];
