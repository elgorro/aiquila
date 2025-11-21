import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { createClient, WebDAVClient } from "webdav";

// Configuration from environment
const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL || "";
const NEXTCLOUD_USER = process.env.NEXTCLOUD_USER || "";
const NEXTCLOUD_PASSWORD = process.env.NEXTCLOUD_PASSWORD || "";

let webdavClient: WebDAVClient | null = null;

function getClient(): WebDAVClient {
  if (!webdavClient) {
    if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
      throw new Error("Missing Nextcloud credentials. Set NEXTCLOUD_URL, NEXTCLOUD_USER, NEXTCLOUD_PASSWORD");
    }
    webdavClient = createClient(`${NEXTCLOUD_URL}/remote.php/dav/files/${NEXTCLOUD_USER}`, {
      username: NEXTCLOUD_USER,
      password: NEXTCLOUD_PASSWORD,
    });
  }
  return webdavClient;
}

const server = new McpServer({
  name: "nextclaude",
  version: "0.1.0",
});

// Tool: List files in a directory
server.tool(
  "list_files",
  "List files and folders in a Nextcloud directory",
  { path: z.string().default("/").describe("Directory path to list") },
  async ({ path }) => {
    const client = getClient();
    const items = await client.getDirectoryContents(path);
    const listing = Array.isArray(items) ? items : items.data;
    const formatted = listing.map((item: any) =>
      `${item.type === "directory" ? "📁" : "📄"} ${item.basename}`
    ).join("\n");
    return { content: [{ type: "text", text: formatted || "Empty directory" }] };
  }
);

// Tool: Read a file
server.tool(
  "read_file",
  "Read contents of a file from Nextcloud",
  { path: z.string().describe("File path to read") },
  async ({ path }) => {
    const client = getClient();
    const content = await client.getFileContents(path, { format: "text" });
    return { content: [{ type: "text", text: content as string }] };
  }
);

// Tool: Create or update a file
server.tool(
  "write_file",
  "Create or update a file in Nextcloud",
  {
    path: z.string().describe("File path to write"),
    content: z.string().describe("Content to write"),
  },
  async ({ path, content }) => {
    const client = getClient();
    await client.putFileContents(path, content);
    return { content: [{ type: "text", text: `File written: ${path}` }] };
  }
);

// Tool: Create a directory
server.tool(
  "create_folder",
  "Create a folder in Nextcloud",
  { path: z.string().describe("Folder path to create") },
  async ({ path }) => {
    const client = getClient();
    await client.createDirectory(path);
    return { content: [{ type: "text", text: `Folder created: ${path}` }] };
  }
);

// Tool: Delete a file or folder
server.tool(
  "delete",
  "Delete a file or folder from Nextcloud",
  { path: z.string().describe("Path to delete") },
  async ({ path }) => {
    const client = getClient();
    await client.deleteFile(path);
    return { content: [{ type: "text", text: `Deleted: ${path}` }] };
  }
);

// ============ TASKS API (CalDAV) ============

async function fetchCalDAV(endpoint: string, method: string = "GET", body?: string): Promise<string> {
  const url = `${NEXTCLOUD_URL}/remote.php/dav${endpoint}`;
  const response = await fetch(url, {
    method,
    headers: {
      Authorization: "Basic " + Buffer.from(`${NEXTCLOUD_USER}:${NEXTCLOUD_PASSWORD}`).toString("base64"),
      "Content-Type": "application/xml; charset=utf-8",
      Depth: "1",
    },
    body,
  });
  return response.text();
}

// Tool: List task lists (calendars with VTODO support)
server.tool(
  "list_task_lists",
  "List all task lists in Nextcloud Tasks",
  {},
  async () => {
    const xml = await fetchCalDAV(`/calendars/${NEXTCLOUD_USER}/`, "PROPFIND", `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop>
</d:propfind>`);
    // Extract display names from response
    const names = [...xml.matchAll(/<d:displayname>([^<]+)<\/d:displayname>/g)].map(m => m[1]);
    return { content: [{ type: "text", text: names.length ? names.join("\n") : "No task lists found" }] };
  }
);

// Tool: Create a task
server.tool(
  "create_task",
  "Create a new task in Nextcloud Tasks",
  {
    list: z.string().describe("Task list name (e.g., 'personal')"),
    title: z.string().describe("Task title"),
    description: z.string().optional().describe("Task description"),
    due: z.string().optional().describe("Due date in YYYYMMDD format"),
  },
  async ({ list, title, description, due }) => {
    const uid = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    let vtodo = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//NextClaude//MCP//EN
BEGIN:VTODO
UID:${uid}
DTSTAMP:${new Date().toISOString().replace(/[-:]/g, "").split(".")[0]}Z
SUMMARY:${title}`;
    if (description) vtodo += `\nDESCRIPTION:${description}`;
    if (due) vtodo += `\nDUE;VALUE=DATE:${due}`;
    vtodo += `\nEND:VTODO\nEND:VCALENDAR`;

    await fetchCalDAV(`/calendars/${NEXTCLOUD_USER}/${list}/${uid}.ics`, "PUT", vtodo);
    return { content: [{ type: "text", text: `Task created: ${title}` }] };
  }
);

// ============ COOKBOOK (Recipe files) ============

// Tool: Add a recipe to Cookbook (creates markdown file in Recipes folder)
server.tool(
  "add_recipe",
  "Add a recipe to Nextcloud Cookbook (creates a recipe file)",
  {
    name: z.string().describe("Recipe name"),
    ingredients: z.string().describe("Ingredients list"),
    instructions: z.string().describe("Cooking instructions"),
    servings: z.string().optional().describe("Number of servings"),
    prepTime: z.string().optional().describe("Preparation time"),
  },
  async ({ name, ingredients, instructions, servings, prepTime }) => {
    const client = getClient();
    const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, "-");

    let content = `# ${name}\n\n`;
    if (servings) content += `**Servings:** ${servings}\n`;
    if (prepTime) content += `**Prep Time:** ${prepTime}\n`;
    content += `\n## Ingredients\n\n${ingredients}\n\n## Instructions\n\n${instructions}`;

    // Try to create Recipes folder if it doesn't exist
    try { await client.createDirectory("/Recipes"); } catch {}
    await client.putFileContents(`/Recipes/${slug}.md`, content);
    return { content: [{ type: "text", text: `Recipe saved: /Recipes/${slug}.md` }] };
  }
);

// Tool: Create a note
server.tool(
  "create_note",
  "Create a note in Nextcloud Notes folder",
  {
    title: z.string().describe("Note title"),
    content: z.string().describe("Note content"),
  },
  async ({ title, content }) => {
    const client = getClient();
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, "-");
    try { await client.createDirectory("/Notes"); } catch {}
    await client.putFileContents(`/Notes/${slug}.md`, `# ${title}\n\n${content}`);
    return { content: [{ type: "text", text: `Note saved: /Notes/${slug}.md` }] };
  }
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("NextClaude MCP server running");
}

main().catch(console.error);
