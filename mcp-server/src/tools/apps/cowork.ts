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
 * schedule, enable/disable/pause, run now or stop a run, and inspect run history
 * and logs.
 */

// ── Shapes ──────────────────────────────────────────────────────────────────

interface Coworker {
  id: number;
  title: string;
  description?: string | null;
  /** Pinned provider id; null follows the owner's setting. */
  provider?: string | null;
  /** Pinned model id; null uses the provider's default. */
  model?: string | null;
  taskType: string;
  cronSchedule: string;
  inputType: string;
  inputPath?: string | null;
  outputType: string;
  outputPath?: string | null;
  /** Task-specific options, e.g. maxTags, targetLanguage, effort, tools. */
  options?: Record<string, unknown>;
  customPrompt?: string | null;
  promptId?: number | null;
  /** App that registered the coworker through ICoworkManager; null for user-created. */
  ownerApp?: string | null;
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
  /** running | pending | success | error | cancelled */
  status: string;
  itemsTotal: number;
  itemsProcessed: number;
  summary?: string | null;
  error?: string | null;
  startedAt: number;
  finishedAt?: number | null;
}

interface CoworkerRunLogEntry {
  ts: number;
  level: string;
  message: string;
  item?: string | null;
}

/** A single run as returned by GET /coworkers/{id}/runs/{runId}. */
interface CoworkerRunDetail extends CoworkerRun {
  log?: CoworkerRunLogEntry[];
}

interface TemplatesResponse {
  templates: Array<{
    id: string;
    title: string;
    description?: string;
    provider?: string;
    scope?: 'builtin' | 'instance';
  }>;
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
  const opts = c.options ?? {};
  const lines = [`- **${c.title}** (ID: ${c.id}) — ${state}`];
  if (c.description) lines.push(`  ${c.description}`);
  lines.push(
    `  task: ${c.taskType} | provider: ${c.provider ?? 'default'}${c.model ? ` (${c.model})` : ''} | input: ${c.inputPath ?? '/'}`,
    `  output: ${c.outputType}${c.outputPath ? ` → ${c.outputPath}` : ''}`,
    `  schedule: ${c.cronSchedule} | next run: ${fmtTime(c.nextRunAt)} | last run: ${fmtTime(c.lastRunAt)}${c.lastStatus ? ` (${c.lastStatus})` : ''}`
  );
  const tuning = [
    typeof opts.effort === 'string' && opts.effort ? `effort: ${opts.effort}` : '',
    typeof opts.thinking === 'string' && opts.thinking ? `thinking: ${opts.thinking}` : '',
  ].filter(Boolean);
  if (tuning.length > 0) lines.push(`  ${tuning.join(' | ')}`);
  if (c.customPrompt) lines.push(`  prompt: ${c.customPrompt.split('\n')[0]}`);
  else if (c.promptId) lines.push(`  prompt: saved prompt #${c.promptId}`);
  if (Array.isArray(opts.tools)) {
    lines.push(`  tools: ${opts.tools.length > 0 ? opts.tools.join(', ') : 'none'}`);
  }
  if (c.lastError) lines.push(`  ⚠ last error: ${c.lastError}`);
  return lines.join('\n');
}

function formatRun(r: CoworkerRun): string {
  const duration =
    r.finishedAt && r.startedAt ? ` (${Math.max(0, r.finishedAt - r.startedAt)}s)` : '';
  return `- #${r.id} ${r.status} — ${r.itemsProcessed}/${r.itemsTotal} — ${fmtTime(r.startedAt)}${duration}`;
}

/**
 * Error for the coworker routes that newer AIquila app versions add (stop, single run).
 * The app answers 404 both for an unknown coworker/run and for a route it does not have.
 */
