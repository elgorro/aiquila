import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Assistant / AI Task Processing Tools
 *
 * Bridges MCP to Nextcloud's native AI task processing framework
 * (TaskProcessing API, NC 29+) and text-to-image API.
 *
 * These tools let Claude delegate structured text tasks (summarise, extract
 * topics, generate headline, etc.) and image generation to whatever AI
 * provider is configured inside Nextcloud (LocalAI, Stable Diffusion, …).
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface TaskType {
  id: string;
  name: string;
  description?: string;
  inputShape?: Record<string, unknown>;
  outputShape?: Record<string, unknown>;
}

interface Task {
  id: number;
  type: string;
  status: number; // 0=unknown,1=scheduled,2=running,3=successful,4=failed
  output?: Record<string, unknown>;
  input?: Record<string, unknown>;
  appId?: string;
  customId?: string;
  errorMessage?: string;
}

// NC TaskProcessing status codes
const STATUS_LABELS: Record<number, string> = {
  0: 'unknown',
  1: 'scheduled',
  2: 'running',
  3: 'successful',
  4: 'failed',
};

// ---------------------------------------------------------------------------
// list_text_tasks
// ---------------------------------------------------------------------------

export const listTextTasksTool = {
  name: 'list_text_tasks',
  description:
    "List AI task types available in Nextcloud's TaskProcessing framework (summarize, headline, image-to-text, text-to-image, …). Shows which task types have at least one provider configured.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const data = await fetchOCS<{ types: Record<string, TaskType> }>(
        '/ocs/v2.php/taskprocessing/tasktypes'
      );
      const types = data.ocs.data.types ?? {};
      const list = Object.entries(types).map(([id, t]) => ({
        id,
        name: t.name,
        description: t.description,
      }));
      return {
        content: [
          {
            type: 'text' as const,
            text:
              list.length === 0
                ? 'No AI task types are currently configured in Nextcloud.'
                : JSON.stringify(list, null, 2),
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing task types: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// process_text
// ---------------------------------------------------------------------------

export const processTextTool = {
  name: 'process_text',
  description:
    "Submit a text-processing task to Nextcloud's AI framework and return the task ID. Use get_task_result to poll until it completes. Common task types: 'core:text2text' (free prompt), 'core:summarize' (summary), 'core:headline' (headline), 'core:extract_topics' (topics).",
  inputSchema: z.object({
    taskType: z
      .string()
      .describe(
        "Task type ID (e.g. 'core:summarize', 'core:headline', 'core:extract_topics', 'core:text2text')"
      ),
    input: z.string().describe('The text to process'),
    customId: z
      .string()
      .optional()
      .describe('Optional identifier to tag the task for later retrieval'),
  }),
  handler: async (args: { taskType: string; input: string; customId?: string }) => {
    try {
      const payload: Record<string, unknown> = {
        type: args.taskType,
        appId: 'aiquila-mcp',
        input: { input: args.input },
      };
      if (args.customId) {
        payload.customId = args.customId;
      }

      const data = await fetchOCS<{ task: Task }>('/ocs/v2.php/taskprocessing/schedule', {
        method: 'POST',
        jsonBody: payload,
      });
      const task = data.ocs.data.task;
      return {
        content: [
          {
            type: 'text' as const,
            text: `Task scheduled. ID: ${task.id} | Status: ${STATUS_LABELS[task.status] ?? task.status}\n\nUse get_task_result with taskId ${task.id} to retrieve the result.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error scheduling task: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// get_task_result
// ---------------------------------------------------------------------------

export const getTaskResultTool = {
  name: 'get_task_result',
  description:
    'Get the status and result of a Nextcloud AI task previously created by process_text or generate_image. Status: 1=scheduled, 2=running, 3=successful, 4=failed.',
  inputSchema: z.object({
    taskId: z.number().describe('The task ID returned by process_text or generate_image'),
  }),
  handler: async (args: { taskId: number }) => {
    try {
      const data = await fetchOCS<{ task: Task }>(`/ocs/v2.php/taskprocessing/task/${args.taskId}`);
      const task = data.ocs.data.task;
      const statusLabel = STATUS_LABELS[task.status] ?? String(task.status);

      if (task.status === 3) {
        // successful
        const output = task.output;
        const text =
          typeof output?.output === 'string' ? output.output : JSON.stringify(output, null, 2);
        return {
          content: [
            {
              type: 'text' as const,
              text: `Task ${task.id} completed successfully.\n\nResult:\n${text}`,
            },
          ],
        };
      }

      if (task.status === 4) {
        // failed
        return {
          content: [
            {
              type: 'text' as const,
              text: `Task ${task.id} failed: ${task.errorMessage ?? 'Unknown error'}`,
            },
          ],
          isError: true,
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Task ${task.id} status: ${statusLabel}. Poll again shortly.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting task result: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// generate_image
// ---------------------------------------------------------------------------

export const generateImageTool = {
  name: 'generate_image',
  description:
    "Generate an image from a text prompt using Nextcloud's text-to-image AI provider (e.g. Stable Diffusion via LocalAI). Returns the task ID; use get_task_result to check completion.",
  inputSchema: z.object({
    prompt: z.string().describe('Text description of the image to generate'),
    savePath: z
      .string()
      .optional()
      .describe(
        "Optional Nextcloud path to save the generated image (e.g. '/Photos/generated.png'). If omitted, only the task ID is returned."
      ),
  }),
  handler: async (args: { prompt: string; savePath?: string }) => {
    try {
      const payload: Record<string, unknown> = {
        type: 'core:text2image',
        appId: 'aiquila-mcp',
        input: {
          input: args.prompt,
          numberOfImages: 1,
        },
      };
      if (args.savePath) {
        payload.customId = `savePath:${args.savePath}`;
      }

      const data = await fetchOCS<{ task: Task }>('/ocs/v2.php/taskprocessing/schedule', {
        method: 'POST',
        jsonBody: payload,
      });
      const task = data.ocs.data.task;
      const lines = [
        `Image generation task scheduled. ID: ${task.id}`,
        `Status: ${STATUS_LABELS[task.status] ?? task.status}`,
        '',
        `Use get_task_result with taskId ${task.id} to check progress.`,
      ];
      if (args.savePath) {
        lines.push(
          `Requested save path: ${args.savePath} (manual download required after task completes).`
        );
      }

      return {
        content: [{ type: 'text' as const, text: lines.join('\n') }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error scheduling image generation: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const assistantTools = [
  listTextTasksTool,
  processTextTool,
  getTaskResultTool,
  generateImageTool,
];
