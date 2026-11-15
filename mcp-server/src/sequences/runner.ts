// SPDX-License-Identifier: MIT

/**
 * Runs a sequence: each step in order, as the calling user, within one
 * `tools/call`.
 *
 * - Only tools in the caller's own tool set run (the list `tools/list` gives
 *   them, after app detection and MCP_TOOLS). A step whose tool is not in it
 *   is skipped and reported. Sequence tools never run as a step.
 * - Each step's arguments are resolved from the templates (`template.ts`),
 *   then parsed by that tool's own input schema, exactly as a direct call
 *   would be (MCP 2026-07-28, Tools › Security: servers MUST validate inputs).
 * - A step that fails stops the run unless it says `onFailure: continue`.
 * - The run as a whole, the number of steps and the text kept per step are
 *   capped (LIMITS), and each step after the first counts against the
 *   caller's tool-call rate limit.
 * - Progress goes out after each step when the client sent a progress token
 *   (MCP 2026-07-28, Progress: values increase, and stop once it completes).
 */

import { z } from 'zod';
import { logger } from '../logger.js';
import { reportToolFailure } from '../nc-context.js';
import type { Tool } from '../tools/types.js';
import { LIMITS, isSequenceTool, sequenceToolName, stepId, type Sequence } from './definition.js';
import {
  DependencyError,
  TemplateError,
  resolveArguments,
  type StepOutput,
  type TemplateScope,
} from './template.js';

export type StepStatus = 'ok' | 'failed' | 'skipped' | 'not_run' | 'planned';

export interface StepReport {
  index: number;
  id: string;
  tool: string;
  status: StepStatus;
  reason: string | null;
  /** The resolved arguments; in a dry run, or when a step's arguments were invalid. */
  arguments: Record<string, unknown> | null;
  ms: number | null;
  /** The text the step returned; in the report text only, not in structuredContent. */
  text?: string;
}

export interface RunReport {
  sequence: string;
  title: string;
  status: 'completed' | 'failed' | 'planned';
  steps: StepReport[];
}

export const RunReportSchema = z.object({
  sequence: z.string(),
  title: z.string(),
  status: z.enum(['completed', 'failed', 'planned']),
  steps: z.array(
    z.object({
      index: z.number().int(),
      id: z.string(),
      tool: z.string(),
      status: z.enum(['ok', 'failed', 'skipped', 'not_run', 'planned']),
      reason: z.string().nullable(),
      arguments: z.record(z.string(), z.unknown()).nullable(),
      ms: z.number().nullable(),
    })
  ),
});

export interface RunOptions {
  /** The caller's tools by name. */
  tools: ReadonlyMap<string, Tool>;
  inputs: Record<string, unknown>;
  dryRun?: boolean;
  now?: Date;
  timeZone?: string;
  signal?: AbortSignal;
  /** Policy cap on steps; never above LIMITS.steps. */
  maxSteps?: number;
  runMs?: number;
  /** Called after each step, with an increasing count. */
  onProgress?: (done: number, total: number, message: string) => Promise<void> | void;
  /** Charges one tool call to the caller's rate limit; false when refused. */
  chargeStep?: () => boolean;
  /** Second argument passed to each tool handler (the SDK's request context). */
  toolContext?: unknown;
}

/** Zod schema of a sequence's inputs; also the input schema of its own tool. */
export function inputSchemaFor(seq: Sequence): z.ZodObject {
  const shape: Record<string, z.ZodType> = {};
  for (const input of seq.inputs) {
    let t: z.ZodType;
    switch (input.type) {
      case 'integer':
        t = z.number().int();
        break;
      case 'number':
        t = z.number();
        break;
      case 'boolean':
        t = z.boolean();
        break;
      case 'array':
        t = z.array(z.unknown());
        break;
      default:
        t = z.string();
    }
    let desc = input.description;
    if (input.default !== undefined)
      desc += `${desc ? ' ' : ''}(default: ${JSON.stringify(input.default)})`;
    if (desc) t = t.describe(desc);
    shape[input.name] = input.required ? t : t.optional();
  }
  return z.object(shape);
}

class StepTimeout extends Error {}

function textOf(result: { content?: { type: string; text?: string }[] }): string {
  const text = (result.content ?? [])
    .filter((c) => c.type === 'text' && typeof c.text === 'string')
    .map((c) => c.text as string)
    .join('\n');
  return text.length > LIMITS.stepTextChars
    ? `${text.slice(0, LIMITS.stepTextChars)}\n… (truncated)`
    : text;
}

