// SPDX-License-Identifier: MIT

/**
 * The shape of a sequence: a named, parameterised, ordered list of steps,
 * each a call to one AIquila MCP tool.
 *
 * Definitions come from three sources: built into this server, written by an
 * admin in the Nextcloud app (organization), or written by a user for
 * themselves (personal). Nextcloud validates what it stores against
 * `sequence-catalog.json`; the server parses every definition through
 * `SequenceDefinitionSchema` again before it lists or runs it, so a row that
 * slipped past (or predates) the app's checks is dropped, not run.
 */

import { z } from 'zod';

export type SequenceSource = 'builtin' | 'organization' | 'personal';

/** Hard limits. The Nextcloud admin policy may lower `steps`, never raise it. */
export const LIMITS = {
  /** Steps per sequence. */
  steps: 20,
  /** Inputs per sequence. */
  inputs: 20,
  /** Wall-clock budget for a whole run, in ms. */
  runMs: 120_000,
  /** Characters of one step's text kept for templates and the report. */
  stepTextChars: 20_000,
  /** Characters of the whole report. */
  reportChars: 100_000,
  /** Length of a slug. `seq_my_` + 48 stays far below the 128 a tool name may have. */
  slugChars: 48,
  titleChars: 255,
  descriptionChars: 2000,
} as const;

/**
 * Tool names are `seq_<slug>` (built-in, organization) and `seq_my_<slug>`
 * (personal): MCP 2026-07-28, Tools › Tool Names — 1–128 characters of
 * `[A-Za-z0-9_.-]`, unique within the server.
 */
export const SLUG_RE = /^[a-z0-9][a-z0-9_-]{0,47}$/;
/** Inputs and step ids are template identifiers. */
export const IDENT_RE = /^[A-Za-z_][A-Za-z0-9_]{0,31}$/;
/** Input names a sequence tool reserves for itself. */
export const RESERVED_INPUTS: ReadonlySet<string> = new Set(['dryRun']);

export const INPUT_TYPES = ['string', 'integer', 'number', 'boolean', 'array'] as const;

const JsonValue: z.ZodType<unknown> = z.lazy(() =>
  z.union([
    z.string(),
    z.number(),
    z.boolean(),
    z.null(),
    z.array(JsonValue),
    z.record(z.string(), JsonValue),
  ])
);

export const SequenceInputSchema = z
  .object({
    name: z.string().regex(IDENT_RE),
    type: z.enum(INPUT_TYPES),
    required: z.boolean().default(false),
    description: z.string().max(500).default(''),
    /** Used when the caller leaves the input out. Never itself a template. */
    default: JsonValue.optional(),
  })
  .refine((i) => !RESERVED_INPUTS.has(i.name), { message: 'Input name is reserved' });

export const SequenceStepSchema = z.object({
  /** Name later steps use in `{{steps.<id>.text}}`. Defaults to `step<n>`. */
  id: z.string().regex(IDENT_RE).optional(),
  tool: z.string().min(1).max(128),
  /**
   * Reserved for steps on external MCP servers. This version runs AIquila
   * tools only, so anything but empty is rejected.
   */
  server: z.literal('').optional(),
  arguments: z.record(z.string(), JsonValue).default({}),
  onFailure: z.enum(['stop', 'continue']).default('stop'),
});

export const SequenceDefinitionSchema = z
  .object({
    slug: z.string().regex(SLUG_RE),
    title: z.string().min(1).max(LIMITS.titleChars),
    description: z.string().max(LIMITS.descriptionChars).default(''),
    inputs: z.array(SequenceInputSchema).max(LIMITS.inputs).default([]),
    steps: z.array(SequenceStepSchema).min(1).max(LIMITS.steps),
    /** "Show as its own tool": also listed as `seq_<slug>` / `seq_my_<slug>`. */
    pinned: z.boolean().default(false),
  })
  .superRefine((d, ctx) => {
    const inputs = new Set<string>();
    for (const i of d.inputs) {
      if (inputs.has(i.name))
        ctx.addIssue({ code: 'custom', message: `Duplicate input ${i.name}` });
      inputs.add(i.name);
    }
    const ids = new Set<string>();
    d.steps.forEach((s, n) => {
      const id = s.id ?? `step${n + 1}`;
      if (ids.has(id)) ctx.addIssue({ code: 'custom', message: `Duplicate step id ${id}` });
      ids.add(id);
    });
  });

export type SequenceInput = z.output<typeof SequenceInputSchema>;
export type SequenceStep = z.output<typeof SequenceStepSchema>;
export type SequenceDefinition = z.output<typeof SequenceDefinitionSchema>;

/** A definition with where it came from. */
export interface Sequence extends SequenceDefinition {
  source: SequenceSource;
}

/** The tool name a sequence is listed and run under. */
export function sequenceToolName(seq: Pick<Sequence, 'slug' | 'source'>): string {
  return seq.source === 'personal' ? `seq_my_${seq.slug}` : `seq_${seq.slug}`;
}

/** The id a step is referred to by. */
export function stepId(step: SequenceStep, index: number): string {
  return step.id ?? `step${index + 1}`;
}

/** The tools that run sequences; never a step themselves (no nesting). */
export function isSequenceTool(name: string): boolean {
  return name === 'list_sequences' || name === 'run_sequence' || name.startsWith('seq_');
}
