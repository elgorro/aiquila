import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { createClient, WebDAVClient } from 'webdav';

// Configuration from environment
const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || '';
const NEXTCLOUD_USER = process.env.NEXTCLOUD_USER || '';
const NEXTCLOUD_PASSWORD = process.env.NEXTCLOUD_PASSWORD || '';

let webdavClient: WebDAVClient | null = null;

function getClient(): WebDAVClient {
  if (!webdavClient) {
    if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
      throw new Error(
        'Missing Nextcloud credentials. Set NEXTCLOUD_URL, NEXTCLOUD_USER, NEXTCLOUD_PASSWORD'
      );
    }
    webdavClient = createClient(`${NEXTCLOUD_URL}/remote.php/dav/files/${NEXTCLOUD_USER}`, {
      username: NEXTCLOUD_USER,
      password: NEXTCLOUD_PASSWORD,
    });
  }
  return webdavClient;
}

const server = new McpServer({
  name: 'aiquila',
  version: '0.1.0',
});

// Tool: List files in a directory
server.tool(
  'list_files',
  'List files and folders in a Nextcloud directory',
  {
    path: z.string(),
  },
  async ({ path }) => {
    const client = getClient();
    const items = await client.getDirectoryContents(path || '/');
    const listing = Array.isArray(items) ? items : items.data;
    const formatted = listing
      .map((item: any) => `${item.type === 'directory' ? '📁' : '📄'} ${item.basename}`)
      .join('\n');
    return { content: [{ type: 'text', text: formatted || 'Empty directory' }] };
  }
);

// Tool: Read a file
server.tool(
  'read_file',
  'Read contents of a file from Nextcloud',
  { path: z.string().describe('File path to read') },
  async ({ path }) => {
    const client = getClient();
    const content = await client.getFileContents(path, { format: 'text' });
    return { content: [{ type: 'text', text: content as string }] };
  }
);

// Tool: Create or update a file
server.tool(
  'write_file',
  'Create or update a file in Nextcloud',
  {
    path: z.string().describe('File path to write'),
    content: z.string().describe('Content to write'),
  },
  async ({ path, content }) => {
    const client = getClient();
    await client.putFileContents(path, content);
    return { content: [{ type: 'text', text: `File written: ${path}` }] };
  }
);

// Tool: Create a directory
server.tool(
  'create_folder',
  'Create a folder in Nextcloud',
  { path: z.string().describe('Folder path to create') },
  async ({ path }) => {
    const client = getClient();
    await client.createDirectory(path);
    return { content: [{ type: 'text', text: `Folder created: ${path}` }] };
  }
);

// Tool: Delete a file or folder
server.tool(
  'delete',
  'Delete a file or folder from Nextcloud',
  { path: z.string().describe('Path to delete') },
  async ({ path }) => {
    const client = getClient();
    await client.deleteFile(path);
    return { content: [{ type: 'text', text: `Deleted: ${path}` }] };
  }
);

// ============ TASKS API (CalDAV) ============

async function fetchCalDAV(
  endpoint: string,
  method: string = 'GET',
  body?: string
): Promise<string> {
  const url = `${NEXTCLOUD_URL}/remote.php/dav${endpoint}`;
  const response = await fetch(url, {
    method,
    headers: {
      Authorization:
        'Basic ' + Buffer.from(`${NEXTCLOUD_USER}:${NEXTCLOUD_PASSWORD}`).toString('base64'),
      'Content-Type': 'application/xml; charset=utf-8',
      Depth: '1',
    },
    body,
  });
  return response.text();
}

// Tool: List task lists (calendars with VTODO support)
server.tool('list_task_lists', 'List all task lists in Nextcloud Tasks', {}, async () => {
  const xml = await fetchCalDAV(
    `/calendars/${NEXTCLOUD_USER}/`,
    'PROPFIND',
    `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop>
</d:propfind>`
  );
  // Extract display names from response
  const names = [...xml.matchAll(/<d:displayname>([^<]+)<\/d:displayname>/g)].map((m) => m[1]);
  return {
    content: [{ type: 'text', text: names.length ? names.join('\n') : 'No task lists found' }],
  };
});

// Tool: Create a task
server.tool(
  'create_task',
  'Create a new task in Nextcloud Tasks',
  {
    list: z.string().describe("Task list name (e.g., 'personal')"),
    title: z.string().describe('Task title'),
    description: z.string().optional().describe('Task description'),
    due: z.string().optional().describe('Due date in YYYYMMDD format'),
  },
  async ({ list, title, description, due }) => {
    const uid = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    let vtodo = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//AIquila//MCP//EN
BEGIN:VTODO
UID:${uid}
DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '').split('.')[0]}Z
SUMMARY:${title}`;
    if (description) vtodo += `\nDESCRIPTION:${description}`;
    if (due) vtodo += `\nDUE;VALUE=DATE:${due}`;
    vtodo += `\nEND:VTODO\nEND:VCALENDAR`;

    await fetchCalDAV(`/calendars/${NEXTCLOUD_USER}/${list}/${uid}.ics`, 'PUT', vtodo);
    return { content: [{ type: 'text', text: `Task created: ${title}` }] };
  }
);

// ============ COOKBOOK (Recipe files) ============

// Tool: Add a recipe to Cookbook (creates markdown file in Recipes folder)
server.tool(
  'add_recipe',
  'Add a recipe to Nextcloud Cookbook (creates a recipe file)',
  {
    name: z.string().describe('Recipe name'),
    ingredients: z.string().describe('Ingredients list'),
    instructions: z.string().describe('Cooking instructions'),
    servings: z.string().optional().describe('Number of servings'),
    prepTime: z.string().optional().describe('Preparation time'),
  },
  async ({ name, ingredients, instructions, servings, prepTime }) => {
    const client = getClient();
    const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-');

    let content = `# ${name}\n\n`;
    if (servings) content += `**Servings:** ${servings}\n`;
    if (prepTime) content += `**Prep Time:** ${prepTime}\n`;
    content += `\n## Ingredients\n\n${ingredients}\n\n## Instructions\n\n${instructions}`;

    // Try to create Recipes folder if it doesn't exist
    try {
      await client.createDirectory('/Recipes');
    } catch {
      /* ignore if exists */
    }
    await client.putFileContents(`/Recipes/${slug}.md`, content);
    return { content: [{ type: 'text', text: `Recipe saved: /Recipes/${slug}.md` }] };
  }
);