/** Paths of the arguments a schema needs but `args` lacks. */
function missingRequired(issues: z.core.$ZodIssue[], args: Record<string, unknown>): Set<string> {
  const out = new Set<string>();
  for (const i of issues) {
    if (i.code !== 'invalid_type' || i.path.length === 0) continue;
    let v: unknown = args;
    for (const k of i.path)
      v = v && typeof v === 'object' ? (v as Record<PropertyKey, unknown>)[k] : undefined;
    if (v === undefined) out.add(i.path.join('.'));
  }
  return out;
}

async function withTimeout<T>(p: Promise<T>, ms: number, signal?: AbortSignal): Promise<T> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  let onAbort: (() => void) | undefined;
  try {
    return await Promise.race([
      p,
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new StepTimeout()), Math.max(0, ms));
        if (signal) {
          onAbort = () => reject(new Error('cancelled'));
          signal.addEventListener('abort', onAbort, { once: true });
        }
      }),
    ]);
  } finally {
    clearTimeout(timer);
    if (signal && onAbort) signal.removeEventListener('abort', onAbort);
  }
}

/**
 * Validates and applies defaults to the caller's inputs.
 * Returns the inputs, or the reason they are invalid.
 */
export function prepareInputs(
  seq: Sequence,
  given: Record<string, unknown>
): { ok: true; inputs: Record<string, unknown> } | { ok: false; error: string } {
  const parsed = inputSchemaFor(seq).safeParse(given ?? {});
  if (!parsed.success) return { ok: false, error: z.prettifyError(parsed.error) };
  const inputs: Record<string, unknown> = { ...parsed.data };
  for (const input of seq.inputs) {
    if (inputs[input.name] === undefined) {
      delete inputs[input.name];
      if (input.default !== undefined) inputs[input.name] = input.default;
    }
  }
  return { ok: true, inputs };
}

