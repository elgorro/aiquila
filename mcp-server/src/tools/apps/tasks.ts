import { z } from 'zod';
import { fetchCalDAV } from '../../client/caldav.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Tasks App Tools
 * Provides full task management via CalDAV
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/**
 * Parsed representation of a VTODO component with CalDAV metadata.
 * Date fields are stored as raw iCalendar strings (e.g. "20240115T103000Z").
 */
interface ParsedTask {
  uid: string;
  summary: string;
  status: string;
  percentComplete: number;
  priority: number;
  due?: string;
  dtstart?: string;
  completed?: string;
  created?: string;
  lastModified?: string;
  description?: string;
  location?: string;
  url?: string;
  categories: string[];
  relatedTo?: string;
  classification?: string;
  pinned: boolean;
  sortOrder?: number;
  hideSubtasks: boolean;
  hideCompletedSubtasks: boolean;
  etag?: string;
  href?: string;
}

// ---------------------------------------------------------------------------
// XML helpers
// ---------------------------------------------------------------------------

/**
 * Build a regex that matches an XML element with any namespace prefix.
 * e.g. nsTagContent("calendar-data") matches <c:calendar-data>, <cal:calendar-data>, <calendar-data>
 */
function nsTagContent(localName: string): RegExp {
  return new RegExp(
    `<(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}[^>]*>([\\s\\S]*?)</(?:[a-zA-Z][a-zA-Z0-9]*:)?${localName}>`
  );
}

// ---------------------------------------------------------------------------
// iCalendar helpers
// ---------------------------------------------------------------------------

/**
 * Unfold iCalendar content lines per RFC 5545 Section 3.1.
 * Folded lines have CRLF followed by a single space or tab.
 */
function unfoldICalLines(text: string): string {
  return text.replace(/\r?\n[ \t]/g, '');
}

/**
 * Escape special characters in iCalendar text values per RFC 5545.
 */
function escapeICalValue(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\n/g, '\\n');
}

/**
 * Format an iCalendar date string for human-readable display.
 * "20240115T103000Z" -> "2024-01-15 10:30"
 * "20240115" -> "2024-01-15"
 */
function formatICalDate(icalDate: string): string {
  if (icalDate.length === 8) {
    return `${icalDate.slice(0, 4)}-${icalDate.slice(4, 6)}-${icalDate.slice(6, 8)}`;
  }
  const match = icalDate.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/);
  if (match) {
    const [, y, m, d, hh, mm] = match;
    return `${y}-${m}-${d} ${hh}:${mm}`;
  }
  return icalDate;
}

/**
 * Generate an iCalendar UTC timestamp for "now".
 */
function icalNow(): string {
  return new Date().toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

/**
 * Replace, add, or remove a simple iCalendar property inside a VTODO block.
 * - value === null  -> remove the property
 * - property exists -> replace its value
 * - property absent -> insert before END:VTODO
 */
function setICalProperty(icalData: string, propName: string, value: string | null): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  if (regex.test(icalData)) {
    return icalData.replace(regex, `${propName}:${value}`);
  }
  return icalData.replace(/END:VTODO/, `${propName}:${value}\r\nEND:VTODO`);
}

/**
 * Replace, add, or remove a date property, using VALUE=DATE for date-only values.
 */
function setICalDateProperty(icalData: string, propName: string, value: string | null): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  const formatted = value.length === 8 ? `${propName};VALUE=DATE:${value}` : `${propName}:${value}`;
  if (regex.test(icalData)) {
    return icalData.replace(regex, formatted);
  }
  return icalData.replace(/END:VTODO/, `${formatted}\r\nEND:VTODO`);
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Parse VTODO fields and CalDAV metadata from a REPORT response.
 * Iterates over <d:response> blocks to extract per-task ETag and href.
 */
