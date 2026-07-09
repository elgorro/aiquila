// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchAiquilaAPI } from '../../client/aiquila.js';
import type { ToolAnnotations } from '../types.js';

/**
 * AIquila Cowork Tools
 *
 * Steer persistent, scheduled AI tasks ("coworkers") that run server-side in the
 * AIquila Nextcloud app — e.g. image-classification jobs powered by Claude vision
 * or Mistral Pixtral. Lets the assistant create from templates, configure,
 * enable/disable/pause, trigger an immediate run, and inspect run progress.
 */

// ── Shapes ──────────────────────────────────────────────────────────────────

interface Coworker {
  id: number;
  title: string;
  description?: string | null;
  model?: string | null;
  taskType: string;
  cronSchedule: string;
  inputType: string;
  inputPath?: string | null;
  outputType: string;
  isActive: boolean;
  paused: boolean;
  lastRunAt?: number | null;
  nextRunAt?: number | null;
  lastStatus?: string | null;
  lastError?: string | null;
}

interface CoworkerRun {
  id: number;
  coworkerId: number;
  status: string;
  itemsTotal: number;
  itemsProcessed: number;
  summary?: string | null;
  error?: string | null;
  startedAt: number;
  finishedAt?: number | null;
}

interface TemplatesResponse {
  templates: Array<{ id: string; title: string; description?: string; model?: string }>;
  taskTypes: Array<{ id: string; label: string; family: string }>;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function err(action: string, error: unknown) {
  return {
    content: [
      {
        type: 'text' as const,
        text: `Error ${action}: ${error instanceof Error ? error.message : String(error)}`,
      },
    ],
    isError: true,
  };
}

function text(body: string) {
  return { content: [{ type: 'text' as const, text: body }] };
}

function fmtTime(epoch?: number | null): string {
  return epoch ? new Date(epoch * 1000).toISOString() : '—';
}

function formatCoworker(c: Coworker): string {
  const state = !c.isActive ? 'disabled' : c.paused ? 'paused' : 'active';
  const lines = [
    `- **${c.title}** (ID: ${c.id}) — ${state}`,
    `  task: ${c.taskType} | provider: ${c.model ?? 'default'} | input: ${c.inputPath ?? '/'}`,
    `  schedule: ${c.cronSchedule} | next run: ${fmtTime(c.nextRunAt)} | last run: ${fmtTime(c.lastRunAt)}${c.lastStatus ? ` (${c.lastStatus})` : ''}`,
  ];
  if (c.lastError) lines.push(`  ⚠ last error: ${c.lastError}`);
  return lines.join('\n');
}

// ── Tools ────────────────────────────────────────────────────────────────────

export const listCoworkersTool = {
  name: 'list_coworkers',
  title: 'List Coworkers',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List the current user's coworkers (persistent scheduled AI tasks) with their status, provider, schedule and last run.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const coworkers = await fetchAiquilaAPI<Coworker[]>('/coworkers');
      if (coworkers.length === 0) {
        return text(
          'No coworkers configured. Use create_coworker (optionally with a templateId) to add one.'
        );
      }
      return text(coworkers.map(formatCoworker).join('\n\n'));
    } catch (error) {
      return err('listing coworkers', error);
    }
  },
};

export const listCoworkerTemplatesTool = {
  name: 'list_coworker_templates',
  title: 'List Coworker Templates',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List built-in coworker templates (e.g. image classification via Claude vision or Mistral Pixtral) and available task types.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const data = await fetchAiquilaAPI<TemplatesResponse>('/coworkers/templates');
      const tpls = data.templates
        .map((t) => `- **${t.title}** (id: ${t.id})${t.description ? ` — ${t.description}` : ''}`)
        .join('\n');
      const types = data.taskTypes.map((t) => `- ${t.id} (${t.family}) — ${t.label}`).join('\n');
      return text(`Templates:\n${tpls}\n\nTask types:\n${types}`);
    } catch (error) {
      return err('listing templates', error);
    }
  },
};