function notFoundOr(action: string, error: unknown, notFound: string) {
  if (error instanceof Error && (error as Error & { statusCode?: number }).statusCode === 404) {
    return {
      content: [
        {
          type: 'text' as const,
          text: `${notFound}, or the installed AIquila app does not support ${action} yet (update the app).`,
        },
      ],
      isError: true,
    };
  }
  return err(action, error);
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
    'List coworker templates — built-in ones (e.g. image classification via Claude vision or Mistral Pixtral) and any your administrator published for the organization — and the available task types.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const data = await fetchAiquilaAPI<TemplatesResponse>('/coworkers/templates');
      const tpls = data.templates
        .map(
          (t) =>
            `- **${t.title}** (id: ${t.id}${t.scope === 'instance' ? ', organization' : ''})${t.description ? ` — ${t.description}` : ''}`
        )
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
  description:
    'Get a single coworker by ID, including its schedule, last run status, full options and prompt.',
  inputSchema: z.object({ id: z.number().describe('Coworker ID') }),
  handler: async (args: { id: number }) => {
    try {
      const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`);
      const parts = [formatCoworker(c)];
      if (c.options && Object.keys(c.options).length > 0) {
        parts.push(`Options:\n${JSON.stringify(c.options, null, 2)}`);
      }
      if (c.customPrompt) parts.push(`Prompt:\n${c.customPrompt}`);
      return text(parts.join('\n\n'));
    } catch (error) {
      return err('getting coworker', error);
    }
  },
};

/** Fields shared by create_coworker and update_coworker. */
const coworkerFields = {
  title: z.string().optional(),
  description: z.string().optional().describe('Free-text description'),
  provider: z
    .string()
    .optional()
    .describe(
      'Provider id: "anthropic", "mistral", "deepseek", "hetzner" or "local"; "" follows the owner setting'
    ),
  model: z.string().optional().describe("Model id to run with; omit to use the provider's default"),
  inputPath: z.string().optional().describe('Folder to process, e.g. "/Photos"'),
  outputType: z
    .string()
    .optional()
    .describe('Where results go, e.g. "system_tags" (default) or "file"'),
  outputPath: z.string().optional().describe('Output file or folder when outputType needs one'),
  cronSchedule: z.string().optional().describe('5-field cron, e.g. "0 3 * * *"'),
  maxTags: z.number().optional().describe('Max tags per image (vision:classify)'),
  recursive: z.boolean().optional().describe('Include sub-folders'),
  effort: z.string().optional().describe('Reasoning effort, e.g. "low"; "" uses the default'),
  thinking: z
    .enum(['auto', 'on', 'off'])
    .optional()
    .describe('Extended thinking for models that support it'),
  options: z
    .record(z.string(), z.unknown())
    .optional()
    .describe(
      'Task-specific options, e.g. {"targetLanguage":"de"} for docs:translate. Typed fields above take precedence'
    ),
  prompt: z
    .string()
    .optional()
    .describe('Custom prompt the coworker runs (agent coworkers, needs a recent AIquila app)'),
  promptId: z.number().optional().describe('Id of a saved prompt to run instead of `prompt`'),
  tools: z
    .array(z.string())
    .optional()
    .describe(
      'Allowlist of MCP tool names an agent coworker may call on its unattended runs; [] allows none'
    ),
  isActive: z.boolean().optional().describe('Whether the coworker is enabled'),
};

interface CoworkerFieldArgs {
  title?: string;
  description?: string;
  provider?: string;
  model?: string;
  inputPath?: string;
  outputType?: string;
  outputPath?: string;
  cronSchedule?: string;
  maxTags?: number;
  recursive?: boolean;
  effort?: string;
  thinking?: 'auto' | 'on' | 'off';
  options?: Record<string, unknown>;
  prompt?: string;
  promptId?: number;
  tools?: string[];
  isActive?: boolean;
}

/** Option changes requested by the typed fields and the generic `options` map. */
function optionChanges(args: CoworkerFieldArgs): Record<string, unknown> {
  const options: Record<string, unknown> = { ...(args.options ?? {}) };
  if (args.maxTags !== undefined) options.maxTags = args.maxTags;
  if (args.recursive !== undefined) options.recursive = args.recursive;
  if (args.effort !== undefined) options.effort = args.effort;
  if (args.thinking !== undefined) options.thinking = args.thinking;
  if (args.tools !== undefined) options.tools = args.tools;
  return options;
}

/** Map tool arguments to the snake_case body the AIquila API expects (options excluded). */
function buildBody(args: CoworkerFieldArgs): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  if (args.title !== undefined) body.title = args.title;
  if (args.description !== undefined) body.description = args.description;
  if (args.provider !== undefined) body.provider = args.provider;
  if (args.model !== undefined) body.model = args.model;
  if (args.inputPath !== undefined) {
    body.input_type = 'folder';
    body.input_path = args.inputPath;
  }
  if (args.outputType !== undefined) body.output_type = args.outputType;
  if (args.outputPath !== undefined) body.output_path = args.outputPath;
  if (args.cronSchedule !== undefined) body.cron_schedule = args.cronSchedule;
  if (args.prompt !== undefined) body.custom_prompt = args.prompt;
  if (args.promptId !== undefined) body.prompt_id = args.promptId;
  if (args.isActive !== undefined) body.is_active = args.isActive;
  return body;
}

/** Older AIquila apps drop prompt fields silently; say so rather than report success. */
function promptNotice(args: CoworkerFieldArgs, c: Coworker): string {
  const ignored =
    (args.prompt !== undefined && (c.customPrompt ?? '') !== args.prompt) ||
    (args.promptId !== undefined && c.promptId !== args.promptId);
  return ignored
    ? '\n\n⚠ The Nextcloud app ignored the prompt; agent coworkers need a newer AIquila app.'
    : '';
}

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
    'Create a coworker. Provide templateId to start from a template (built-in, e.g. "classify-images-claude", or an organization one), and/or explicit fields to configure a custom one. Fields override template defaults.',
  inputSchema: z.object({
    templateId: z.string().optional().describe('Template id (see list_coworker_templates)'),
    taskType: z.string().optional().describe('Task type id, e.g. "vision:classify"'),
    ...coworkerFields,
  }),
  handler: async (args: CoworkerFieldArgs & { templateId?: string; taskType?: string }) => {
    try {
      const body = buildBody(args);
      if (args.taskType !== undefined) body.task_type = args.taskType;
      const options = optionChanges(args);
      if (Object.keys(options).length > 0) body.options = options;

      const endpoint = args.templateId ? '/coworkers/templates' : '/coworkers';
      if (args.templateId) body.templateId = args.templateId;

      const c = await fetchAiquilaAPI<Coworker>(endpoint, { method: 'POST', body });
      return text(`Coworker created:\n${formatCoworker(c)}${promptNotice(args, c)}`);
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
    "Update a coworker's configuration (title, description, provider, input/output, schedule, prompt, tool allowlist, options). Only the given fields change; option changes are merged into the existing options.",
  inputSchema: z.object({
    id: z.number().describe('Coworker ID'),
    ...coworkerFields,
  }),
  handler: async (args: CoworkerFieldArgs & { id: number }) => {
    try {
      const body = buildBody(args);
      const changes = optionChanges(args);
      if (Object.keys(changes).length > 0) {
        // The API replaces options wholesale, so merge onto the stored set.
        const current = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`);
        body.options = { ...(current.options ?? {}), ...changes };
      }

      const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`, { method: 'PUT', body });
      return text(`Coworker updated:\n${formatCoworker(c)}${promptNotice(args, c)}`);
    } catch (error) {
      return err('updating coworker', error);
    }
  },
};

export const setCoworkerScheduleTool = {
  name: 'set_coworker_schedule',
  title: 'Set Coworker Schedule',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Change when a coworker runs. Takes a 5-field cron expression in the server's time zone and returns the next run time.",
  inputSchema: z.object({
    id: z.number().describe('Coworker ID'),
    cronSchedule: z
      .string()
      .describe('5-field cron: minute hour day-of-month month day-of-week, e.g. "30 6 * * 1-5"'),
  }),
  handler: async (args: { id: number; cronSchedule: string }) => {
    try {
      const c = await fetchAiquilaAPI<Coworker>(`/coworkers/${args.id}`, {
        method: 'PUT',
        body: { cron_schedule: args.cronSchedule },
      });
      return text(
        `Schedule for "${c.title}" set to ${c.cronSchedule} — ` +
          (c.nextRunAt
            ? `next run: ${fmtTime(c.nextRunAt)}.`
            : `no run scheduled while the coworker is ${c.isActive ? 'paused' : 'disabled'}.`)
      );
    } catch (error) {
      return err('setting coworker schedule', error);
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
  description:
    'Get recent run history (progress, status, summary) for a coworker. Use get_coworker_run for the full summary and log of one run.',
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
          formatRun(r) +
          (r.error ? ` — ⚠ ${r.error}` : r.summary ? ` — ${r.summary.split('\n')[0]}` : '')
      );
      return text(lines.join('\n'));
    } catch (error) {
      return err('getting coworker runs', error);
    }
  },
};

export const getCoworkerRunTool = {
  name: 'get_coworker_run',
  title: 'Get Coworker Run',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get one coworker run in full: status, duration, complete summary, error and its per-item log.',
  inputSchema: z.object({
    id: z.number().describe('Coworker ID'),
    runId: z.number().describe('Run ID (see get_coworker_runs)'),
  }),
  handler: async (args: { id: number; runId: number }) => {
    try {
      const r = await fetchAiquilaAPI<CoworkerRunDetail>(
        `/coworkers/${args.id}/runs/${args.runId}`
      );
      const parts = [formatRun(r)];
      if (r.summary) parts.push(`Summary:\n${r.summary}`);
      if (r.error) parts.push(`⚠ Error:\n${r.error}`);
      if (r.log && r.log.length > 0) {
        const log = r.log.map(
          (e) => `${fmtTime(e.ts)} [${e.level}]${e.item ? ` ${e.item}:` : ''} ${e.message}`
        );
        parts.push(`Log (${r.log.length} entries):\n${log.join('\n')}`);
      } else {
        parts.push('Log: no entries.');
      }
      return text(parts.join('\n\n'));
    } catch (error) {
      return notFoundOr('run detail', error, `Run ${args.runId} of coworker ${args.id} not found`);
    }
  },
};

export const stopCoworkerTool = {
  name: 'stop_coworker',
  title: 'Stop Coworker Run',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Stop a coworker's in-flight run (running or pending batch) and mark it cancelled. The schedule is untouched — use pause_coworker or disable_coworker to stop future runs.",
  inputSchema: z.object({ id: z.number().describe('Coworker ID') }),
  handler: async (args: { id: number }) => {
    try {
      const run = await fetchAiquilaAPI<CoworkerRun>(`/coworkers/${args.id}/stop`, {
        method: 'POST',
      });
      return text(
        `Run #${run.id} ${run.status} after ${run.itemsProcessed}/${run.itemsTotal} item(s).`
      );
    } catch (error) {
      return notFoundOr(
        'stopping runs',
        error,
        `Coworker ${args.id} not found or has no active run`
      );
    }
  },
};

export const coworkTools = [
  listCoworkersTool,
  listCoworkerTemplatesTool,
  getCoworkerTool,
  createCoworkerTool,
  updateCoworkerTool,
  setCoworkerScheduleTool,
  deleteCoworkerTool,
  enableCoworkerTool,
  disableCoworkerTool,
  pauseCoworkerTool,
  resumeCoworkerTool,
  runCoworkerTool,
  stopCoworkerTool,
  getCoworkerRunsTool,
  getCoworkerRunTool,
];
