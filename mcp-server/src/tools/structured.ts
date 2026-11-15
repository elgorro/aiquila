// SPDX-License-Identifier: MIT

/**
 * Structured tool results (MCP 2026-07-28, Tools › Structured Content and
 * Output Schema).
 *
 * A tool that declares an `outputSchema` returns its result data in
 * `structuredContent`, and servers MUST make that conform to the schema. The
 * value is parsed through the schema here, so a field the upstream API adds
 * later is dropped rather than sent against an `additionalProperties: false`
 * schema. For backwards compatibility the serialized JSON also goes in a
 * text block (SHOULD), after the tool's usual prose so models keep reading
 * what they read before.
 *
 * Output schemas are always objects at the root: the 2025-era revisions
 * require it, and the SDK would otherwise wrap the value for those clients.
 *
 * Only the identifiers a caller needs are required. Other fields are
 * nullable and filled through the coercers below, so a field an upstream
 * version leaves out or types differently becomes null instead of failing a
 * call that used to succeed.
 */

import { z } from 'zod';

export interface StructuredResult<T> {
  [key: string]: unknown;
  content: { type: 'text'; text: string }[];
  structuredContent: T;
}

export function structuredResult<S extends z.ZodObject>(
  schema: S,
  data: z.input<S>,
  text?: string
): StructuredResult<z.output<S>> {
  const value = schema.parse(data);
  const json = JSON.stringify(value);
  return {
    content:
      text === undefined
        ? [{ type: 'text', text: json }]
        : [
            { type: 'text', text },
            { type: 'text', text: json },
          ],
    structuredContent: value,
  };
}

/** The value if it is a string, else null. */
export function str(v: unknown): string | null {
  return typeof v === 'string' ? v : null;
}

/** The value as an integer (numeric strings included), else null. */
export function int(v: unknown): number | null {
  const n = typeof v === 'string' && v.trim() !== '' ? Number(v) : v;
  return typeof n === 'number' && Number.isInteger(n) ? n : null;
}

/** The value if it is a boolean (or 0/1), else null. */
export function bool(v: unknown): boolean | null {
  if (typeof v === 'boolean') return v;
  if (v === 0 || v === 1) return v === 1;
  return null;
}
