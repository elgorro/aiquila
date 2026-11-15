// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { z } from 'zod';
import {
  SequenceDefinitionSchema,
  sequenceToolName,
  type Sequence,
} from '../sequences/definition.js';
import {
  DependencyError,
  TemplateError,
  checkTemplates,
  resolveArguments,
  type TemplateScope,
} from '../sequences/template.js';
import { formatReport, prepareInputs, runSequence } from '../sequences/runner.js';
import { BUILTIN_SEQUENCES } from '../sequences/builtin.js';
import { deriveAnnotations, pinnedSequenceTools, runSequenceTool } from '../sequences/tools.js';
import {
  _expireSequenceCache,
  _resetSequenceCache,
  effectiveFrom,
  getEffectiveSequences,
  onSequencesChange,
} from '../sequences/store.js';
import { TOOL_REGISTRY, _resetCache } from '../tool-registry.js';
import type { Tool } from '../tools/types.js';
import { runAs } from '../nc-context.js';

const NOW = new Date('2026-11-15T09:30:12.345Z');

function scope(over: Partial<TemplateScope> = {}): TemplateScope {
  return {
    inputs: {},
    declaredInputs: new Set(),
    steps: new Map(),
    now: NOW,
    timeZone: 'UTC',
    ...over,
  };
}

function seq(def: Record<string, unknown>, source: Sequence['source'] = 'personal'): Sequence {
  return { ...SequenceDefinitionSchema.parse({ slug: 'test', title: 'Test', ...def }), source };
}

function tool(name: string, handler: Tool['handler'], schema = z.object({}).passthrough()): Tool {
  return {
    name,
    title: name,
    description: name,
    annotations: {
      readOnlyHint: true,
      destructiveHint: false,
      idempotentHint: true,
      openWorldHint: false,
    },
    inputSchema: schema,
    handler,
  };
}

const ok = (text: string, structuredContent?: unknown) => ({
  content: [{ type: 'text', text }],
  ...(structuredContent ? { structuredContent } : {}),
});