export async function runSequence(seq: Sequence, opts: RunOptions): Promise<RunReport> {
  const name = sequenceToolName(seq);
  const maxSteps = Math.min(opts.maxSteps ?? LIMITS.steps, LIMITS.steps);
  const deadline = Date.now() + (opts.runMs ?? LIMITS.runMs);
  const steps: StepReport[] = [];
  const outputs = new Map<string, StepOutput | null>();
  const scope: TemplateScope = {
    inputs: opts.inputs,
    declaredInputs: new Set(seq.inputs.map((i) => i.name)),
    steps: outputs,
    now: opts.now ?? new Date(),
    timeZone: opts.timeZone,
  };
  const total = seq.steps.length;
  let stopped: string | null = seq.steps.length > maxSteps ? `more than ${maxSteps} steps` : null;
  let ran = 0;

  for (let i = 0; i < total; i++) {
    const step = seq.steps[i];
    const id = stepId(step, i);
    const report: StepReport = {
      index: i + 1,
      id,
      tool: step.tool,
      status: 'skipped',
      reason: null,
      arguments: null,
      ms: null,
    };
    steps.push(report);
    const finish = async () => {
      outputs.set(id, outputs.get(id) ?? null);
      if (!opts.dryRun && opts.onProgress) {
        try {
          await opts.onProgress(i + 1, total, `${id} (${step.tool}): ${report.status}`);
        } catch {
          // A client that went away does not stop the run.
        }
      }
    };

    if (stopped) {
      report.status = 'not_run';
      report.reason = stopped;
      outputs.set(id, null);
      continue;
    }

    const tool = opts.tools.get(step.tool);
    if (isSequenceTool(step.tool)) {
      report.status = 'failed';
      report.reason = 'a sequence cannot run another sequence';
    } else if (step.server) {
      report.status = 'failed';
      report.reason = 'steps on other MCP servers are not supported';
    } else if (!tool) {
      report.reason = 'tool not available to you';
    }
    if (report.status === 'failed' || !tool) {
      if (report.status === 'failed' && step.onFailure === 'stop') stopped = `step ${id} failed`;
      await finish();
      continue;
    }

    let args: Record<string, unknown>;
    let omitted: string[];
    try {
      ({ args, omitted } = resolveArguments(step.arguments, scope));
    } catch (err) {
      if (err instanceof DependencyError) {
        report.status = opts.dryRun ? 'planned' : 'skipped';
        report.reason = opts.dryRun
          ? `uses the output of step ${err.step}; resolved when it runs`
          : err.message;
        if (opts.dryRun) report.arguments = step.arguments;
      } else {
        report.status = 'failed';
        report.reason = err instanceof TemplateError ? err.message : String(err);
        if (step.onFailure === 'stop') stopped = `step ${id} failed`;
      }
      await finish();
      continue;
    }

    const parsed = (tool.inputSchema as z.ZodType).safeParse(args);
    if (!parsed.success) {
      const missing = missingRequired(parsed.error.issues, args);
      const needs = omitted.filter((p) => missing.has(p));
      report.arguments = args;
      if (needs.length > 0) {
        report.status = 'skipped';
        report.reason = `needs ${needs.join(', ')}, from an input that was not given`;
      } else {
        report.status = 'failed';
        report.reason = `invalid arguments: ${z.prettifyError(parsed.error)}`;
        if (step.onFailure === 'stop') stopped = `step ${id} failed`;
      }
      await finish();
      continue;
    }

    if (opts.dryRun) {
      report.status = 'planned';
      report.arguments = parsed.data as Record<string, unknown>;
      await finish();
      continue;
    }

    if (opts.signal?.aborted) {
      report.status = 'not_run';
      report.reason = 'cancelled';
      stopped = 'cancelled';
      await finish();
      continue;
    }
    if (ran > 0 && opts.chargeStep && !opts.chargeStep()) {
      report.status = 'failed';
      report.reason = 'rate limit exceeded';
      stopped = 'rate limit exceeded';
      await finish();
      continue;
    }

    ran++;
    const start = Date.now();
    try {
      const result = (await withTimeout(
        Promise.resolve(
          (tool.handler as (a: unknown, c: unknown) => unknown)(parsed.data, opts.toolContext)
        ),
        deadline - start,
        opts.signal
      )) as {
        isError?: boolean;
        content?: { type: string; text?: string }[];
        structuredContent?: unknown;
      };
      report.ms = Date.now() - start;
      const text = textOf(result ?? {});
      if (result?.isError) {
        reportToolFailure();
        report.status = 'failed';
        report.reason = text.split('\n')[0].slice(0, 500) || 'the tool reported an error';
      } else {
        report.status = 'ok';
        outputs.set(id, { text, result: result?.structuredContent });
      }
    } catch (err) {
      report.ms = Date.now() - start;
      report.status = 'failed';
      if (err instanceof StepTimeout) {
        report.reason = `the sequence ran out of time (${Math.round((opts.runMs ?? LIMITS.runMs) / 1000)} s)`;
        stopped = 'out of time';
      } else {
        reportToolFailure();
        report.reason = err instanceof Error ? err.message : String(err);
      }
    }
    if (report.status === 'failed' && step.onFailure === 'stop' && !stopped) {
      stopped = `step ${id} failed`;
    }
    logger.debug(
      { sequence: name, step: id, tool: step.tool, status: report.status },
      '[seq] Step'
    );
    report.text = outputs.get(id)?.text;
    await finish();
  }

  const failed = steps.some((s) => s.status === 'failed');
  return {
    sequence: name,
    title: seq.title,
    status: opts.dryRun ? (failed ? 'failed' : 'planned') : failed ? 'failed' : 'completed',
    steps,
  };
}

/** The report as text for the model, capped at LIMITS.reportChars. */
export function formatReport(report: RunReport): string {
  const counts = new Map<StepStatus, number>();
  for (const s of report.steps) counts.set(s.status, (counts.get(s.status) ?? 0) + 1);
  const summary = [...counts].map(([k, v]) => `${v} ${k.replace('_', ' ')}`).join(', ');
  const parts = [`Sequence "${report.title}" (${report.sequence}): ${report.status} — ${summary}.`];
  for (const s of report.steps) {
    parts.push(`\n## ${s.index}. ${s.id}: ${s.tool} — ${s.status.replace('_', ' ')}`);
    if (s.reason) parts.push(s.reason);
    if (s.arguments && (s.status === 'planned' || s.status === 'failed')) {
      parts.push(`Arguments: ${JSON.stringify(s.arguments)}`);
    }
    if (s.text) parts.push(s.text);
  }
  const text = parts.join('\n');
  return text.length > LIMITS.reportChars
    ? `${text.slice(0, LIMITS.reportChars)}\n… (report truncated)`
    : text;
}

/** The report without the step texts, for `structuredContent`. */
export function structuredReport(report: RunReport): RunReport {
  return {
    ...report,
    steps: report.steps.map(({ index, id, tool, status, reason, arguments: a, ms }) => ({
      index,
      id,
      tool,
      status,
      reason,
      arguments: a,
      ms,
    })),
  };
}