// Tool: Create a note
server.tool(
  'create_note',
  'Create a note in Nextcloud Notes folder',
  {
    title: z.string().describe('Note title'),
    content: z.string().describe('Note content'),
  },
  async ({ title, content }) => {
    const client = getClient();
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    try {
      await client.createDirectory('/Notes');
    } catch {
      /* ignore if exists */
    }
    await client.putFileContents(`/Notes/${slug}.md`, `# ${title}\n\n${content}`);
    return { content: [{ type: 'text', text: `Note saved: /Notes/${slug}.md` }] };
  }
);

// ============ AIQUILA OCC COMMANDS ============

async function runOCC(command: string): Promise<string> {
  const response = await fetch(`${NEXTCLOUD_URL}/ocs/v2.php/apps/serverinfo/api/v1/info`, {
    headers: {
      Authorization:
        'Basic ' + Buffer.from(`${NEXTCLOUD_USER}:${NEXTCLOUD_PASSWORD}`).toString('base64'),
      'OCS-APIRequest': 'true',
    },
  });

  // Since we can't execute OCC directly via API, we'll use WebDAV to create a marker file
  // and document the command for manual execution
  throw new Error('OCC commands must be run on the Nextcloud server. Use SSH or docker exec.');
}

// Tool: Show AIquila configuration
server.tool(
  'aiquila_show_config',
  'Show current AIquila configuration (API key, model, tokens, timeout)',
  {},
  async () => {
    const helpText = `To view AIquila configuration, run this command on your Nextcloud server:

docker exec -u www-data aiquila-nextcloud php occ aiquila:configure --show

Or via SSH:
php occ aiquila:configure --show

This will show:
- API Key (masked for security)
- Claude Model
- Max Tokens
- API Timeout`;

    return { content: [{ type: 'text', text: helpText }] };
  }
);

// Tool: Configure AIquila settings
server.tool(
  'aiquila_configure',
  'Configure AIquila settings (API key, model, tokens, timeout)',
  {
    apiKey: z.string().optional().describe('Claude API key (starts with sk-ant-)'),
    model: z.string().optional().describe('Claude model (e.g., claude-sonnet-4-20250514)'),
    maxTokens: z.number().optional().describe('Maximum tokens (1-100000)'),
    timeout: z.number().optional().describe('API timeout in seconds (10-1800)'),
  },
  async ({ apiKey, model, maxTokens, timeout }) => {
    let command = 'docker exec -u www-data aiquila-nextcloud php occ aiquila:configure';
    const options: string[] = [];

    if (apiKey) options.push(`--api-key "${apiKey}"`);
    if (model) options.push(`--model "${model}"`);
    if (maxTokens) options.push(`--max-tokens ${maxTokens}`);
    if (timeout) options.push(`--timeout ${timeout}`);

    if (options.length === 0) {
      return {
        content: [
          {
            type: 'text',
            text: 'No options provided. Use aiquila_show_config to view current settings.',
          },
        ],
      };
    }

    command += ' ' + options.join(' ');

    const helpText = `To configure AIquila, run this command on your Nextcloud server:

${command}

Or via SSH:
php occ aiquila:configure ${options.join(' ')}

This will update the configuration with validation.`;

    return { content: [{ type: 'text', text: helpText }] };
  }
);

// Tool: Test AIquila Claude integration
server.tool(
  'aiquila_test',
  'Test AIquila Claude API integration with a prompt',
  {
    prompt: z.string().optional().describe('Test prompt to send to Claude'),
    user: z.string().optional().describe('Nextcloud user ID to test with'),
  },
  async ({ prompt, user }) => {
    let command = 'docker exec -u www-data aiquila-nextcloud php occ aiquila:test';

    if (prompt) command += ` --prompt "${prompt}"`;
    if (user) command += ` --user ${user}`;

    const helpText = `To test AIquila Claude integration, run this command on your Nextcloud server:

${command}

Or via SSH:
php occ aiquila:test${prompt ? ` --prompt "${prompt}"` : ''}${user ? ` --user ${user}` : ''}

This will:
1. Check configuration (API key, model, tokens, timeout)
2. Send a test request to Claude API
3. Display the response or error details

Useful for debugging API connectivity and configuration issues.`;

    return { content: [{ type: 'text', text: helpText }] };
  }
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('AIquila MCP server running');
}

main().catch(console.error);