describe('templates', () => {
  it('substitutes inputs, keeping the type of a lone placeholder', () => {
    const s = scope({ inputs: { n: 5, list: ['a'] }, declaredInputs: new Set(['n', 'list']) });
    expect(
      resolveArguments({ a: '{{inputs.n}}', b: '{{ inputs.list }}', c: 'n={{inputs.n}}' }, s).args
    ).toEqual({ a: 5, b: ['a'], c: 'n=5' });
  });

  it('gives RFC 3339 dates: a full-date for today, a UTC date-time for now', () => {
    const { args } = resolveArguments(
      { t: '{{today}}', w: '{{today+1w}}', y: '{{today-1d}}', n: '{{now}}', h: '{{now+2h}}' },
      scope()
    );
    expect(args).toEqual({
      t: '2026-11-15',
      w: '2026-11-22',
      y: '2026-11-14',
      n: '2026-11-15T09:30:12Z',
      h: '2026-11-15T11:30:12Z',
    });
  });

  it('takes today in the given time zone', () => {
    const late = new Date('2026-11-15T23:30:00Z');
    expect(
      resolveArguments({ t: '{{today}}' }, scope({ now: late, timeZone: 'Europe/Berlin' })).args.t
    ).toBe('2026-11-16');
  });

  it('reads the text and structured result of earlier steps', () => {
    const steps = new Map([['find', { text: 'found it', result: { items: [{ id: 42 }] } }]]);
    expect(
      resolveArguments(
        { id: '{{steps.find.result.items.0.id}}', note: '{{steps.find.text}}!' },
        scope({ steps })
      ).args
    ).toEqual({ id: 42, note: 'found it!' });
  });

  it('is a single pass: substituted text is never expanded again', () => {
    const steps = new Map([['a', { text: '{{inputs.secret}} {{now}}' }]]);
    const s = scope({ steps, inputs: { secret: 'x' }, declaredInputs: new Set(['secret']) });
    expect(resolveArguments({ v: 'got {{steps.a.text}}' }, s).args.v).toBe(
      'got {{inputs.secret}} {{now}}'
    );
  });

  it('leaves braces that name no variable alone', () => {
    expect(resolveArguments({ v: 'Hi {{ name }} and {{x y}}' }, scope()).args.v).toBe(
      'Hi {{ name }} and {{x y}}'
    );
  });

  it('drops an argument whose optional input was not given', () => {
    const s = scope({ declaredInputs: new Set(['opt']) });
    expect(resolveArguments({ a: '{{inputs.opt}}', b: 'x{{inputs.opt}}y' }, s)).toEqual({
      args: { b: 'xy' },
      omitted: ['a'],
    });
  });

  it('refuses unknown inputs, bad offsets and failed dependencies', () => {
    expect(() => resolveArguments({ a: '{{inputs.nope}}' }, scope())).toThrow(TemplateError);
    expect(() => resolveArguments({ a: '{{today+2h}}' }, scope())).toThrow(TemplateError);
    expect(() =>
      resolveArguments({ a: '{{inputs.x+1d}}' }, scope({ declaredInputs: new Set(['x']) }))
    ).toThrow(TemplateError);
    const failed = new Map([['a', null]]);
    expect(() => resolveArguments({ a: '{{steps.a.text}}' }, scope({ steps: failed }))).toThrow(
      DependencyError
    );
  });

  it('checks a definition statically: known inputs, earlier steps only', () => {
    const def = SequenceDefinitionSchema.parse({
      slug: 'x',
      title: 'X',
      inputs: [{ name: 'a', type: 'string' }],
      steps: [
        { id: 'one', tool: 't', arguments: { v: '{{inputs.a}} {{steps.two.text}}' } },
        {
          id: 'two',
          tool: 't',
          arguments: { v: '{{steps.one.text}} {{inputs.b}} {{steps.one.foo}}' },
        },
      ],
    });
    expect(checkTemplates(def)).toEqual([
      'Step one: {{steps.two.text}} does not name an earlier step',
      'Step two: unknown input in {{inputs.b}}',
      'Step two: write steps.one.text or steps.one.result.<path>',
    ]);
  });
});

describe('definitions', () => {
  it('names tools seq_<slug> and seq_my_<slug> within the MCP tool-name charset', () => {
    for (const s of BUILTIN_SEQUENCES) {
      const name = sequenceToolName(s);
      expect(name).toMatch(/^seq_[a-z0-9_-]+$/);
      expect(name.length).toBeLessThanOrEqual(128);
    }
    expect(sequenceToolName({ slug: 'x', source: 'personal' })).toBe('seq_my_x');
  });

  it('rejects a step on another server, a reserved input and duplicate step ids', () => {
    const base = { slug: 'x', title: 'X' };
    expect(
      SequenceDefinitionSchema.safeParse({ ...base, steps: [{ tool: 't', server: 'other' }] })
        .success
    ).toBe(false);
    expect(
      SequenceDefinitionSchema.safeParse({
        ...base,
        inputs: [{ name: 'dryRun', type: 'boolean' }],
        steps: [{ tool: 't' }],
      }).success
    ).toBe(false);
    expect(
      SequenceDefinitionSchema.safeParse({
        ...base,
        steps: [
          { id: 'a', tool: 't' },
          { id: 'a', tool: 't' },
        ],
      }).success
    ).toBe(false);
  });

  it('every built-in names registry tools only, with valid templates and arguments', () => {
    const byName = new Map(TOOL_REGISTRY.flatMap((e) => e.tools).map((t) => [t.name, t]));
    for (const s of BUILTIN_SEQUENCES) {
      expect(checkTemplates(s), s.slug).toEqual([]);
      for (const step of s.steps) {
        const t = byName.get(step.tool);
        expect(t, `${s.slug}: ${step.tool}`).toBeDefined();
        const keys = Object.keys((t!.inputSchema as z.ZodObject).shape);
        for (const arg of Object.keys(step.arguments))
          expect(keys, `${s.slug}.${step.tool}`).toContain(arg);
      }
    }
  });
});