export const getCoworkerTool = {
  name: 'get_coworker',
  title: 'Get Coworker',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get a single coworker by ID, including its schedule and last run status.',
  inputSchema: z.object({ id: z.number().describe('Coworker ID') }),
  handler: async (args: { id: number }) => {
    try {
      const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`);
      return text(formatCoworker(c));
    } catch (error) {
      return err('getting coworker', error);
    }
  },
};

export const createCoworkerTool = {
  name: 'create_coworker',
  title: 'Create Coworker',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a coworker. Provide templateId to start from a built-in template (e.g. "classify-images-claude" or "classify-images-pixtral"), and/or explicit fields to configure a custom one. Fields override template defaults.',
  inputSchema: z.object({
    templateId: z
      .string()
      .optional()
      .describe('Built-in template id (see list_coworker_templates)'),
    title: z.string().optional(),
    taskType: z.string().optional().describe('Task type id, e.g. "vision:classify"'),
    model: z
      .string()
      .optional()
      .describe('Provider id: "anthropic" (Claude) or "mistral" (Pixtral)'),
    inputPath: z.string().optional().describe('Folder to process, e.g. "/Photos"'),
    cronSchedule: z.string().optional().describe('5-field cron, e.g. "0 3 * * *"'),
    maxTags: z.number().optional().describe('Max tags per image (vision:classify)'),
    recursive: z.boolean().optional().describe('Include sub-folders'),
    isActive: z.boolean().optional().describe('Whether the coworker is enabled'),
  }),
  handler: async (args: {
    templateId?: string;
    title?: string;
    taskType?: string;
    model?: string;
    inputPath?: string;
    cronSchedule?: string;
    maxTags?: number;
    recursive?: boolean;
    isActive?: boolean;
  }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.title !== undefined) body.title = args.title;
      if (args.taskType !== undefined) body.task_type = args.taskType;
      if (args.model !== undefined) body.model = args.model;
      if (args.inputPath !== undefined) {
        body.input_type = 'folder';
        body.input_path = args.inputPath;
      }
      if (args.cronSchedule !== undefined) body.cron_schedule = args.cronSchedule;
      if (args.isActive !== undefined) body.is_active = args.isActive;
      const options: Record<string, unknown> = {};
      if (args.maxTags !== undefined) options.maxTags = args.maxTags;
      if (args.recursive !== undefined) options.recursive = args.recursive;
      if (Object.keys(options).length > 0) body.options = options;

      const endpoint = args.templateId ? '/coworkers/templates' : '/coworkers';
      if (args.templateId) body.templateId = args.templateId;

      const c = await fetchAiquilaAPI<Coworker>(endpoint, { method: 'POST', body });
      return text(`Coworker created:\n${formatCoworker(c)}`);
    } catch (error) {
      return err('creating coworker', error);
    }
  },
};

export const updateCoworkerTool = {
  name: 'update_coworker',
  title: 'Update Coworker',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Update a coworker's configuration (title, provider, input folder, schedule, options).",
  inputSchema: z.object({
    id: z.number().describe('Coworker ID'),
    title: z.string().optional(),
    model: z.string().optional().describe('"anthropic" or "mistral"'),
    inputPath: z.string().optional(),
    cronSchedule: z.string().optional(),
    maxTags: z.number().optional(),
    recursive: z.boolean().optional(),
  }),
  handler: async (args: {
    id: number;
    title?: string;
    model?: string;
    inputPath?: string;
    cronSchedule?: string;
    maxTags?: number;
    recursive?: boolean;
  }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.title !== undefined) body.title = args.title;
      if (args.model !== undefined) body.model = args.model;
      if (args.inputPath !== undefined) {
        body.input_type = 'folder';
        body.input_path = args.inputPath;
      }
      if (args.cronSchedule !== undefined) body.cron_schedule = args.cronSchedule;
      const options: Record<string, unknown> = {};
      if (args.maxTags !== undefined) options.maxTags = args.maxTags;
      if (args.recursive !== undefined) options.recursive = args.recursive;
      if (Object.keys(options).length > 0) body.options = options;

      const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`, { method: 'PUT', body });
      return text(`Coworker updated:\n${formatCoworker(c)}`);
    } catch (error) {
      return err('updating coworker', error);
    }
  },
};

