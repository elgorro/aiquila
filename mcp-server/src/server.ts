// SPDX-License-Identifier: MIT

import { createRequire } from 'node:module';
import { McpServer, type CallToolResult } from '@modelcontextprotocol/server';
import { logger } from './logger.js';
import { getFilteredToolSets } from './tool-registry.js';
import { reportToolFailure } from './nc-context.js';

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
export async function createServer(): Promise<McpServer> {
  const server = new McpServer({
    name: SERVER_NAME,
    version: SERVER_VERSION,
  });

  const toolSets = await getFilteredToolSets();

  for (const toolSet of toolSets) {
    for (const tool of toolSet) {
      const name = tool.name;
      server.registerTool(
        name,
        {
          title: tool.title,
          description: tool.description,
          annotations: tool.annotations,
          inputSchema: tool.inputSchema,
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
  }

  return server;
}
