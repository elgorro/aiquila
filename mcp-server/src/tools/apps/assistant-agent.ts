// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { getWebDAVClient } from '../../client/webdav.js';
import { ICAL_RECUR, IANA_TZID } from '../dav-utils.js';

/**
 * Nextcloud Assistant agent tools: the user's Skills (reusable instructions
 * the Context Agent loads on demand) and Assignments (prompts the Assistant
 * runs on a schedule). Both are public OCS APIs of the Assistant app
 * (`#[OpenAPI(scope: SCOPE_DEFAULT)]`, assistant 4.1, Nextcloud 35).
 */

const SKILLS_API = '/ocs/v2.php/apps/assistant/api/v1/skills';
const ASSIGNMENTS_API = '/ocs/v2.php/apps/assistant/assignments';

interface Assignment {
  id: number;
  user_id: string;
  prompt: string;
  recurrence: string;
  starts_at: number;
  created_at: number;
  updated_at: number;
  last_run_at: number;
  timezone: string;
}

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(action: string, err: unknown) {
  const message = err instanceof Error ? err.message : String(err);
  return {
    content: [{ type: 'text' as const, text: `Error ${action}: ${message}` }],
    isError: true,
  };
}

/** The status in an `OCS API error: <status> …` message, if there is one. */
function ocsStatus(err: unknown): number | undefined {
  const m = err instanceof Error ? err.message.match(/^OCS API error: (\d{3})\b/) : null;
  return m ? Number(m[1]) : undefined;
}

// A skill's name is its folder name and the frontmatter `name`. The Assistant
// accepts any valid file name; this only keeps it to one path segment, so it
// cannot address anything outside the skills folder.
const SkillName = z
  .string()
  .min(1)
  .max(255)
  .refine(
    (n) => !/[/\\]/.test(n) && n !== '.' && n !== '..',
    'A skill name is a single folder name.'
  )
  .describe('Skill name, as list_assistant_skills shows it, e.g. "weekly-report"');

/**
 * RFC 5545 §3.3.10: a RECUR value is `;`-separated `NAME=value` parts with
 * exactly one FREQ. The Assistant parses it again; this catches the common
 * mistakes before the request, with a message that says what is wrong.
 */
export function recurrenceProblem(rule: string): string | undefined {
  const value = rule.replace(/^RRULE:/i, '');
  if (!ICAL_RECUR.test(value)) return 'it may only contain letters, digits and = ; , + -';
  const parts = value.split(';').filter(Boolean);
  if (parts.some((p) => !/^[A-Za-z]+=[^=]+$/.test(p))) return 'every part must be NAME=value';
  const freq = parts.filter((p) => /^FREQ=/i.test(p));
  if (freq.length !== 1) return 'it needs exactly one FREQ (e.g. FREQ=WEEKLY;BYDAY=MO)';
  if (!/^FREQ=(SECONDLY|MINUTELY|HOURLY|DAILY|WEEKLY|MONTHLY|YEARLY)$/i.test(freq[0])) {
    return 'FREQ must be one of SECONDLY, MINUTELY, HOURLY, DAILY, WEEKLY, MONTHLY, YEARLY';
  }
  // weekdaynum = [[plus / minus] ordwk] weekday, ordwk 1 to 53. The Assistant
  // accepts any BYDAY text, and a rule it cannot evaluate would never run.
  const byday = parts.find((p) => /^BYDAY=/i.test(p));
  if (byday) {
    for (const day of byday.slice(6).split(',')) {
      const m = day.match(/^([+-]?)(\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/i);
      if (!m || (m[2] !== undefined && (Number(m[2]) < 1 || Number(m[2]) > 53))) {
        return `BYDAY "${day}" is not a weekday such as MO, -1FR or 2TU`;
      }
    }
  }
  return undefined;
}

const Recurrence = z
  .string()
  .superRefine((rule, ctx) => {
    const problem = recurrenceProblem(rule);
    if (problem) ctx.addIssue({ code: 'custom', message: `Invalid recurrence rule: ${problem}` });
  })
  .transform((rule) => rule.replace(/^RRULE:/i, ''))
  .describe('iCalendar RRULE (RFC 5545), e.g. "FREQ=DAILY" or "FREQ=WEEKLY;BYDAY=MO,TH"');

// An offset is required: a bare local time would be read in the MCP server's
// zone, which is rarely the user's.
const StartsAt = z
  .string()
  .regex(
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/,
    'Use ISO 8601 with an offset, e.g. 2026-11-16T08:00:00+01:00 or 2026-11-16T07:00:00Z.'
  )
  .describe('First run, ISO 8601 with offset, e.g. "2026-11-16T08:00:00+01:00"');

const TimeZone = z
  .string()
  .regex(IANA_TZID, 'Use an IANA time zone, e.g. "Europe/Berlin".')
  .describe(
    'IANA time zone the recurrence follows, e.g. "Europe/Berlin" (default: the user\'s zone)'
  );

function toEpoch(iso: string): number {
  return Math.floor(Date.parse(iso) / 1000);
}

function formatAssignment(a: Assignment): string {
  const when = (s: number) => (s > 0 ? new Date(s * 1000).toISOString() : 'never');
  return [
    `[${a.id}] ${a.recurrence} (${a.timezone})`,
    `  starts: ${when(a.starts_at)} · last run: ${when(a.last_run_at)}`,
    `  prompt: ${a.prompt}`,
  ].join('\n');
}

// ---------------------------------------------------------------------------
// Skills
// ---------------------------------------------------------------------------

export const listAssistantSkillsTool = {
  name: 'list_assistant_skills',
  title: 'List Assistant Skills',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List the user's Nextcloud Assistant skills: reusable instructions the Assistant's agent loads when a task matches their description.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const res = await fetchOCS<{ skills: { name: string; description: string }[] }>(SKILLS_API);
      const skills = res.ocs.data.skills ?? [];
      if (skills.length === 0) return text('No skills yet. Create one with save_assistant_skill.');
      return text(skills.map((s) => `- ${s.name}: ${s.description}`).join('\n'));
    } catch (err) {
      // 404: the user has no skills folder yet, which is the same as no skills.
      if (ocsStatus(err) === 404) {
        return text('No skills yet. Create one with save_assistant_skill.');
      }
      return error('listing skills', err);
    }
  },
};