function parseVTodos(responseXml: string): ParsedTask[] {
  const tasks: ParsedTask[] = [];

  const responseBlocks = responseXml.match(/<d:response>[\s\S]*?<\/d:response>/g);
  if (!responseBlocks) return tasks;

  for (const responseBlock of responseBlocks) {
    const hrefMatch = responseBlock.match(/<d:href>([^<]+)<\/d:href>/);
    const etagMatch = responseBlock.match(/<d:getetag>"?([^"<]+)"?<\/d:getetag>/);
    const calDataMatch = responseBlock.match(nsTagContent('calendar-data'));
    if (!calDataMatch) continue;

    const icalData = calDataMatch[1];
    const vtodoBlocks = icalData.match(/BEGIN:VTODO[\s\S]*?END:VTODO/g);
    if (!vtodoBlocks) continue;

    for (const block of vtodoBlocks) {
      const unfolded = unfoldICalLines(block);
      const lines = unfolded.split(/\r?\n/);

      const task: ParsedTask = {
        uid: '',
        summary: '',
        status: 'NEEDS-ACTION',
        percentComplete: 0,
        priority: 0,
        categories: [],
        pinned: false,
        hideSubtasks: false,
        hideCompletedSubtasks: false,
        etag: etagMatch?.[1],
        href: hrefMatch?.[1],
      };

      for (const line of lines) {
        const propMatch = line.match(/^([A-Z][A-Z0-9-]*)(;[^:]*)?:(.*)/);
        if (!propMatch) continue;

        const [, name, params, value] = propMatch;

        switch (name) {
          case 'UID':
            task.uid = value;
            break;
          case 'SUMMARY':
            task.summary = value;
            break;
          case 'STATUS':
            task.status = value;
            break;
          case 'PRIORITY':
            task.priority = parseInt(value, 10) || 0;
            break;
          case 'PERCENT-COMPLETE':
            task.percentComplete = parseInt(value, 10) || 0;
            break;
          case 'DESCRIPTION':
            task.description = value;
            break;
          case 'DUE':
            task.due = value;
            break;
          case 'DTSTART':
            task.dtstart = value;
            break;
          case 'COMPLETED':
            task.completed = value;
            break;
          case 'CREATED':
            task.created = value;
            break;
          case 'LAST-MODIFIED':
            task.lastModified = value;
            break;
          case 'LOCATION':
            task.location = value;
            break;
          case 'URL':
            task.url = value;
            break;
          case 'CLASS':
            task.classification = value;
            break;
          case 'CATEGORIES':
            task.categories.push(
              ...value
                .split(',')
                .map((c) => c.trim())
                .filter(Boolean)
            );
            break;
          case 'RELATED-TO':
            if (!params || params.includes('RELTYPE=PARENT')) {
              task.relatedTo = value;
            }
            break;
          case 'X-PINNED':
            task.pinned = value.toLowerCase() === 'true';
            break;
          case 'X-APPLE-SORT-ORDER':
            task.sortOrder = parseInt(value, 10);
            break;
          case 'X-OC-HIDESUBTASKS':
            task.hideSubtasks = value === '1';
            break;
          case 'X-OC-HIDECOMPLETEDSUBTASKS':
            task.hideCompletedSubtasks = value === '1';
            break;
        }
      }

      if (task.summary) {
        tasks.push(task);
      }
    }
  }

  return tasks;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/**
 * Format a parsed task for human-readable display.
 */
function formatTask(task: ParsedTask): string {
  const checkbox = task.status === 'COMPLETED' ? '[x]' : '[ ]';
  let line = `${checkbox} ${task.summary}`;

  if (task.priority > 0) {
    const label = task.priority <= 4 ? 'High' : task.priority === 5 ? 'Medium' : 'Low';
    line += ` (Priority: ${task.priority}/${label})`;
  }

  if (task.percentComplete > 0 && task.percentComplete < 100) {
    line += ` [${task.percentComplete}%]`;
  }

  const dateParts: string[] = [];
  if (task.dtstart) dateParts.push(`Start: ${formatICalDate(task.dtstart)}`);
  if (task.due) dateParts.push(`Due: ${formatICalDate(task.due)}`);
  if (task.completed) dateParts.push(`Completed: ${formatICalDate(task.completed)}`);
  if (dateParts.length > 0) line += ` | ${dateParts.join(' | ')}`;

  if (task.location) line += `\n    Location: ${task.location}`;
  if (task.categories.length > 0) line += `\n    Tags: ${task.categories.join(', ')}`;
  if (task.relatedTo) line += `\n    Parent: ${task.relatedTo}`;
  if (task.description) line += `\n    ${task.description}`;
  if (task.classification && task.classification !== 'PUBLIC')
    line += `\n    Class: ${task.classification}`;

  line += `\n    UID: ${task.uid}`;

  return line;
}

