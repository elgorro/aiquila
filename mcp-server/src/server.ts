import { createRequire } from 'node:module';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { logger } from './logger.js';

// Import system tools
import { fileSystemTools } from './tools/system/files.js';
import { statusTools } from './tools/system/status.js';
import { appsTools } from './tools/system/apps.js';
import { securityTools } from './tools/system/security.js';
import { occTools } from './tools/system/occ.js';
import { tagsTools } from './tools/system/tags.js';

// Import app-specific tools
import { tasksTools } from './tools/apps/tasks.js';
import { calendarTools } from './tools/apps/calendar.js';
import { cookbookTools } from './tools/apps/cookbook.js';
import { notesTools } from './tools/apps/notes.js';
import { aiquilaTools } from './tools/apps/aiquila.js';
import { usersTools } from './tools/apps/users.js';
import { groupsTools } from './tools/apps/groups.js';
import { sharesTools } from './tools/apps/shares.js';
import { contactsTools } from './tools/apps/contacts.js';
import { mailTools } from './tools/apps/mail.js';
import { bookmarksTools } from './tools/apps/bookmarks.js';
import { mapsTools } from './tools/apps/maps.js';

const SERVER_NAME = 'aiquila';
const _require = createRequire(import.meta.url);
export const SERVER_VERSION: string = _require('../package.json').version;

const allToolSets = [
  fileSystemTools,
  statusTools,
  calendarTools,
  tasksTools,
  cookbookTools,
  notesTools,
  aiquilaTools,
  usersTools,
  groupsTools,
  appsTools,
  occTools,
  securityTools,
  sharesTools,
  contactsTools,
  mailTools,
  bookmarksTools,
  mapsTools,
  tagsTools,
];

/**
 * Create a fully-configured McpServer with all tools registered.
 *
 * Returns a new instance each time because the MCP SDK Protocol
 * class throws if connect() is called more than once.
 */
export function createServer(): McpServer {
  const server = new McpServer({
    name: SERVER_NAME,
    version: SERVER_VERSION,
  });

  for (const toolSet of allToolSets) {
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