describe('runner', () => {
  it('runs steps in order and feeds outputs forward', async () => {
    const calls: unknown[] = [];
    const tools = new Map([
      ['a', tool('a', async () => ok('first', { id: 7 }))],
      ['b', tool('b', async (args: unknown) => (calls.push(args), ok('second')))],
    ]);
    const s = seq({
      steps: [
        { id: 'x', tool: 'a' },
        { tool: 'b', arguments: { id: '{{steps.x.result.id}}' } },
      ],
    });
    const report = await runSequence(s, { tools, inputs: {} });
    expect(report.status).toBe('completed');
    expect(report.steps.map((r) => [r.id, r.status])).toEqual([
      ['x', 'ok'],
      ['step2', 'ok'],
    ]);
    expect(calls).toEqual([{ id: 7 }]);
  });

  it('stops on a failure unless the step says continue', async () => {
    const fail = tool('f', async () => ({
      content: [{ type: 'text', text: 'boom' }],
      isError: true,
    }));
    const tools = new Map([
      ['f', fail],
      ['a', tool('a', async () => ok('fine'))],
    ]);
    const stop = await runSequence(seq({ steps: [{ tool: 'f' }, { tool: 'a' }] }), {
      tools,
      inputs: {},
    });
    expect(stop.status).toBe('failed');
    expect(stop.steps.map((r) => r.status)).toEqual(['failed', 'not_run']);
    expect(stop.steps[0].reason).toBe('boom');

    const cont = await runSequence(
      seq({ steps: [{ tool: 'f', onFailure: 'continue' }, { tool: 'a' }] }),
      {
        tools,
        inputs: {},
      }
    );
    expect(cont.steps.map((r) => r.status)).toEqual(['failed', 'ok']);
  });

  it('skips a tool the caller does not have, and steps that depend on it', async () => {
    const tools = new Map([['a', tool('a', async () => ok('fine'))]]);
    const s = seq({
      steps: [
        { id: 'm', tool: 'missing' },
        { tool: 'a', arguments: { v: '{{steps.m.text}}' } },
        { tool: 'a' },
      ],
    });
    const report = await runSequence(s, { tools, inputs: {} });
    expect(report.steps.map((r) => [r.status, r.reason])).toEqual([
      ['skipped', 'tool not available to you'],
      ['skipped', 'needs the output of step m, which did not succeed'],
      ['ok', null],
    ]);
    expect(report.status).toBe('completed');
  });

  it('never runs a sequence as a step (no nesting)', async () => {
    const tools = new Map([['run_sequence', tool('run_sequence', vi.fn())]]);
    const report = await runSequence(seq({ steps: [{ tool: 'run_sequence' }] }), {
      tools,
      inputs: {},
    });
    expect(report.steps[0]).toMatchObject({
      status: 'failed',
      reason: 'a sequence cannot run another sequence',
    });
    expect(tools.get('run_sequence')!.handler).not.toHaveBeenCalled();
  });

  it("parses arguments with the tool's own schema", async () => {
    const handler = vi.fn(async () => ok('x'));
    const tools = new Map([['n', tool('n', handler, z.object({ count: z.number().max(3) }))]]);
    const report = await runSequence(seq({ steps: [{ tool: 'n', arguments: { count: 9 } }] }), {
      tools,
      inputs: {},
    });
    expect(report.steps[0].status).toBe('failed');
    expect(report.steps[0].reason).toMatch(/^invalid arguments/);
    expect(handler).not.toHaveBeenCalled();
  });

  it('skips a step whose required argument comes from an input left out', async () => {
    const tools = new Map([['n', tool('n', vi.fn(), z.object({ accountId: z.number() }))]]);
    const s = seq({
      inputs: [{ name: 'acct', type: 'integer' }],
      steps: [{ tool: 'n', arguments: { accountId: '{{inputs.acct}}' } }],
    });
    const report = await runSequence(s, { tools, inputs: {} });
    expect(report.steps[0]).toMatchObject({ status: 'skipped' });
    expect(report.steps[0].reason).toContain('accountId');
  });

  it('a dry run resolves and validates without calling anything', async () => {
    const handler = vi.fn();
    const tools = new Map([['a', tool('a', handler)]]);
    const s = seq({
      steps: [
        { id: 'x', tool: 'a', arguments: { d: '{{today}}' } },
        { tool: 'a', arguments: { v: '{{steps.x.text}}' } },
      ],
    });
    const report = await runSequence(s, {
      tools,
      inputs: {},
      dryRun: true,
      now: NOW,
      timeZone: 'UTC',
    });
    expect(report.status).toBe('planned');
    expect(report.steps.map((r) => r.status)).toEqual(['planned', 'planned']);
    expect(report.steps[0].arguments).toEqual({ d: '2026-11-15' });
    expect(handler).not.toHaveBeenCalled();
  });

  it('stops when the run is out of time', async () => {
    const slow = tool('slow', () => new Promise(() => {}));
    const tools = new Map([
      ['slow', slow],
      ['a', tool('a', async () => ok('x'))],
    ]);
    const s = seq({ steps: [{ tool: 'slow', onFailure: 'continue' }, { tool: 'a' }] });
    const report = await runSequence(s, { tools, inputs: {}, runMs: 20 });
    expect(report.steps.map((r) => r.status)).toEqual(['failed', 'not_run']);
    expect(report.steps[0].reason).toMatch(/ran out of time/);
  });

  it('refuses more steps than the policy allows', async () => {
    const tools = new Map([
      [
        'a',
        tool(
          'a',
          vi.fn(async () => ok('x'))
        ),
      ],
    ]);
    const report = await runSequence(seq({ steps: [{ tool: 'a' }, { tool: 'a' }] }), {
      tools,
      inputs: {},
      maxSteps: 1,
    });
    expect(report.steps.every((r) => r.status === 'not_run')).toBe(true);
  });

  it('charges every step after the first, and stops when refused', async () => {
    const tools = new Map([['a', tool('a', async () => ok('x'))]]);
    let budget = 1;
    const report = await runSequence(
      seq({ steps: [{ tool: 'a' }, { tool: 'a' }, { tool: 'a' }] }),
      {
        tools,
        inputs: {},
        chargeStep: () => budget-- > 0,
      }
    );
    expect(report.steps.map((r) => r.status)).toEqual(['ok', 'ok', 'failed']);
    expect(report.steps[2].reason).toBe('rate limit exceeded');
  });

  it('sends increasing progress, once per step, and nothing after', async () => {
    const tools = new Map([['a', tool('a', async () => ok('x'))]]);
    const progress: number[] = [];
    await runSequence(seq({ steps: [{ tool: 'a' }, { tool: 'missing' }, { tool: 'a' }] }), {
      tools,
      inputs: {},
      onProgress: (done) => {
        progress.push(done);
      },
    });
    expect(progress).toEqual([1, 2, 3]);
  });

  it('keeps the identity of the caller in every step', async () => {
    const { currentIdentity } = await import('../nc-context.js');
    const seen: unknown[] = [];
    const tools = new Map([['a', tool('a', async () => (seen.push(currentIdentity()), ok('x')))]]);
    const identity = { kind: 'service' as const };
    await runAs(identity, () =>
      runSequence(seq({ steps: [{ tool: 'a' }, { tool: 'a' }] }), { tools, inputs: {} })
    );
    expect(seen).toEqual([identity, identity]);
  });

  it('validates inputs and applies defaults', () => {
    const s = seq({
      inputs: [
        { name: 'cal', type: 'string', default: 'personal' },
        { name: 'n', type: 'integer', required: true },
      ],
      steps: [{ tool: 'a' }],
    });
    expect(prepareInputs(s, { n: 2 })).toEqual({ ok: true, inputs: { cal: 'personal', n: 2 } });
    expect(prepareInputs(s, {}).ok).toBe(false);
    expect(prepareInputs(s, { n: 1.5 }).ok).toBe(false);
  });

  it('the report names every step', async () => {
    const tools = new Map([['a', tool('a', async () => ok('hello'))]]);
    const text = formatReport(
      await runSequence(seq({ steps: [{ tool: 'a' }] }), { tools, inputs: {} })
    );
    expect(text).toContain('Sequence "Test" (seq_my_test): completed');
    expect(text).toContain('## 1. step1: a — ok');
    expect(text).toContain('hello');
  });
});