export const deleteCoworkerTool = {
  name: 'delete_coworker',
  title: 'Delete Coworker',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a coworker and its run history.',
  inputSchema: z.object({ id: z.number().describe('Coworker ID') }),
  handler: async (args: { id: number }) => {
    try {
      await fetchAiquilaAPI(`/coworkers/${args.id}`, { method: 'DELETE' });
      return text(`Coworker ${args.id} deleted.`);
    } catch (error) {
      return err('deleting coworker', error);
    }
  },
};

function stateTool(
  name: string,
  title: string,
  description: string,
  action: 'enable' | 'disable' | 'pause' | 'resume' | 'run',
  annotations: ToolAnnotations
) {
  return {
    name,
    title,
    description,
    annotations,
    inputSchema: z.object({ id: z.number().describe('Coworker ID') }),
    handler: async (args: { id: number }) => {
      try {
        if (action === 'run') {
          const run = await fetchAiquilaAPI<CoworkerRun>(`/coworkers/${args.id}/run`, {
            method: 'POST',
          });
          return text(
            `Run finished: ${run.status} — processed ${run.itemsProcessed}/${run.itemsTotal}.` +
              (run.summary ? `\n${run.summary}` : '') +
              (run.error ? `\n⚠ ${run.error}` : '')
          );
        }
        const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}/${action}`, {
          method: 'POST',
        });
        return text(`Coworker ${action}d:\n${formatCoworker(c)}`);
      } catch (error) {
        return err(`${action} coworker`, error);
      }
    },
  };
}

const STATE_CHANGE: ToolAnnotations = {
  readOnlyHint: false,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: false,
};
/** Turning a coworker off removes its scheduled behaviour. */
const STATE_STOP: ToolAnnotations = { ...STATE_CHANGE, destructiveHint: true };

export const enableCoworkerTool = stateTool(
  'enable_coworker',
  'Enable Coworker',
  'Enable a coworker so it runs on its schedule.',
  'enable',
  STATE_CHANGE
);
export const disableCoworkerTool = stateTool(
  'disable_coworker',
  'Disable Coworker',
  'Disable a coworker so it stops running on its schedule.',
  'disable',
  STATE_STOP
);
export const pauseCoworkerTool = stateTool(
  'pause_coworker',
  'Pause Coworker',
  'Temporarily pause a coworker without disabling it.',
  'pause',
  STATE_STOP
);
export const resumeCoworkerTool = stateTool(
  'resume_coworker',
  'Resume Coworker',
  'Resume a paused coworker.',
  'resume',
  STATE_CHANGE
);
export const runCoworkerTool = stateTool(
  'run_coworker',
  'Run Coworker',
  'Run a coworker immediately (synchronously) and return the run result.',
  'run',
  // executes the coworker's prompt against an AI backend; repeating it does more work
  { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true }
);

export const getCoworkerRunsTool = {
  name: 'get_coworker_runs',
  title: 'Get Coworker Runs',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get recent run history (progress, status, summary) for a coworker.',
  inputSchema: z.object({
    id: z.number().describe('Coworker ID'),
    limit: z.number().optional().describe('Max runs to return (default 20)'),
  }),
  handler: async (args: { id: number; limit?: number }) => {
    try {
      const runs = await fetchAiquilaAPI<CoworkerRun[]>(`/coworkers/${args.id}/runs`, {
        queryParams: args.limit ? { limit: String(args.limit) } : undefined,
      });
      if (runs.length === 0) return text('No runs yet for this coworker.');
      const lines = runs.map(
        (r) =>
          `- #${r.id} ${r.status} — ${r.itemsProcessed}/${r.itemsTotal} — ${fmtTime(r.startedAt)}` +
          (r.error ? ` — ⚠ ${r.error}` : r.summary ? ` — ${r.summary.split('\n')[0]}` : '')
      );
      return text(lines.join('\n'));
    } catch (error) {
      return err('getting coworker runs', error);
    }
  },
};

export const coworkTools = [
  listCoworkersTool,
  listCoworkerTemplatesTool,
  getCoworkerTool,
  createCoworkerTool,
  updateCoworkerTool,
  deleteCoworkerTool,
  enableCoworkerTool,
  disableCoworkerTool,
  pauseCoworkerTool,
  resumeCoworkerTool,
  runCoworkerTool,
  getCoworkerRunsTool,
];
