// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));
const mockDeleteFile = vi.fn();
vi.mock('../client/webdav.js', () => ({
  getWebDAVClient: () => ({ deleteFile: (...args: unknown[]) => mockDeleteFile(...args) }),
}));

import {
  createAssistantAssignmentTool,
  deleteAssistantAssignmentTool,
  deleteAssistantSkillTool,
  getAssistantSkillTool,
  listAssistantAssignmentsTool,
  listAssistantSkillsTool,
  recurrenceProblem,
  saveAssistantSkillTool,
  updateAssistantAssignmentTool,
} from '../tools/apps/assistant-agent.js';
import { assistantTools } from '../tools/apps/assistant.js';

const ocs = <T>(data: T) => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } });

const ASSIGNMENT = {
  id: 7,
  user_id: 'alice',
  prompt: 'Summarise my unread mail',
  recurrence: 'FREQ=WEEKLY;BYDAY=MO',
  starts_at: 1794643200,
  created_at: 1794600000,
  updated_at: 1794600000,
  last_run_at: 0,
  timezone: 'Europe/Berlin',
};

// A block body: a function returned from beforeEach is run as its teardown.
beforeEach(() => {
  mockFetchOCS.mockReset();
  mockDeleteFile.mockReset();
});

describe('category', () => {
  it('joins the assistant category', () => {
    const names = assistantTools.map((t) => t.name);
    expect(names).toEqual(
      expect.arrayContaining(['list_assistant_skills', 'create_assistant_assignment'])
    );
  });
});

describe('skills', () => {
  it('lists name and description', async () => {
    mockFetchOCS.mockResolvedValue(
      ocs({ skills: [{ name: 'weekly-report', description: 'Write the weekly report' }] })
    );
    const res = await listAssistantSkillsTool.handler();
    expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/assistant/api/v1/skills');
    expect(res.content[0].text).toBe('- weekly-report: Write the weekly report');
  });

  it('treats a missing skills folder (404) as no skills', async () => {
    mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found - {}'));
    const res = await listAssistantSkillsTool.handler();
    expect(res).not.toHaveProperty('isError');
    expect(res.content[0].text).toMatch(/No skills yet/);
  });

  it('reads a skill by its encoded name', async () => {
    mockFetchOCS.mockResolvedValue(ocs({ content: '---\nname: Weekly Report\n---\n\nDo it.' }));
    const res = await getAssistantSkillTool.handler({ name: 'Weekly Report' });
    expect(mockFetchOCS).toHaveBeenCalledWith(
      '/ocs/v2.php/apps/assistant/api/v1/skills/Weekly%20Report'
    );
    expect(res.content[0].text).toContain('Do it.');
  });

  it('refuses a name that is more than one folder', () => {
    for (const bad of ['../x', 'a/b', '..', 'a\\b']) {
      expect(getAssistantSkillTool.inputSchema.safeParse({ name: bad }).success, bad).toBe(false);
    }
  });

  it('says whether a save created or replaced the skill', async () => {
    mockFetchOCS.mockResolvedValueOnce(ocs({ action: 'created' }));
    mockFetchOCS.mockResolvedValueOnce(ocs({ action: 'overwritten' }));
    const args = { name: 'triage', description: 'Triage new issues', content: '# Steps' };
    expect((await saveAssistantSkillTool.handler(args)).content[0].text).toBe(
      'Created skill "triage".'
    );
    expect((await saveAssistantSkillTool.handler(args)).content[0].text).toBe(
      'Replaced skill "triage".'
    );
    expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/assistant/api/v1/skills', {
      method: 'POST',
      jsonBody: { skillName: 'triage', description: 'Triage new issues', content: '# Steps' },
    });
  });
});

describe('delete_assistant_skill', () => {
  it('deletes the folder inside the Assistant folder the API names', async () => {
    mockFetchOCS
      .mockResolvedValueOnce(ocs({ content: '---' }))
      .mockResolvedValueOnce(ocs({ path: '/alice/files/Assistent' }));
    const res = await deleteAssistantSkillTool.handler({ name: 'triage' });
    expect(mockDeleteFile).toHaveBeenCalledWith('/Assistent/Context Agent/Skills/triage');
    expect(res.content[0].text).toMatch(/Deleted skill "triage"/);
  });

  it('deletes nothing for an unknown skill', async () => {
    mockFetchOCS.mockRejectedValue(new Error('OCS API error: 404 Not Found - {}'));
    const res = await deleteAssistantSkillTool.handler({ name: 'nope' });
    expect(res).toMatchObject({ isError: true });
    expect(mockDeleteFile).not.toHaveBeenCalled();
  });

  it('explains that a shared skill cannot be deleted', async () => {
    mockFetchOCS
      .mockResolvedValueOnce(ocs({ content: '---' }))
      .mockResolvedValueOnce(ocs({ path: '/alice/files/Assistant' }));
    mockDeleteFile.mockRejectedValue(
      Object.assign(new Error('Invalid response: 404'), { status: 404 })
    );
    const res = await deleteAssistantSkillTool.handler({ name: 'company-style' });
    expect(res.content[0].text).toMatch(/admin shares cannot be deleted/);
  });
});