export const getAssistantSkillTool = {
  name: 'get_assistant_skill',
  title: 'Get Assistant Skill',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Read one Assistant skill's full SKILL.md: its frontmatter and instructions.",
  inputSchema: z.object({ name: SkillName }),
  handler: async (args: { name: string }) => {
    try {
      const res = await fetchOCS<{ content: string }>(
        `${SKILLS_API}/${encodeURIComponent(args.name)}`
      );
      return text(res.ocs.data.content);
    } catch (err) {
      if (ocsStatus(err) === 404) {
        return error('reading skill', new Error(`No skill named "${args.name}".`));
      }
      return error('reading skill', err);
    }
  },
};

export const saveAssistantSkillTool = {
  name: 'save_assistant_skill',
  title: 'Save Assistant Skill',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Create an Assistant skill, or replace the one with the same name. The description tells the agent when to use it; the content is the Markdown instructions.',
  inputSchema: z.object({
    name: SkillName,
    description: z
      .string()
      .min(1)
      .max(1024)
      .describe('When the agent should use this skill, in one or two sentences'),
    content: z.string().min(1).describe('The instructions, in Markdown'),
  }),
  handler: async (args: { name: string; description: string; content: string }) => {
    try {
      const res = await fetchOCS<{ action: 'created' | 'overwritten' }>(SKILLS_API, {
        method: 'POST',
        jsonBody: { skillName: args.name, description: args.description, content: args.content },
      });
      return text(
        res.ocs.data.action === 'overwritten'
          ? `Replaced skill "${args.name}".`
          : `Created skill "${args.name}".`
      );
    } catch (err) {
      return error('saving skill', err);
    }
  },
};

/**
 * The user's own skills folder in Files. The Assistant names its folder per
 * user (assistant-folder-path) and keeps skills under `Context Agent/Skills`
 * (AgentSkillsService::SKILLS_FOLDER_PATH); the API has no delete endpoint.
 */
async function skillsFolder(): Promise<string> {
  const res = await fetchOCS<{ path: string }>(
    '/ocs/v2.php/apps/assistant/api/v1/assistant-folder-path'
  );
  // An internal path, /<uid>/files/<folder>: keep the part inside Files.
  const inFiles = res.ocs.data.path.replace(/^\/[^/]+\/files(?=\/|$)/, '') || '/';
  return `${inFiles.replace(/\/+$/, '')}/Context Agent/Skills`;
}

