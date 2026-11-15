// SPDX-License-Identifier: MIT

/**
 * The tools sequences reach the client through.
 *
 * - `list_sequences` and `run_sequence` are always there, so a client holding
 *   a stale tool list can still find and run any sequence.
 * - A sequence marked "show as its own tool" is also listed as `seq_<slug>`
 *   (built-in, organization) or `seq_my_<slug>` (personal), with its inputs
 *   as the input schema.
 *
 * The list is built per request as the signed-in user (MCP 2026-07-28,
 * Tools: the set "MAY vary by the authorization presented on the request",
 * and SHOULD come in a deterministic order): built-ins in their fixed order,
 * then the organization's and the user's own, each by slug.
 *
 * The caller's tool set is not known here; `configureSequences` gives it, so
 * this module needs nothing from the registry and the registry can list
 * these tools without an import cycle.
 */

import { z } from 'zod';
import type { ServerContext } from '@modelcontextprotocol/server';
import type { Tool, ToolAnnotations } from '../tools/types.js';
import { structuredResult } from '../tools/structured.js';
import { chargeToolCall } from './budget.js';
import { isSequenceTool, sequenceToolName, stepId, type Sequence } from './definition.js';
import {
  RunReportSchema,
  formatReport,
  inputSchemaFor,
  prepareInputs,
  runSequence,
  structuredReport,
} from './runner.js';
import { getEffectiveSequences, type EffectiveSequences } from './store.js';

export interface SequenceEnvironment {
  /** The caller's tools (sequence tools included or not), and whether the AIquila app is there. */
  callerTools(): Promise<{ tools: Tool[]; appAvailable: boolean; key: string }>;
  /** Every tool the server has, for the annotations of steps the caller cannot run. */
  registryTools(): Tool[];
}

let env: SequenceEnvironment | undefined;

export function configureSequences(e: SequenceEnvironment): void {
  env = e;
}

function environment(): SequenceEnvironment {
  if (!env) throw new Error('Sequences are not configured');
  return env;
}

/**
 * MCP 2026-07-28 schema, ToolAnnotations: a hint left out means
 * readOnlyHint false, destructiveHint true, idempotentHint false,
 * openWorldHint true. A step whose tool this server does not know gets those.
 */
const UNKNOWN_TOOL: ToolAnnotations = {
  readOnlyHint: false,
  destructiveHint: true,
  idempotentHint: false,
  openWorldHint: true,
};

/**
 * A sequence's hints from its steps': read-only only if every step is,
 * destructive if any (non-read-only) step is, idempotent only if every step
 * is, open-world if any is.
 */
export function deriveAnnotations(
  seq: Sequence,
  byName: ReadonlyMap<string, Tool>
): ToolAnnotations {
  const hints = seq.steps.map((s) => byName.get(s.tool)?.annotations ?? UNKNOWN_TOOL);
  const readOnlyHint = hints.every((h) => h.readOnlyHint);
  return {
    readOnlyHint,
    destructiveHint: !readOnlyHint && hints.some((h) => !h.readOnlyHint && h.destructiveHint),
    idempotentHint: hints.every((h) => h.readOnlyHint || h.idempotentHint),
    openWorldHint: hints.some((h) => h.openWorldHint),
  };
}

function plan(seq: Sequence): string {
  return seq.steps
    .map((s, i) => `${i + 1}. ${s.tool}${s.onFailure === 'continue' ? '' : ' (stops on failure)'}`)
    .join('; ');
}

const SOURCE_LABEL = {
  builtin: 'built in',
  organization: "your organization's",
  personal: 'your own',
} as const;

function describe(seq: Sequence): string {
  return (
    `${seq.description ? `${seq.description} ` : ''}` +
    `Sequence (${SOURCE_LABEL[seq.source]}): runs these steps in one call, as you: ${plan(seq)}. ` +
    'Steps whose tool you do not have are skipped. Pass dryRun: true to see the resolved plan without running it.'
  );
}

interface Loaded {
  effective: EffectiveSequences;
  tools: Map<string, Tool>;
  byName: Map<string, Tool>;
}

async function load(): Promise<Loaded> {
  const e = environment();
  const { tools, appAvailable, key } = await e.callerTools();
  const effective = await getEffectiveSequences(key, appAvailable);
  return {
    effective,
    tools: new Map(tools.filter((t) => !isSequenceTool(t.name)).map((t) => [t.name, t])),
    byName: new Map(e.registryTools().map((t) => [t.name, t])),
  };
}

function timeZone(): string | undefined {
  return process.env.TZ || undefined;
}

async function execute(
  seq: Sequence,
  loaded: Loaded,
  given: Record<string, unknown>,
  dryRun: boolean,
  ctx: ServerContext | undefined
) {
  const prepared = prepareInputs(seq, given);
  if (!prepared.ok) {
    return {
      content: [
        {
          type: 'text' as const,
          text: `Invalid inputs for ${sequenceToolName(seq)}:\n${prepared.error}`,
        },
      ],
      isError: true,
    };
  }
  const token = ctx?.mcpReq?._meta?.progressToken;
  const report = await runSequence(seq, {
    tools: loaded.tools,
    inputs: prepared.inputs,
    dryRun,
    timeZone: timeZone(),
    maxSteps: loaded.effective.policy.maxSteps,
    signal: ctx?.mcpReq?.signal,
    chargeStep: chargeToolCall,
    toolContext: ctx,
    onProgress:
      token !== undefined && ctx
        ? (progress, total, message) =>
            ctx.mcpReq.notify({
              method: 'notifications/progress',
              params: { progressToken: token, progress, total, message },
            })
        : undefined,
  });
  const result = structuredResult(RunReportSchema, structuredReport(report), formatReport(report));
  // The step texts are already in the prose; the JSON block repeats only the outline.
  return report.status === 'failed' ? { ...result, isError: true } : result;
}