describe('RFC 5545 §3.3.10 — recurrence rules', () => {
  it.each([
    'FREQ=DAILY',
    'FREQ=WEEKLY;BYDAY=MO,TH',
    'freq=monthly;BYMONTHDAY=-1',
    'RRULE:FREQ=YEARLY',
  ])('accepts %s', (rule) => expect(recurrenceProblem(rule)).toBeUndefined());

  it.each([
    ['BYDAY=MO', /exactly one FREQ/],
    ['FREQ=DAILY;FREQ=WEEKLY', /exactly one FREQ/],
    ['FREQ=FORTNIGHTLY', /FREQ must be one of/],
    ['FREQ=DAILY;COUNT', /NAME=value/],
    ['FREQ=DAILY; COUNT=2', /may only contain/],
    ['FREQ=WEEKLY;BYDAY=XX', /not a weekday/],
    ['FREQ=MONTHLY;BYDAY=54MO', /not a weekday/],
  ])('rejects %s', (rule, why) => expect(recurrenceProblem(rule)).toMatch(why));
});

describe('assignments', () => {
  it('lists assignments readably', async () => {
    mockFetchOCS.mockResolvedValue(ocs({ assignments: [ASSIGNMENT] }));
    const res = await listAssistantAssignmentsTool.handler();
    expect(res.content[0].text).toContain('[7] FREQ=WEEKLY;BYDAY=MO (Europe/Berlin)');
    expect(res.content[0].text).toContain('last run: never');
  });

  it('creates with the start as Unix seconds and the RRULE without its prefix', async () => {
    mockFetchOCS.mockResolvedValue(ocs({ assignment: ASSIGNMENT }));
    const args = createAssistantAssignmentTool.inputSchema.parse({
      title: 'Monday mail',
      prompt: 'Summarise my unread mail',
      startsAt: '2026-11-16T08:00:00+01:00',
      recurrence: 'RRULE:FREQ=WEEKLY;BYDAY=MO',
      timezone: 'Europe/Berlin',
    });
    await createAssistantAssignmentTool.handler(args);
    expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/assistant/assignments', {
      method: 'POST',
      jsonBody: {
        title: 'Monday mail',
        prompt: 'Summarise my unread mail',
        startsAt: Date.UTC(2026, 10, 16, 7, 0, 0) / 1000,
        recurrence: 'FREQ=WEEKLY;BYDAY=MO',
        timezone: 'Europe/Berlin',
      },
    });
  });

  it('requires an offset on the start time', () => {
    const r = createAssistantAssignmentTool.inputSchema.safeParse({
      title: 't',
      prompt: 'p',
      startsAt: '2026-11-16T08:00:00',
      recurrence: 'FREQ=DAILY',
    });
    expect(r.success).toBe(false);
  });

  it('explains a 400 from the Assistant', async () => {
    mockFetchOCS.mockRejectedValue(new Error('OCS API error: 400 Bad Request - '));
    const res = await createAssistantAssignmentTool.handler({
      title: 't',
      prompt: 'p',
      startsAt: '2026-11-16T08:00:00Z',
      recurrence: 'FREQ=DAILY',
    });
    expect(res).toMatchObject({ isError: true });
    expect(res.content[0].text).toMatch(/rejected the recurrence rule/);
  });

  it('updates only the fields given, and refuses an empty update', async () => {
    mockFetchOCS.mockResolvedValue(ocs({ assignment: ASSIGNMENT }));
    await updateAssistantAssignmentTool.handler({ id: 7, prompt: 'New prompt' });
    expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/assistant/assignments/7', {
      method: 'PATCH',
      jsonBody: { prompt: 'New prompt' },
    });
    const empty = await updateAssistantAssignmentTool.handler({ id: 7 });
    expect(empty).toMatchObject({ isError: true });
    expect(mockFetchOCS).toHaveBeenCalledTimes(1);
  });

  it('deletes by ID', async () => {
    mockFetchOCS.mockResolvedValue(ocs(''));
    const res = await deleteAssistantAssignmentTool.handler({ id: 7 });
    expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/apps/assistant/assignments/7', {
      method: 'DELETE',
    });
    expect(res.content[0].text).toBe('Deleted assignment 7.');
  });
});
