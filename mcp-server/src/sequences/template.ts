// SPDX-License-Identifier: MIT

/**
 * Argument templates for sequence steps.
 *
 * A placeholder is `{{ <variable> }}`:
 *
 * - `inputs.<name>`: an input of the sequence.
 * - `today`, `today+7d`, `today-1w`: the local date, RFC 3339 `full-date`
 *   (`2026-11-15`), shifted by days (`d`) or weeks (`w`).
 * - `now`, `now+2h`, `now-30m`: the current time, RFC 3339 `date-time` in UTC
 *   (`2026-11-15T09:30:00Z`, §5.6), shifted by minutes, hours, days or weeks.
 * - `steps.<id>.text`: the text an earlier step returned.
 * - `steps.<id>.result.<path>`: a field of an earlier step's
 *   `structuredContent`, for the tools that declare an output schema; `<path>`
 *   is dot-separated, with numbers indexing arrays (`result.items.0.id`).
 *
 * An argument that is a single placeholder takes the variable's value with its
 * type (a number stays a number, a list a list). A placeholder inside longer
 * text is replaced by the value as text. Substitution is a single pass over
 * the template: a substituted value is never scanned for placeholders again,
 * so text a tool returned cannot inject a variable into a later step.
 *
 * `{{ ... }}` that does not name one of these roots is left as it is, so text
 * that happens to contain braces needs no escaping.
 */

import { stepId, type SequenceDefinition } from './definition.js';

const PLACEHOLDER = /\{\{\s*([^{}]*?)\s*\}\}/g;
const EXPRESSION = /^(inputs|steps|today|now)((?:\.[A-Za-z0-9_-]+)*)(?:([+-])(\d{1,4})([mhdw]))?$/;
const ROOT = /^(inputs|steps|today|now)\b/;

/** A template that can never resolve: an unknown input, a later step, a bad offset. */
export class TemplateError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'TemplateError';
  }
}

/** A step the template refers to did not succeed, so this one cannot run. */
export class DependencyError extends Error {
  constructor(readonly step: string) {
    super(`needs the output of step ${step}, which did not succeed`);
    this.name = 'DependencyError';
  }
}

export interface StepOutput {
  text: string;
  /** `structuredContent`, when the tool returned one. */
  result?: unknown;
}

export interface TemplateScope {
  /** Inputs the caller gave, defaults applied. An absent optional input is missing here. */
  inputs: Record<string, unknown>;
  declaredInputs: ReadonlySet<string>;
  /** Earlier steps by id: their output, or null when they did not succeed. */
  steps: ReadonlyMap<string, StepOutput | null>;
  now: Date;
  /** IANA zone for `today`; the process zone when unset. */
  timeZone?: string;
}

/** Marks a value whose input the caller left out. */
const ABSENT = Symbol('absent');

interface Parsed {
  root: 'inputs' | 'steps' | 'today' | 'now';
  path: string[];
  offset?: { sign: 1 | -1; amount: number; unit: 'm' | 'h' | 'd' | 'w' };
}

function parse(expr: string): Parsed | null {
  const m = EXPRESSION.exec(expr);
  if (!m) {
    if (ROOT.test(expr)) throw new TemplateError(`Invalid placeholder {{${expr}}}`);
    return null;
  }
  const path = m[2] ? m[2].slice(1).split('.') : [];
  const offset = m[3]
    ? {
        sign: (m[3] === '+' ? 1 : -1) as 1 | -1,
        amount: Number(m[4]),
        unit: m[5] as 'm' | 'h' | 'd' | 'w',
      }
    : undefined;
  return { root: m[1] as Parsed['root'], path, offset };
}

const UNIT_MS = { m: 60_000, h: 3_600_000, d: 86_400_000, w: 604_800_000 } as const;

function localDate(now: Date, timeZone: string | undefined, days: number): string {
  // en-CA formats as YYYY-MM-DD.
  const today = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
  if (days === 0) return today;
  const [y, mo, d] = today.split('-').map(Number);
  return new Date(Date.UTC(y, mo - 1, d + days)).toISOString().slice(0, 10);
}

