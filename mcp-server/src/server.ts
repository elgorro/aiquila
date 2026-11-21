// SPDX-License-Identifier: MIT

import { createRequire } from 'node:module';
import { McpServer, type CallToolResult } from '@modelcontextprotocol/server';
import { logger } from './logger.js';
import { getFilteredToolSets } from './tool-registry.js';
import { registerResources, servesResources } from './resources.js';
import { reportToolFailure } from './nc-context.js';
import { pinnedSequenceTools } from './sequences/tools.js';
import type { Tool } from './tools/types.js';

const SERVER_NAME = 'aiquila';
const _require = createRequire(import.meta.url);
export const SERVER_VERSION: string = _require('../package.json').version;

/**
 * Create a fully-configured McpServer with filtered tools registered.
 *
 * Tools are filtered based on the MCP_TOOLS env var (explicit whitelist)
 * or auto-detected from enabled Nextcloud apps at startup.
 *
 * This is the factory behind both serving entries (serveStdio, createMcpHandler),
 * which call it for every connection or request in either protocol era. It
 * must return a new instance each time: an instance serves one connection.
 */
export interface CreateServerOptions {
  /**
   * The `tools.listChanged` capability. Advertise it only where the caller can
   * deliver `notifications/tools/list_changed` (MCP 2026-07-28, Tools); the
   * SDK's default is true.
   */
  toolsListChanged?: boolean;
}

function register(server: McpServer, tool: Tool): void {
  const name = tool.name;
  server.registerTool(
    name,
    {
      title: tool.title,
      description: tool.description,
      annotations: tool.annotations,
      inputSchema: tool.inputSchema,
      ...(tool.outputSchema ? { outputSchema: tool.outputSchema } : {}),
    },
    async (...args: unknown[]) => {
      const start = Date.now();
      logger.debug({ tool: name }, '[tool] Called');
      let result: CallToolResult;
      try {
        result = await (tool.handler as (...a: unknown[]) => Promise<CallToolResult>)(...args);
      } catch (err) {
        reportToolFailure();
        throw err;
      }
      const ms = Date.now() - start;
      if (result?.isError) {
        reportToolFailure();
        const first = result.content?.[0];
        const errorText = first?.type === 'text' ? first.text : 'unknown error';
        logger.warn({ tool: name, error: errorText, ms }, '[tool] Error response');
      } else {
        logger.debug({ tool: name, ms }, '[tool] Completed');
      }
      return result;
    }
  );
}

export async function createServer(options: CreateServerOptions = {}): Promise<McpServer> {
  const toolSets = await getFilteredToolSets();
  const toolNames = new Set(toolSets.flat().map((tool) => tool.name));

  const server = new McpServer(
    {
      name: SERVER_NAME,
      version: SERVER_VERSION,
    },
    {
      capabilities: {
        ...(options.toolsListChanged === undefined
          ? {}
          : { tools: { listChanged: options.toolsListChanged } }),
        // Declared here so that `listChanged` is false: the SDK would default it
        // to true, and the resource templates never change (MCP 2026-07-28,
        // Resources › Capabilities).
        ...(servesResources(toolNames) ? { resources: { listChanged: false } } : {}),
      },
    }
  );

  for (const toolSet of toolSets) {
    for (const tool of toolSet) register(server, tool);
  }
  registerResources(server, toolNames);

  // The caller's sequences shown as their own tools, after the fixed ones and
  // in a fixed order. Only where the sequences category is in the tool set.
  if (toolSets.some((ts) => ts.some((t) => t.name === 'run_sequence'))) {
    let pinned: Tool[] = [];
    try {
      pinned = await pinnedSequenceTools();
    } catch (err) {
      logger.warn({ err: err instanceof Error ? err.message : String(err) }, '[seq] Not listed');
    }
    for (const tool of pinned) register(server, tool);
  }

  return server;
}
