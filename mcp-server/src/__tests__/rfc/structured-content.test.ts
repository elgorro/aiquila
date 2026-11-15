// SPDX-License-Identifier: MIT

// Structured tool results over the real app. MCP 2026-07-28, Tools ›
// Output Schema: "Servers MUST provide structured results that conform to
// this schema"; Structured Content: a tool that returns structured content
// SHOULD also return the serialized JSON in a text block.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  LEGACY,
  type Stack,
} from './harness.js';

let s: Stack;
let token: string;
beforeAll(async () => {
  s = await startStack({ MCP_TOOLS: 'files' });
  token = (await obtainTokens(s, (await register(s)).client_id)).access_token;
});
afterAll(() => s.close());

describe('MCP 2026-07-28 Tools: outputSchema and structuredContent', () => {
  it('Tool: tools/list advertises an object outputSchema for create_folder only where declared', async () => {
    const body = await rpcResult(await mcp(s, modernBody(1, 'tools/list'), { token }));
    const tools: { name: string; outputSchema?: Record<string, unknown> }[] = body.result.tools;
    const folder = tools.find((t) => t.name === 'create_folder');
    expect(folder?.outputSchema).toMatchObject({
      type: 'object',
      properties: { path: { type: 'string' } },
      required: ['path'],
    });
    expect(tools.find((t) => t.name === 'list_files')?.outputSchema).toBeUndefined();
  });

  it('Output Schema: a 2026-07-28 tools/call returns conforming structuredContent and its JSON', async () => {
    const res = await mcp(
      s,
      modernBody(2, 'tools/call', { name: 'create_folder', arguments: { path: '/Reports' } }),
      { token }
    );
    const { result } = await rpcResult(res);
    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual({ path: '/Reports' });
    const texts = result.content.filter((c: { type: string }) => c.type === 'text');
    expect(JSON.parse(texts[texts.length - 1].text)).toEqual(result.structuredContent);
  });

  it('the 2025-era leg returns the same structuredContent', async () => {
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
        'mcp-protocol-version': LEGACY,
        authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: 3,
        method: 'tools/call',
        params: { name: 'create_folder', arguments: { path: '/Old' } },
      }),
    });
    const { result } = await rpcResult(res);
    expect(result.structuredContent).toEqual({ path: '/Old' });
  });
});
