import { z } from "zod";
import { fetchCalDAV } from "../../client/caldav.js";
import { getNextcloudConfig } from "../types.js";

/**
 * Nextcloud Tasks App Tools
 * Provides task management via CalDAV
 */

/**
 * List all task lists in Nextcloud Tasks
 */
export const listTaskListsTool = {
  name: "list_task_lists",
  description: "List all task lists in Nextcloud Tasks",
  inputSchema: z.object({}),
  handler: async () => {
    const config = getNextcloudConfig();
    const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/`;

    const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:resourcetype />
    <d:displayname />
    <cs:getctag />
    <c:supported-calendar-component-set />
  </d:prop>
</d:propfind>`;

    const response = await fetchCalDAV(calDavUrl, {
      method: "PROPFIND",
      body: propfindBody,
      headers: {
        Depth: "1",
      },
    });

    const responseText = await response.text();
    return {
      content: [
        {
          type: "text",
          text: responseText,
        },
      ],
    };
  },
};

/**
 * Create a new task in Nextcloud Tasks
 */
export const createTaskTool = {
  name: "create_task",
  description: "Create a new task in Nextcloud Tasks",
  inputSchema: z.object({
    summary: z.string().describe("The task summary/title"),
    calendarName: z
      .string()
      .default("tasks")
      .describe("The calendar name (default: 'tasks')"),
    description: z
      .string()
      .optional()
      .describe("Optional detailed description of the task"),
    priority: z
      .number()
      .min(0)
      .max(9)
      .optional()
      .describe("Task priority (0-9, where 0 = undefined, 1 = highest, 9 = lowest)"),
  }),
  handler: async (args: {
    summary: string;
    calendarName: string;
    description?: string;
    priority?: number;
  }) => {
    const config = getNextcloudConfig();
    const taskUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
    const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/${taskUid}.ics`;

    let vtodoContent = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//AIquila//MCP Server//EN
BEGIN:VTODO
UID:${taskUid}
SUMMARY:${args.summary}
DTSTAMP:${new Date().toISOString().replace(/[-:]/g, "").split(".")[0]}Z
STATUS:NEEDS-ACTION`;

    if (args.description) {
      vtodoContent += `\nDESCRIPTION:${args.description}`;
    }

    if (args.priority !== undefined) {
      vtodoContent += `\nPRIORITY:${args.priority}`;
    }

    vtodoContent += `\nEND:VTODO
END:VCALENDAR`;

    const response = await fetchCalDAV(calDavUrl, {
      method: "PUT",
      body: vtodoContent,
      headers: {
        "Content-Type": "text/calendar; charset=utf-8",
      },
    });

    if (response.ok) {
      return {
        content: [
          {
            type: "text",
            text: `Task created successfully: ${args.summary} (UID: ${taskUid})`,
          },
        ],
      };
    } else {
      const errorText = await response.text();
      throw new Error(`Failed to create task: ${response.status} - ${errorText}`);
    }
  },
};

/**
 * Parse VTODO fields from iCalendar data
 */
function parseVTodos(icalData: string): Array<Record<string, string>> {
  const todos: Array<Record<string, string>> = [];
  const vtodoBlocks = icalData.match(/BEGIN:VTODO[\s\S]*?END:VTODO/g);
  if (!vtodoBlocks) return todos;

  for (const block of vtodoBlocks) {
    const fields: Record<string, string> = {};
    const lines = block.split(/\r?\n/);
    for (const line of lines) {
      const match = line.match(/^(SUMMARY|STATUS|PRIORITY|DESCRIPTION|DUE|UID|PERCENT-COMPLETE)[^:]*:(.*)/);
      if (match) {
        fields[match[1]] = match[2];
      }
    }
    if (fields.SUMMARY) {
      todos.push(fields);
    }
  }
  return todos;
}

/**
 * Format a single task for display
 */
function formatTask(task: Record<string, string>): string {
  const status = task.STATUS || "NEEDS-ACTION";
  const checkbox = status === "COMPLETED" ? "[x]" : "[ ]";
  let line = `${checkbox} ${task.SUMMARY}`;
  if (task.PRIORITY && task.PRIORITY !== "0") {
    line += ` (Priority: ${task.PRIORITY})`;
  }
  if (task.DUE) {
    line += ` | Due: ${task.DUE}`;
  }
  if (task.DESCRIPTION) {
    line += `\n    ${task.DESCRIPTION}`;
  }
  return line;
}

/**
 * List tasks from a Nextcloud Tasks calendar
 */
export const listTasksTool = {
  name: "list_tasks",
  description:
    "List tasks from a Nextcloud Tasks calendar. Returns task summaries, status, priority, and due dates.",
  inputSchema: z.object({
    calendarName: z
      .string()
      .default("tasks")
      .describe("The calendar/task list name (default: 'tasks')"),
    status: z
      .enum(["NEEDS-ACTION", "COMPLETED", "IN-PROCESS", "CANCELLED"])
      .optional()
      .describe("Filter tasks by status"),
  }),
  handler: async (args: { calendarName: string; status?: string }) => {
    try {
      const config = getNextcloudConfig();
      const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/`;

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VTODO" />
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const response = await fetchCalDAV(calDavUrl, {
        method: "REPORT",
        body: reportBody,
        headers: {
          Depth: "1",
        },
      });

      const responseText = await response.text();
      let tasks = parseVTodos(responseText);

      if (args.status) {
        tasks = tasks.filter((t) => t.STATUS === args.status);
      }

      if (tasks.length === 0) {
        return {
          content: [
            {
              type: "text" as const,
              text: `No tasks found in "${args.calendarName}"${args.status ? ` with status ${args.status}` : ""}.`,
            },
          ],
        };
      }

      const formatted = tasks.map(formatTask).join("\n");
      return {
        content: [
          {
            type: "text" as const,
            text: `Tasks in "${args.calendarName}" (${tasks.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: "text" as const,
            text: `Error listing tasks: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Tasks app tools
 */
export const tasksTools = [listTaskListsTool, listTasksTool, createTaskTool];
