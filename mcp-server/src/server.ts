// SPDX-License-Identifier: MIT

import { createRequire } from 'node:module';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { logger } from './logger.js';
import { getFilteredToolSets } from './tool-registry.js';

const SERVER_NAME = 'aiquila';
const _require = createRequire(import.meta.url);
export const SERVER_VERSION: string = _require('../package.json').version;

/**
 * Create a fully-configured McpServer with filtered tools registered.
 *
 * Tools are filtered based on the MCP_TOOLS env var (explicit whitelist)
 * or auto-detected from enabled Nextcloud apps at startup.
 *
 * Returns a new instance each time because the MCP SDK Protocol
 * class throws if connect() is called more than once.
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
          description: tool.description,
          inputSchema: tool.inputSchema as any,
        },
        async (...args: unknown[]) => {
          const start = Date.now();
          logger.debug({ tool: name }, '[tool] Called');
          const result = await (tool.handler as (...a: unknown[]) => Promise<any>)(...args);
          const ms = Date.now() - start;
          if (result?.isError) {
            const errorText = result.content?.[0]?.text ?? 'unknown error';
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