const ListOutput = z.object({
  sequences: z.array(
    z.object({
      name: z.string(),
      source: z.enum(['builtin', 'organization', 'personal']),
      title: z.string(),
      description: z.string(),
      ownTool: z.boolean(),
      readOnly: z.boolean(),
      destructive: z.boolean(),
      inputs: z.array(
        z.object({
          name: z.string(),
          type: z.string(),
          required: z.boolean(),
          description: z.string(),
          default: z.unknown().optional(),
        })
      ),
      steps: z.array(z.object({ id: z.string(), tool: z.string(), available: z.boolean() })),
    })
  ),
});

export const listSequencesTool = {
  name: 'list_sequences',
  title: 'List Sequences',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the sequences you can run: predefined chains of AIquila tools that run in one call. ' +
    'Built-in ones, your organization’s, and your own. Run one with run_sequence, or with its own seq_* tool where it has one.',
  inputSchema: z.object({}),
  outputSchema: ListOutput,
  handler: async () => {
    const loaded = await load();
    const sequences = loaded.effective.sequences.map((seq) => {
      const a = deriveAnnotations(seq, loaded.byName);
      return {
        name: sequenceToolName(seq),
        source: seq.source,
        title: seq.title,
        description: seq.description,
        ownTool: seq.pinned,
        readOnly: a.readOnlyHint,
        destructive: a.destructiveHint,
        inputs: seq.inputs.map((i) => ({
          name: i.name,
          type: i.type,
          required: i.required,
          description: i.description,
          ...(i.default !== undefined ? { default: i.default } : {}),
        })),
        steps: seq.steps.map((s, n) => ({
          id: stepId(s, n),
          tool: s.tool,
          available: loaded.tools.has(s.tool),
        })),
      };
    });
    const text =
      sequences.length === 0
        ? 'No sequences.'
        : sequences
            .map(
              (s) =>
                `• ${s.name} — ${s.title} (${s.source}${s.readOnly ? ', read-only' : s.destructive ? ', destructive' : ''})` +
                (s.inputs.length
                  ? `; inputs: ${s.inputs.map((i) => `${i.name}${i.required ? '' : '?'}`).join(', ')}`
                  : '')
            )
            .join('\n');
    return structuredResult(ListOutput, { sequences }, text);
  },
};

export const runSequenceTool = {
  name: 'run_sequence',
  title: 'Run Sequence',
  // It can run any sequence, so it carries the defaults: it may change or remove data.
  annotations: { ...UNKNOWN_TOOL },
  description:
    'Run a sequence by the name list_sequences gives (seq_… or seq_my_…). Its steps run in order, as you, in this one call; ' +
    'the result reports each step. Pass dryRun: true to get the resolved plan without running anything. ' +
    'A sequence may change or delete data; list_sequences says which are read-only or destructive.',
  inputSchema: z.object({
    sequence: z.string().describe('Sequence name from list_sequences, e.g. seq_daily_briefing'),
    inputs: z
      .record(z.string(), z.unknown())
      .optional()
      .describe("The sequence's inputs, as list_sequences describes them"),
    dryRun: z.boolean().optional().describe('Resolve and validate the steps, but run none'),
  }),
  outputSchema: RunReportSchema,
  handler: async (
    args: { sequence: string; inputs?: Record<string, unknown>; dryRun?: boolean },
    ctx?: ServerContext
  ) => {
    const loaded = await load();
    const all = loaded.effective.sequences;
    const seq =
      all.find((s) => sequenceToolName(s) === args.sequence) ??
      all.find((s) => s.source !== 'personal' && s.slug === args.sequence) ??
      all.find((s) => s.source === 'personal' && s.slug === args.sequence);
    if (!seq) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `No sequence named "${args.sequence}". Use list_sequences to see the ones you have.`,
          },
        ],
        isError: true,
      };
    }
    return execute(seq, loaded, args.inputs ?? {}, args.dryRun === true, ctx);
  },
};

/** The two fixed tools, as the registry lists them. */
export const sequenceTools = [listSequencesTool, runSequenceTool];

/**
 * The caller's `seq_*` tools: every sequence of theirs marked "show as its
 * own tool", in a deterministic order. Anonymous callers get the built-ins.
 */
export async function pinnedSequenceTools(): Promise<Tool[]> {
  const loaded = await load();
  return loaded.effective.sequences
    .filter((seq) => seq.pinned)
    .map((seq) => {
      const inputs = inputSchemaFor(seq).extend({
        dryRun: z.boolean().optional().describe('Resolve and validate the steps, but run none'),
      });
      return {
        name: sequenceToolName(seq),
        title: seq.title,
        description: describe(seq),
        annotations: deriveAnnotations(seq, loaded.byName),
        inputSchema: inputs,
        outputSchema: RunReportSchema,
        handler: async (args: Record<string, unknown>, ctx?: ServerContext) => {
          const { dryRun, ...given } = args ?? {};
          return execute(seq, loaded, given, dryRun === true, ctx);
        },
      };
    });
}

/** Revalidates the caller's sequences, so a change is noticed without a request. */
export async function refreshSequences(): Promise<void> {
  await load();
}