function evaluate(p: Parsed, expr: string, scope: TemplateScope): unknown {
  if (p.root === 'today' || p.root === 'now') {
    if (p.path.length > 0) throw new TemplateError(`Invalid placeholder {{${expr}}}`);
    const off = p.offset;
    if (p.root === 'today') {
      if (off && off.unit !== 'd' && off.unit !== 'w') {
        throw new TemplateError(`{{${expr}}}: today shifts by days (d) or weeks (w)`);
      }
      const days = off ? off.sign * off.amount * (off.unit === 'w' ? 7 : 1) : 0;
      return localDate(scope.now, scope.timeZone, days);
    }
    const ms = off ? off.sign * off.amount * UNIT_MS[off.unit] : 0;
    return new Date(scope.now.getTime() + ms).toISOString().replace(/\.\d{3}Z$/, 'Z');
  }
  if (p.offset) throw new TemplateError(`{{${expr}}}: only today and now take an offset`);

  if (p.root === 'inputs') {
    if (p.path.length !== 1) throw new TemplateError(`{{${expr}}}: write inputs.<name>`);
    const name = p.path[0];
    if (!scope.declaredInputs.has(name)) throw new TemplateError(`Unknown input ${name}`);
    return Object.prototype.hasOwnProperty.call(scope.inputs, name) ? scope.inputs[name] : ABSENT;
  }

  // steps.<id>.text | steps.<id>.result[.path]
  const [id, field, ...rest] = p.path;
  if (!id || (field !== 'text' && field !== 'result') || (field === 'text' && rest.length > 0)) {
    throw new TemplateError(`{{${expr}}}: write steps.<id>.text or steps.<id>.result.<path>`);
  }
  if (!scope.steps.has(id)) throw new TemplateError(`{{${expr}}}: no earlier step ${id}`);
  const out = scope.steps.get(id);
  if (!out) throw new DependencyError(id);
  if (field === 'text') return out.text;
  let value: unknown = out.result;
  for (const key of rest) {
    if (value === null || typeof value !== 'object') {
      throw new TemplateError(`{{${expr}}}: step ${id} returned no field ${rest.join('.')}`);
    }
    value =
      Array.isArray(value) && /^\d+$/.test(key)
        ? value[Number(key)]
        : (value as Record<string, unknown>)[key];
    if (value === undefined) {
      throw new TemplateError(`{{${expr}}}: step ${id} returned no field ${rest.join('.')}`);
    }
  }
  if (value === undefined) throw new TemplateError(`{{${expr}}}: step ${id} returned no result`);
  return value;
}

function asText(value: unknown): string {
  if (value === ABSENT || value === null || value === undefined) return '';
  return typeof value === 'string' ? value : JSON.stringify(value);
}

/** Resolves one string. A lone placeholder keeps its value's type. */
function resolveString(template: string, scope: TemplateScope): unknown {
  const whole = /^\{\{\s*([^{}]*?)\s*\}\}$/.exec(template);
  if (whole) {
    const p = parse(whole[1]);
    if (p) return evaluate(p, whole[1], scope);
  }
  return template.replace(PLACEHOLDER, (match, expr: string) => {
    const p = parse(expr);
    return p ? asText(evaluate(p, expr, scope)) : match;
  });
}

function resolveValue(
  value: unknown,
  scope: TemplateScope,
  path: string,
  missing: string[]
): unknown {
  if (typeof value === 'string') {
    const out = resolveString(value, scope);
    if (out === ABSENT) missing.push(path);
    return out;
  }
  if (Array.isArray(value)) {
    return value
      .map((v, i) => resolveValue(v, scope, `${path}.${i}`, missing))
      .filter((v) => v !== ABSENT);
  }
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value)) {
      const r = resolveValue(v, scope, path ? `${path}.${k}` : k, missing);
      if (r !== ABSENT) out[k] = r;
    }
    return out;
  }
  return value;
}

/**
 * Resolves a step's arguments. An argument that is only a placeholder for an
 * input the caller left out (and that has no default) is dropped, and named
 * in `omitted`, so the tool's own default applies.
 *
 * @throws TemplateError, DependencyError
 */
export function resolveArguments(
  args: Record<string, unknown>,
  scope: TemplateScope
): { args: Record<string, unknown>; omitted: string[] } {
  const omitted: string[] = [];
  const resolved = resolveValue(args, scope, '', omitted) as Record<string, unknown>;
  return { args: resolved, omitted };
}

/** Every placeholder expression in `value`, recursively. */
function expressions(value: unknown, out: string[] = []): string[] {
  if (typeof value === 'string') {
    for (const m of value.matchAll(PLACEHOLDER)) out.push(m[1]);
  } else if (Array.isArray(value)) {
    for (const v of value) expressions(v, out);
  } else if (value && typeof value === 'object') {
    for (const v of Object.values(value)) expressions(v, out);
  }
  return out;
}

/**
 * Static check of every template in a definition: known inputs only, and
 * steps that refer to an earlier step only. Returns the problems found.
 */
export function checkTemplates(def: SequenceDefinition): string[] {
  const problems: string[] = [];
  const declared = new Set(def.inputs.map((i) => i.name));
  const earlier = new Set<string>();
  def.steps.forEach((step, n) => {
    const id = stepId(step, n);
    for (const expr of expressions(step.arguments)) {
      let p: Parsed | null;
      try {
        p = parse(expr);
      } catch (err) {
        problems.push(`Step ${id}: ${(err as Error).message}`);
        continue;
      }
      if (!p) continue;
      if ((p.root === 'inputs' || p.root === 'steps') && p.offset) {
        problems.push(`Step ${id}: only today and now take an offset`);
      } else if (p.root === 'inputs') {
        if (p.path.length !== 1 || !declared.has(p.path[0])) {
          problems.push(`Step ${id}: unknown input in {{${expr}}}`);
        }
      } else if (p.root === 'steps') {
        const [ref, field] = p.path;
        if (!ref || !earlier.has(ref)) {
          problems.push(`Step ${id}: {{${expr}}} does not name an earlier step`);
        } else if (field !== 'text' && field !== 'result') {
          problems.push(`Step ${id}: write steps.${ref}.text or steps.${ref}.result.<path>`);
        }
      } else if (p.path.length > 0) {
        problems.push(`Step ${id}: invalid placeholder {{${expr}}}`);
      } else if (p.root === 'today' && p.offset && p.offset.unit !== 'd' && p.offset.unit !== 'w') {
        problems.push(`Step ${id}: today shifts by days (d) or weeks (w)`);
      }
    }
    earlier.add(id);
  });
  return problems;
}