export const deleteAssistantSkillTool = {
  name: 'delete_assistant_skill',
  title: 'Delete Assistant Skill',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Delete one of the user's Assistant skills (its folder goes to the trash bin). Skills an admin shares with everyone cannot be deleted here.",
  inputSchema: z.object({ name: SkillName }),
  handler: async (args: { name: string }) => {
    try {
      try {
        await fetchOCS(`${SKILLS_API}/${encodeURIComponent(args.name)}`);
      } catch (err) {
        if (ocsStatus(err) === 404) {
          throw new Error(`No skill named "${args.name}".`, { cause: err });
        }
        throw err;
      }
      const folder = `${await skillsFolder()}/${args.name}`;
      try {
        await getWebDAVClient().deleteFile(folder);
      } catch (err) {
        if ((err as { status?: number }).status === 404) {
          throw new Error(
            `"${args.name}" is not in your skills folder; skills an admin shares cannot be deleted here.`,
            { cause: err }
          );
        }
        throw err;
      }
      return text(`Deleted skill "${args.name}" (${folder} is in the trash bin).`);
    } catch (err) {
      return error('deleting skill', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Assignments
// ---------------------------------------------------------------------------

export const listAssistantAssignmentsTool = {
  name: 'list_assistant_assignments',
  title: 'List Assistant Assignments',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List the user's Nextcloud Assistant assignments: prompts the Assistant runs on a schedule.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const res = await fetchOCS<{ assignments: Assignment[] }>(ASSIGNMENTS_API);
      const list = res.ocs.data.assignments ?? [];
      if (list.length === 0) return text('No assignments.');
      return text(list.map(formatAssignment).join('\n\n'));
    } catch (err) {
      return error('listing assignments', err);
    }
  },
};

export const getAssistantAssignmentTool = {
  name: 'get_assistant_assignment',
  title: 'Get Assistant Assignment',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get one Assistant assignment by ID.',
  inputSchema: z.object({ id: z.number().int().positive().describe('Assignment ID') }),
  handler: async (args: { id: number }) => {
    try {
      const res = await fetchOCS<{ assignment: Assignment }>(`${ASSIGNMENTS_API}/${args.id}`);
      return text(formatAssignment(res.ocs.data.assignment));
    } catch (err) {
      if (ocsStatus(err) === 404) {
        return error('reading assignment', new Error(`No assignment ${args.id}.`));
      }
      return error('reading assignment', err);
    }
  },
};

export const createAssistantAssignmentTool = {
  name: 'create_assistant_assignment',
  title: 'Create Assistant Assignment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Schedule a prompt for the Nextcloud Assistant to run, once or on a recurrence (RRULE). The Assistant runs it as the user and notifies them of the result.',
  inputSchema: z.object({
    title: z.string().min(1).max(256).describe('Short title, e.g. "Monday inbox summary"'),
    prompt: z.string().min(1).describe('What the Assistant should do on each run'),
    startsAt: StartsAt,
    recurrence: Recurrence,
    timezone: TimeZone.optional(),
  }),
  handler: async (args: {
    title: string;
    prompt: string;
    startsAt: string;
    recurrence: string;
    timezone?: string;
  }) => {
    try {
      const res = await fetchOCS<{ assignment: Assignment }>(ASSIGNMENTS_API, {
        method: 'POST',
        jsonBody: {
          title: args.title,
          prompt: args.prompt,
          startsAt: toEpoch(args.startsAt),
          recurrence: args.recurrence,
          ...(args.timezone ? { timezone: args.timezone } : {}),
        },
      });
      return text(`Created assignment:\n${formatAssignment(res.ocs.data.assignment)}`);
    } catch (err) {
      if (ocsStatus(err) === 400) {
        return error(
          'creating assignment',
          new Error('The Assistant rejected the recurrence rule, start time or time zone.')
        );
      }
      return error('creating assignment', err);
    }
  },
};

export const updateAssistantAssignmentTool = {
  name: 'update_assistant_assignment',
  title: 'Update Assistant Assignment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Change an Assistant assignment's prompt, start, recurrence or time zone. Fields left out keep their value.",
  inputSchema: z.object({
    id: z.number().int().positive().describe('Assignment ID'),
    prompt: z.string().min(1).optional().describe('New prompt'),
    startsAt: StartsAt.optional(),
    recurrence: Recurrence.optional(),
    timezone: TimeZone.optional(),
  }),
  handler: async (args: {
    id: number;
    prompt?: string;
    startsAt?: string;
    recurrence?: string;
    timezone?: string;
  }) => {
    const body: Record<string, string | number> = {};
    if (args.prompt !== undefined) body.prompt = args.prompt;
    if (args.startsAt !== undefined) body.startsAt = toEpoch(args.startsAt);
    if (args.recurrence !== undefined) body.recurrence = args.recurrence;
    if (args.timezone !== undefined) body.timezone = args.timezone;
    if (Object.keys(body).length === 0) {
      return error('updating assignment', new Error('Nothing to change.'));
    }
    try {
      const res = await fetchOCS<{ assignment: Assignment }>(`${ASSIGNMENTS_API}/${args.id}`, {
        method: 'PATCH',
        jsonBody: body,
      });
      return text(`Updated assignment:\n${formatAssignment(res.ocs.data.assignment)}`);
    } catch (err) {
      const status = ocsStatus(err);
      if (status === 404)
        return error('updating assignment', new Error(`No assignment ${args.id}.`));
      if (status === 400) {
        return error(
          'updating assignment',
          new Error('The Assistant rejected the recurrence rule, start time or time zone.')
        );
      }
      return error('updating assignment', err);
    }
  },
};

export const deleteAssistantAssignmentTool = {
  name: 'delete_assistant_assignment',
  title: 'Delete Assistant Assignment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete an Assistant assignment, so it no longer runs.',
  inputSchema: z.object({ id: z.number().int().positive().describe('Assignment ID') }),
  handler: async (args: { id: number }) => {
    try {
      await fetchOCS(`${ASSIGNMENTS_API}/${args.id}`, { method: 'DELETE' });
      return text(`Deleted assignment ${args.id}.`);
    } catch (err) {
      return error('deleting assignment', err);
    }
  },
};

export const assistantAgentTools = [
  listAssistantSkillsTool,
  getAssistantSkillTool,
  saveAssistantSkillTool,
  deleteAssistantSkillTool,
  listAssistantAssignmentsTool,
  getAssistantAssignmentTool,
  createAssistantAssignmentTool,
  updateAssistantAssignmentTool,
  deleteAssistantAssignmentTool,
];