describe('annotations', () => {
  const byName = new Map(TOOL_REGISTRY.flatMap((e) => e.tools).map((t) => [t.name, t]));

  it('read-only only if every step is', () => {
    const daily = BUILTIN_SEQUENCES.find((s) => s.slug === 'daily_briefing')!;
    expect(deriveAnnotations(daily, byName)).toEqual({
      readOnlyHint: true,
      destructiveHint: false,
      idempotentHint: true,
      openWorldHint: false,
    });
  });

  it('destructive if any step is', () => {
    const ooo = BUILTIN_SEQUENCES.find((s) => s.slug === 'out_of_office_on')!;
    expect(deriveAnnotations(ooo, byName)).toMatchObject({
      readOnlyHint: false,
      destructiveHint: true,
    });
  });

  it('an unknown tool counts with the MCP defaults', () => {
    expect(deriveAnnotations(seq({ steps: [{ tool: 'nope' }] }), byName)).toEqual({
      readOnlyHint: false,
      destructiveHint: true,
      idempotentHint: false,
      openWorldHint: true,
    });
  });
});

describe('store', () => {
  const definition = { steps: [{ tool: 'list_notifications' }] };
  let saved: NodeJS.ProcessEnv;

  beforeEach(() => {
    saved = process.env;
    process.env = {
      ...saved,
      NEXTCLOUD_URL: 'https://nc.test',
      NEXTCLOUD_USER: 'u',
      NEXTCLOUD_PASSWORD: 'p',
    };
    delete process.env.MCP_TOOLS;
    _resetSequenceCache();
  });
  afterEach(() => {
    process.env = saved;
    vi.unstubAllGlobals();
  });

  it('merges built-ins, organization and personal sequences, dropping invalid rows', () => {
    const eff = effectiveFrom({
      sequences: [
        { scope: 'user', slug: 'mine', title: 'Mine', definition, pinned: true },
        { scope: 'instance', slug: 'org', title: 'Org', definition },
        { scope: 'instance', slug: 'daily_briefing', title: 'Shadow', definition },
        { scope: 'instance', slug: 'my_x', title: 'Squats a personal name', definition },
        {
          scope: 'user',
          slug: 'bad',
          title: 'Bad',
          definition: { steps: [{ tool: 't', arguments: { a: '{{inputs.x}}' } }] },
        },
      ],
      disabledBuiltins: ['back_at_work'],
    });
    const names = eff.sequences.map(sequenceToolName);
    expect(names).toContain('seq_my_mine');
    expect(names).toContain('seq_org');
    expect(names).not.toContain('seq_back_at_work');
    expect(names.filter((n) => n === 'seq_daily_briefing')).toHaveLength(1);
    expect(names).not.toContain('seq_my_x');
    expect(names).not.toContain('seq_my_bad');
  });

  it('applies the policy: no personal sequences, or at most so many pinned', () => {
    const rows = ['a', 'b', 'c'].map((slug) => ({
      scope: 'user',
      slug,
      title: slug,
      definition,
      pinned: true,
    }));
    expect(
      effectiveFrom({ sequences: rows, policy: { allowPersonal: false } }).sequences.some(
        (s) => s.source === 'personal'
      )
    ).toBe(false);
    const eff = effectiveFrom({ sequences: rows, policy: { maxPinned: 1, maxPerUser: 2 } });
    const mine = eff.sequences.filter((s) => s.source === 'personal');
    expect(mine.map((s) => [s.slug, s.pinned])).toEqual([
      ['a', true],
      ['b', false],
    ]);
  });

  it('revalidates with If-None-Match and reports a changed ETag', async () => {
    const responses = [
      new Response(JSON.stringify({ sequences: [] }), { status: 200, headers: { etag: '"v1"' } }),
      new Response(null, { status: 304, headers: { etag: '"v1"' } }),
      new Response(
        JSON.stringify({ sequences: [{ scope: 'user', slug: 'n', title: 'N', definition }] }),
        {
          status: 200,
          headers: { etag: '"v2"' },
        }
      ),
    ];
    const fetchMock = vi.fn(async () => responses.shift()!);
    vi.stubGlobal('fetch', fetchMock);
    const changed: string[] = [];
    const stop = onSequencesChange((k) => changed.push(k));

    await getEffectiveSequences('env', true);
    _expireSequenceCache();
    await getEffectiveSequences('env', true);
    expect(changed).toEqual([]);
    _expireSequenceCache();
    const eff = await getEffectiveSequences('env', true);
    stop();

    const headers = fetchMock.mock.calls.map(
      (c) => (c as unknown[])[1] as { headers: Record<string, string> }
    );
    expect(headers[0].headers['If-None-Match']).toBeUndefined();
    expect(headers[1].headers['If-None-Match']).toBe('"v1"');
    expect(changed).toEqual(['env']);
    expect(eff.sequences.map(sequenceToolName)).toContain('seq_my_n');
  });

  it('keeps built-ins when the app is missing or Nextcloud fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('', { status: 404 }))
    );
    const eff = await getEffectiveSequences('env', true);
    expect(eff.sequences).toHaveLength(BUILTIN_SEQUENCES.length);
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('down');
      })
    );
    _expireSequenceCache();
    expect((await getEffectiveSequences('env', true)).sequences).toHaveLength(
      BUILTIN_SEQUENCES.length
    );
  });

  it('never calls Nextcloud for an anonymous caller or without the app', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    await getEffectiveSequences('anonymous', true);
    await getEffectiveSequences('env', false);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('sequence tools', () => {
  let saved: NodeJS.ProcessEnv;
  beforeEach(() => {
    saved = process.env;
    process.env = {
      ...saved,
      NEXTCLOUD_URL: 'https://nc.test',
      NEXTCLOUD_USER: 'u',
      NEXTCLOUD_PASSWORD: 'p',
      MCP_TOOLS: 'sequences,status',
    };
    _resetCache();
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('', { status: 404 }))
    );
  });
  afterEach(() => {
    process.env = saved;
    vi.unstubAllGlobals();
    _resetCache();
  });

  it('MCP_TOOLS bounds the steps: a tool outside it is skipped', async () => {
    const result = await runSequenceTool.handler({ sequence: 'seq_daily_briefing', dryRun: true });
    const steps = result.structuredContent.steps as { tool: string; status: string }[];
    expect(steps.find((s) => s.tool === 'get_local_time')?.status).toBe('planned');
    expect(steps.find((s) => s.tool === 'list_events')?.status).toBe('skipped');
  });

  it('an unknown sequence is a tool error', async () => {
    const result = await runSequenceTool.handler({ sequence: 'seq_nope' });
    expect(result.isError).toBe(true);
  });

  it('pinned sequences become tools with their inputs, and dryRun', async () => {
    const tools = await pinnedSequenceTools();
    const find = tools.find((t) => t.name === 'seq_find_meeting_slot')!;
    const shape = (find.inputSchema as z.ZodObject).shape;
    expect(Object.keys(shape).sort()).toEqual([
      'attendees',
      'dryRun',
      'end',
      'minCapacity',
      'start',
    ]);
    expect(find.annotations.readOnlyHint).toBe(true);
    expect(find.description).toContain('get_free_busy');
  });
});