// ---------------------------------------------------------------------------
// CalDAV helpers
// ---------------------------------------------------------------------------

/**
 * Resolve a task's CalDAV href, ETag, and full iCal data by querying for its UID.
 */
async function resolveTaskByUid(
  calendarName: string,
  uid: string
): Promise<{ href: string; etag: string; icalData: string }> {
  const config = getNextcloudConfig();
  const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${calendarName}/`;

  const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VTODO">
        <c:prop-filter name="UID">
          <c:text-match collation="i;octet">${uid}</c:text-match>
        </c:prop-filter>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

  const response = await fetchCalDAV(calDavUrl, {
    method: 'REPORT',
    body: reportBody,
    headers: { Depth: '1' },
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(
      `CalDAV REPORT failed for calendar "${calendarName}": ${response.status} - ${errorText}`
    );
  }

  const responseText = await response.text();

  const hrefMatch = responseText.match(/<d:href>([^<]+)<\/d:href>/);
  const etagMatch = responseText.match(/<d:getetag>"?([^"<]+)"?<\/d:getetag>/);
  const calDataMatch = responseText.match(nsTagContent('calendar-data'));

  if (!hrefMatch || !etagMatch || !calDataMatch) {
    throw new Error(`Task with UID "${uid}" not found in calendar "${calendarName}"`);
  }

  return {
    href: hrefMatch[1],
    etag: etagMatch[1],
    icalData: calDataMatch[1],
  };
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

/**
 * List all task lists in Nextcloud Tasks
 */
export const listTaskListsTool = {
  name: 'list_task_lists',
  description: 'List all task lists in Nextcloud Tasks',
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
      method: 'PROPFIND',
      body: propfindBody,
      headers: {
        Depth: '1',
      },
    });

    const responseText = await response.text();
    return {
      content: [
        {
          type: 'text',
          text: responseText,
        },
      ],
    };
  },
};

/**
 * List tasks from a Nextcloud Tasks calendar
 */
export const listTasksTool = {
  name: 'list_tasks',
  description:
    'List tasks from a Nextcloud Tasks calendar. Returns task details including status, priority, dates, tags, and UIDs.',
  inputSchema: z.object({
    calendarName: z
      .string()
      .default('tasks')
      .describe("The calendar/task list name (default: 'tasks')"),
    status: z
      .enum(['NEEDS-ACTION', 'COMPLETED', 'IN-PROCESS', 'CANCELLED'])
      .optional()
      .describe('Filter tasks by status'),
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
        method: 'REPORT',
        body: reportBody,
        headers: {
          Depth: '1',
        },
      });

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `CalDAV REPORT failed for calendar "${args.calendarName}": ${response.status} - ${errorText}`
        );
      }

      const responseText = await response.text();
      let tasks = parseVTodos(responseText);

      if (args.status) {
        tasks = tasks.filter((t) => t.status === args.status);
      }

      if (tasks.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No tasks found in "${args.calendarName}"${args.status ? ` with status ${args.status}` : ''}.`,
            },
          ],
        };
      }

      const formatted = tasks.map(formatTask).join('\n\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Tasks in "${args.calendarName}" (${tasks.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing tasks: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Create a new task in Nextcloud Tasks
 */
export const createTaskTool = {
  name: 'create_task',
  description:
    'Create a new task in Nextcloud Tasks with optional due date, start date, tags, location, and subtask relationships.',
  inputSchema: z.object({
    summary: z.string().describe('The task summary/title'),
    calendarName: z.string().default('tasks').describe("The calendar name (default: 'tasks')"),
    description: z.string().optional().describe('Detailed description of the task'),
    priority: z
      .number()
      .min(0)
      .max(9)
      .optional()
      .describe('Task priority (0=undefined, 1=highest, 5=medium, 9=lowest)'),
    due: z
      .string()
      .optional()
      .describe(
        "Due date in YYYYMMDD or YYYYMMDDTHHmmssZ format (e.g. '20240315' or '20240315T140000Z')"
      ),
    dtstart: z.string().optional().describe('Start date in YYYYMMDD or YYYYMMDDTHHmmssZ format'),
    location: z.string().optional().describe('Location string'),
    categories: z
      .array(z.string())
      .optional()
      .describe("Tags/categories (e.g. ['work', 'urgent'])"),
    relatedTo: z.string().optional().describe('Parent task UID (creates a subtask relationship)'),
    percentComplete: z
      .number()
      .min(0)
      .max(100)
      .optional()
      .describe('Completion percentage (0-100)'),
    classification: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('Task visibility classification'),
    url: z.string().optional().describe('Custom URL reference'),
  }),
  handler: async (args: {
    summary: string;
    calendarName: string;
    description?: string;
    priority?: number;
    due?: string;
    dtstart?: string;
    location?: string;
    categories?: string[];
    relatedTo?: string;
    percentComplete?: number;
    classification?: string;
    url?: string;
  }) => {
    const config = getNextcloudConfig();
    const taskUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
    const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/${taskUid}.ics`;
    const now = icalNow();

    let vtodo = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//AIquila//MCP Server//EN\r\nBEGIN:VTODO\r\nUID:${taskUid}\r\nDTSTAMP:${now}\r\nCREATED:${now}\r\nLAST-MODIFIED:${now}\r\nSUMMARY:${escapeICalValue(args.summary)}\r\nSTATUS:NEEDS-ACTION`;

    if (args.description) {
      vtodo += `\r\nDESCRIPTION:${escapeICalValue(args.description)}`;
    }
    if (args.priority !== undefined && args.priority > 0) {
      vtodo += `\r\nPRIORITY:${args.priority}`;
    }
    if (args.due) {
      vtodo += args.due.length === 8 ? `\r\nDUE;VALUE=DATE:${args.due}` : `\r\nDUE:${args.due}`;
    }
    if (args.dtstart) {
      vtodo +=
        args.dtstart.length === 8
          ? `\r\nDTSTART;VALUE=DATE:${args.dtstart}`
          : `\r\nDTSTART:${args.dtstart}`;
    }
    if (args.location) {
      vtodo += `\r\nLOCATION:${escapeICalValue(args.location)}`;
    }
    if (args.categories && args.categories.length > 0) {
      vtodo += `\r\nCATEGORIES:${args.categories.map(escapeICalValue).join(',')}`;
    }
    if (args.relatedTo) {
      vtodo += `\r\nRELATED-TO;RELTYPE=PARENT:${args.relatedTo}`;
    }
    if (args.percentComplete !== undefined && args.percentComplete > 0) {
      vtodo += `\r\nPERCENT-COMPLETE:${args.percentComplete}`;
    }
    if (args.classification) {
      vtodo += `\r\nCLASS:${args.classification}`;
    }
    if (args.url) {
      vtodo += `\r\nURL:${args.url}`;
    }

    vtodo += `\r\nEND:VTODO\r\nEND:VCALENDAR`;

    const response = await fetchCalDAV(calDavUrl, {
      method: 'PUT',
      body: vtodo,
      headers: {
        'Content-Type': 'text/calendar; charset=utf-8',
        'If-None-Match': '*',
      },
    });

    // CalDAV PUT for creation should return 201 (Created) or 204 (No Content)
    if (response.status !== 201 && response.status !== 204) {
      const errorText = await response.text();
      throw new Error(
        `Failed to create task: server returned ${response.status} (expected 201 or 204) - ${errorText}`
      );
    }

    return {
      content: [
        {
          type: 'text' as const,
          text: `Task created successfully: ${args.summary} (UID: ${taskUid})`,
        },
      ],
    };
  },
};

/**
 * Update an existing task's fields by UID
 */
export const updateTaskTool = {
  name: 'update_task',
  description:
    "Update an existing task's fields by UID. Uses CalDAV ETag-based optimistic concurrency.",
  inputSchema: z.object({
    uid: z.string().describe('The UID of the task to update'),
    calendarName: z.string().default('tasks').describe("The calendar name (default: 'tasks')"),
    summary: z.string().optional().describe('New task title'),
    description: z.string().optional().describe('New description'),
    priority: z
      .number()
      .min(0)
      .max(9)
      .optional()
      .describe('New priority (0-9, 0 removes priority)'),
    due: z
      .string()
      .nullable()
      .optional()
      .describe('New due date (YYYYMMDD or YYYYMMDDTHHmmssZ), or null to remove'),
    dtstart: z.string().nullable().optional().describe('New start date, or null to remove'),
    status: z
      .enum(['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'])
      .optional()
      .describe('New status'),
    location: z.string().nullable().optional().describe('New location, or null to remove'),
    categories: z.array(z.string()).optional().describe('Replace all tags with these'),
    relatedTo: z
      .string()
      .nullable()
      .optional()
      .describe('New parent UID, or null to remove parent'),
    percentComplete: z.number().min(0).max(100).optional().describe('New completion percentage'),
    classification: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('New classification'),
    url: z.string().nullable().optional().describe('New URL, or null to remove'),
    pinned: z.boolean().optional().describe('Pin or unpin the task'),
  }),
  handler: async (args: {
    uid: string;
    calendarName: string;
    summary?: string;
    description?: string;
    priority?: number;
    due?: string | null;
    dtstart?: string | null;
    status?: string;
    location?: string | null;
    categories?: string[];
    relatedTo?: string | null;
    percentComplete?: number;
    classification?: string;
    url?: string | null;
    pinned?: boolean;
  }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag, icalData } = await resolveTaskByUid(args.calendarName, args.uid);

      let modified = unfoldICalLines(icalData);

      // Apply updates for provided fields
      if (args.summary !== undefined) {
        modified = setICalProperty(modified, 'SUMMARY', escapeICalValue(args.summary));
      }
      if (args.description !== undefined) {
        modified = setICalProperty(modified, 'DESCRIPTION', escapeICalValue(args.description));
      }
      if (args.priority !== undefined) {
        modified = setICalProperty(
          modified,
          'PRIORITY',
          args.priority === 0 ? null : String(args.priority)
        );
      }
      if (args.due !== undefined) {
        modified = setICalDateProperty(modified, 'DUE', args.due);
      }
      if (args.dtstart !== undefined) {
        modified = setICalDateProperty(modified, 'DTSTART', args.dtstart);
      }
      if (args.status !== undefined) {
        modified = setICalProperty(modified, 'STATUS', args.status);
      }
      if (args.location !== undefined) {
        modified = setICalProperty(
          modified,
          'LOCATION',
          args.location ? escapeICalValue(args.location) : null
        );
      }
      if (args.percentComplete !== undefined) {
        modified = setICalProperty(modified, 'PERCENT-COMPLETE', String(args.percentComplete));
      }
      if (args.classification !== undefined) {
        modified = setICalProperty(modified, 'CLASS', args.classification);
      }
      if (args.url !== undefined) {
        modified = setICalProperty(modified, 'URL', args.url);
      }
      if (args.pinned !== undefined) {
        modified = setICalProperty(modified, 'X-PINNED', args.pinned ? 'true' : null);
      }
      if (args.relatedTo !== undefined) {
        // RELATED-TO needs special handling for RELTYPE param
        const relRegex = /^RELATED-TO(;[^:]*)?:.*$/m;
        if (args.relatedTo === null) {
          modified = modified.replace(relRegex, '').replace(/(\r?\n){2,}/g, '\r\n');
        } else if (relRegex.test(modified)) {
          modified = modified.replace(relRegex, `RELATED-TO;RELTYPE=PARENT:${args.relatedTo}`);
        } else {
          modified = modified.replace(
            /END:VTODO/,
            `RELATED-TO;RELTYPE=PARENT:${args.relatedTo}\r\nEND:VTODO`
          );
        }
      }
      if (args.categories !== undefined) {
        // Remove all existing CATEGORIES, then add new
        modified = modified.replace(/^CATEGORIES(;[^:]*)?:.*\r?\n?/gm, '');
        if (args.categories.length > 0) {
          modified = modified.replace(
            /END:VTODO/,
            `CATEGORIES:${args.categories.map(escapeICalValue).join(',')}\r\nEND:VTODO`
          );
        }
      }

      // Always update timestamps
      const now = icalNow();
      modified = setICalProperty(modified, 'LAST-MODIFIED', now);
      modified = setICalProperty(modified, 'DTSTAMP', now);

      const putUrl = `${config.url}${href}`;
      const putResponse = await fetchCalDAV(putUrl, {
        method: 'PUT',
        body: modified,
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-Match': `"${etag}"`,
        },
      });

      if (putResponse.ok) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Task updated successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (putResponse.status === 412) {
        throw new Error('Task was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await putResponse.text();
        throw new Error(`Failed to update task: ${putResponse.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating task: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a task from Nextcloud Tasks by UID
 */
export const deleteTaskTool = {
  name: 'delete_task',
  description: 'Delete a task from Nextcloud Tasks by UID. This action is irreversible.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the task to delete'),
    calendarName: z.string().default('tasks').describe("The calendar name (default: 'tasks')"),
  }),
  handler: async (args: { uid: string; calendarName: string }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag } = await resolveTaskByUid(args.calendarName, args.uid);

      const deleteUrl = `${config.url}${href}`;
      const response = await fetchCalDAV(deleteUrl, {
        method: 'DELETE',
        headers: {
          'If-Match': `"${etag}"`,
        },
      });

      if (response.ok || response.status === 204) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Task deleted successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (response.status === 412) {
        throw new Error('Task was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await response.text();
        throw new Error(`Failed to delete task: ${response.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting task: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Mark a task as completed or reopen it
 */
export const completeTaskTool = {
  name: 'complete_task',
  description:
    'Mark a task as completed or reopen it. Sets STATUS, PERCENT-COMPLETE, and COMPLETED date atomically.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the task'),
    calendarName: z.string().default('tasks').describe("The calendar name (default: 'tasks')"),
    completed: z
      .boolean()
      .default(true)
      .describe('true = mark completed, false = reopen (default: true)'),
  }),
  handler: async (args: { uid: string; calendarName: string; completed: boolean }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag, icalData } = await resolveTaskByUid(args.calendarName, args.uid);

      let modified = unfoldICalLines(icalData);
      const now = icalNow();

      if (args.completed) {
        modified = setICalProperty(modified, 'STATUS', 'COMPLETED');
        modified = setICalProperty(modified, 'PERCENT-COMPLETE', '100');
        modified = setICalProperty(modified, 'COMPLETED', now);
      } else {
        modified = setICalProperty(modified, 'STATUS', 'NEEDS-ACTION');
        modified = setICalProperty(modified, 'PERCENT-COMPLETE', '0');
        modified = setICalProperty(modified, 'COMPLETED', null);
      }

      modified = setICalProperty(modified, 'LAST-MODIFIED', now);
      modified = setICalProperty(modified, 'DTSTAMP', now);

      const putUrl = `${config.url}${href}`;
      const putResponse = await fetchCalDAV(putUrl, {
        method: 'PUT',
        body: modified,
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-Match': `"${etag}"`,
        },
      });

      if (putResponse.ok) {
        const action = args.completed ? 'completed' : 'reopened';
        return {
          content: [
            {
              type: 'text' as const,
              text: `Task ${action} successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (putResponse.status === 412) {
        throw new Error('Task was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await putResponse.text();
        throw new Error(`Failed to complete task: ${putResponse.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error completing task: ${error instanceof Error ? error.message : String(error)}`,
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
export const tasksTools = [
  listTaskListsTool,
  listTasksTool,
  createTaskTool,
  updateTaskTool,
  deleteTaskTool,
  completeTaskTool,
];
