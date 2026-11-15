// SPDX-License-Identifier: MIT

// Every tool that declares an outputSchema. MCP 2026-07-28, Tools › Tool:
// outputSchema is JSON Schema (2020-12 by default); the 2025-era revisions
// the legacy leg serves require an object root.

import { describe, it, expect } from 'vitest';
import { z } from 'zod';
import { TOOL_REGISTRY } from '../tool-registry.js';
import { structuredResult, str, int, bool } from '../tools/structured.js';

const withOutput = TOOL_REGISTRY.flatMap((e) => e.tools).filter((t) => t.outputSchema);

describe('tools with an outputSchema', () => {
  // Plus the two sequence tools, whose result is a per-step report.
  it('are the ones that create or look up an object and return its identifier', () => {
    expect(withOutput.map((t) => t.name).sort()).toEqual([
      'create_event',
      'create_folder',
      'create_note',
      'create_project',
      'create_project_from_template',
      'create_share',
      'create_task',
      'get_event',
      'get_file_info',
      'get_note',
      'get_project',
      'get_share',
      'list_sequences',
      'run_sequence',
    ]);
  });

  it.each(withOutput.map((t) => [t.name, t] as const))(
    '%s: the output schema is a JSON Schema object',
    (_name, tool) => {
      const schema = z.toJSONSchema(tool.outputSchema!, { io: 'output' }) as Record<
        string,
        unknown
      >;
      expect(schema.type).toBe('object');
      expect(schema.additionalProperties).toBe(false);
      expect(Array.isArray(schema.required) && schema.required.length > 0).toBe(true);
    }
  );
});

describe('structuredResult', () => {
  const schema = z.object({ id: z.number(), name: z.string().nullable() });

  it('puts the prose first and the serialized structured content last', () => {
    const r = structuredResult(schema, { id: 1, name: 'x' }, 'Created #1');
    expect(r.content).toEqual([
      { type: 'text', text: 'Created #1' },
      { type: 'text', text: '{"id":1,"name":"x"}' },
    ]);
    expect(r.structuredContent).toEqual({ id: 1, name: 'x' });
  });

  it('without prose, sends the JSON alone', () => {
    expect(structuredResult(schema, { id: 1, name: null }).content).toHaveLength(1);
  });

  it('drops fields the schema does not know', () => {
    const r = structuredResult(schema, { id: 1, name: 'x', extra: true } as never);
    expect(r.structuredContent).toEqual({ id: 1, name: 'x' });
  });

  it('coerces leniently and maps anything else to null', () => {
    expect(str('a')).toBe('a');
    expect(str(1)).toBeNull();
    expect(int(3)).toBe(3);
    expect(int('42')).toBe(42);
    expect(int('4.2')).toBeNull();
    expect(int(undefined)).toBeNull();
    expect(bool(true)).toBe(true);
    expect(bool(1)).toBe(true);
    expect(bool('yes')).toBeNull();
  });
});
