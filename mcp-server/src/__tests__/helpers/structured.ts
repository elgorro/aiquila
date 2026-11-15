// SPDX-License-Identifier: MIT

import { expect } from 'vitest';
import type { z } from 'zod';

/**
 * Asserts a successful result of a tool with an outputSchema: MCP 2026-07-28,
 * Tools › Output Schema (structured results MUST conform) and Structured
 * Content (the serialized JSON SHOULD also be in a text block).
 */
export function expectStructured(
  result: { content: { type: string; text?: string }[]; structuredContent?: unknown },
  tool: { outputSchema?: z.ZodObject }
): Record<string, unknown> {
  expect(tool.outputSchema).toBeDefined();
  expect(result).not.toHaveProperty('isError');
  const parsed = tool.outputSchema!.safeParse(result.structuredContent);
  expect(parsed.success, parsed.error?.message).toBe(true);
  const last = result.content[result.content.length - 1];
  expect(last.type).toBe('text');
  expect(JSON.parse(last.text!)).toEqual(result.structuredContent);
  return result.structuredContent as Record<string, unknown>;
}
