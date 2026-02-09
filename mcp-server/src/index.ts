import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';

// Import system tools
import { fileSystemTools } from './tools/system/files.js';
import { statusTools } from './tools/system/status.js';
import { appsTools } from './tools/system/apps.js';
import { securityTools } from './tools/system/security.js';
import { occTools } from './tools/system/occ.js';

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

/**
 * AIquila MCP Server
 * Provides Model Context Protocol integration for Nextcloud
 *
 * Architecture:
 * - System tools: Core operations (files, status, apps, security)
 * - App tools: Nextcloud integrations (Calendar, Tasks, Cookbook, Notes, Mail, Users, Groups, Shares, AIquila)
 */

const server = new McpServer({
  name: 'aiquila',
  version: '0.1.11',
});

/**
 * Register all tools with the MCP server
 */
function registerTools() {
  // Register system tools (file operations)
  fileSystemTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register system status tools
  statusTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Calendar app tools
  calendarTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Tasks app tools
  tasksTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Cookbook app tools
  cookbookTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Notes app tools
  notesTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register AIquila internal tools
  aiquilaTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register User Management tools
  usersTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Group Management tools
  groupsTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register App Management tools
  appsTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register OCC execution tools
  occTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Security tools
  securityTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Share Management tools
  sharesTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Contacts app tools
  contactsTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });

  // Register Mail app tools
  mailTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK (known issue with complex Zod schemas)
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });
}

async function main() {
  registerTools();
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('AIquila MCP server running');
}

main().catch(console.error);
