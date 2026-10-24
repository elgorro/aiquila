// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the AIquila API client module
const mockFetchAiquilaAPI = vi.fn();

vi.mock('../client/aiquila.js', () => ({
  fetchAiquilaAPI: (...args: unknown[]) => mockFetchAiquilaAPI(...args),
}));

import {
  listCoworkersTool,
  listCoworkerTemplatesTool,
  createCoworkerTool,
  updateCoworkerTool,
  deleteCoworkerTool,
  pauseCoworkerTool,
  runCoworkerTool,
  getCoworkerRunsTool,
  getCoworkerTool,
  setCoworkerScheduleTool,
  stopCoworkerTool,
  getCoworkerRunTool,
} from '../tools/apps/cowork.js';

const sampleCoworker = {
  id: 7,
  title: 'Classify images — Pixtral',
  provider: 'mistral',
  model: null,
  taskType: 'vision:classify',
  cronSchedule: '0 3 * * *',
  inputType: 'folder',
  inputPath: '/Photos',
  outputType: 'system_tags',
  isActive: true,
  paused: false,
  lastRunAt: null,
  nextRunAt: 1800000000,
  lastStatus: null,
  lastError: null,
};

describe('Cowork Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_coworkers', () => {
    it('formats a coworker list', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([sampleCoworker]);
      const res = await listCoworkersTool.handler();
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers');
      expect(res.content[0].text).toContain('Classify images — Pixtral');
      expect(res.content[0].text).toContain('provider: mistral');
      expect(res).not.toHaveProperty('isError');
    });

    it('handles the empty case', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([]);
      const res = await listCoworkersTool.handler();
      expect(res.content[0].text).toContain('No coworkers configured');
    });

    it('surfaces errors', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('boom'));
      const res = await listCoworkersTool.handler();
      expect(res.isError).toBe(true);
      expect(res.content[0].text).toContain('boom');
    });
  });

  describe('list_coworker_templates', () => {
    it('lists templates and task types', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        templates: [{ id: 'classify-images-claude', title: 'Classify — Claude', description: 'd' }],
        taskTypes: [{ id: 'vision:classify', label: 'Classify images', family: 'vision' }],
      });
      const res = await listCoworkerTemplatesTool.handler();
      expect(res.content[0].text).toContain('classify-images-claude');
      expect(res.content[0].text).toContain('vision:classify');
    });
  });

  describe('create_coworker', () => {
    it('creates from a template via the templates endpoint', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(sampleCoworker);
      const res = await createCoworkerTool.handler({ templateId: 'classify-images-mistral' });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/templates', {
        method: 'POST',
        body: { templateId: 'classify-images-mistral' },
      });
      expect(res.content[0].text).toContain('Coworker created');
    });

    it('creates a custom coworker with mapped fields and options', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(sampleCoworker);
      await createCoworkerTool.handler({
        title: 'My job',
        provider: 'anthropic',
        inputPath: '/Albums',
        cronSchedule: '0 4 * * *',
        maxTags: 5,
        recursive: false,
      });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers', {
        method: 'POST',
        body: {
          title: 'My job',
          provider: 'anthropic',
          input_type: 'folder',
          input_path: '/Albums',
          cron_schedule: '0 4 * * *',
          options: { maxTags: 5, recursive: false },
        },
      });
    });

    it('maps description, output, tuning, generic options and agent fields', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        ...sampleCoworker,
        customPrompt: 'Summarise unread mail',
        promptId: null,
      });
      const res = await createCoworkerTool.handler({
        taskType: 'agent:run',
        description: 'Daily digest',
        outputType: 'file',
        outputPath: '/Notes/daily.md',
        effort: 'low',
        thinking: 'off',
        options: { targetLanguage: 'de', maxTags: 1 },
        maxTags: 3,
        prompt: 'Summarise unread mail',
        tools: ['mail_list_messages'],
      });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers', {
        method: 'POST',
        body: {
          task_type: 'agent:run',
          description: 'Daily digest',
          output_type: 'file',
          output_path: '/Notes/daily.md',
          custom_prompt: 'Summarise unread mail',
          options: {
            targetLanguage: 'de',
            maxTags: 3,
            effort: 'low',
            thinking: 'off',
            tools: ['mail_list_messages'],
          },
        },
      });
      expect(res.content[0].text).not.toContain('ignored the prompt');
    });

    it('warns when the app ignores the prompt', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({ ...sampleCoworker, customPrompt: null });
      const res = await createCoworkerTool.handler({ title: 'x', prompt: 'Do things' });
      expect(res.content[0].text).toContain('ignored the prompt');
    });
  });

  describe('update_coworker', () => {
    it('PUTs only provided fields', async () => {
      mockFetchAiquilaAPI.mockResolvedValue(sampleCoworker);
      await updateCoworkerTool.handler({ id: 7, provider: 'mistral' });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledTimes(1);
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7', {
        method: 'PUT',
        body: { provider: 'mistral' },
      });
    });

    it('merges option changes into the stored options', async () => {
      mockFetchAiquilaAPI
        .mockResolvedValueOnce({
          ...sampleCoworker,
          options: { maxTags: 5, recursive: true, targetLanguage: 'fr' },
        })
        .mockResolvedValueOnce(sampleCoworker);
      await updateCoworkerTool.handler({ id: 7, maxTags: 2, isActive: false });
      expect(mockFetchAiquilaAPI).toHaveBeenNthCalledWith(1, '/coworkers/7');
      expect(mockFetchAiquilaAPI).toHaveBeenNthCalledWith(2, '/coworkers/7', {
        method: 'PUT',
        body: {
          is_active: false,
          options: { maxTags: 2, recursive: true, targetLanguage: 'fr' },
        },
      });
    });
  });

  describe('get_coworker', () => {
    it('shows options, prompt and tool allowlist', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        ...sampleCoworker,
        options: { tools: ['list_files'], effort: 'low' },
        customPrompt: 'Line one\nLine two',
      });
      const res = await getCoworkerTool.handler({ id: 7 });
      const out = res.content[0].text;
      expect(out).toContain('tools: list_files');
      expect(out).toContain('effort: low');
      expect(out).toContain('"effort": "low"');
      expect(out).toContain('Line one\nLine two');
    });
  });

  describe('set_coworker_schedule', () => {
    it('PUTs the cron schedule and reports the next run', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        ...sampleCoworker,
        cronSchedule: '30 6 * * 1-5',
        nextRunAt: 1800000000,
      });
      const res = await setCoworkerScheduleTool.handler({ id: 7, cronSchedule: '30 6 * * 1-5' });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7', {
        method: 'PUT',
        body: { cron_schedule: '30 6 * * 1-5' },
      });
      expect(res.content[0].text).toContain('30 6 * * 1-5');
      expect(res.content[0].text).toContain(new Date(1800000000 * 1000).toISOString());
    });
  });

  describe('delete_coworker', () => {
    it('DELETEs by id', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({ deleted: true });
      const res = await deleteCoworkerTool.handler({ id: 7 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7', { method: 'DELETE' });
      expect(res.content[0].text).toContain('deleted');
    });
  });

  describe('pause_coworker', () => {
    it('POSTs to the pause action', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({ ...sampleCoworker, paused: true });
      const res = await pauseCoworkerTool.handler({ id: 7 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7/pause', { method: 'POST' });
      expect(res.content[0].text).toContain('paused');
    });
  });

  describe('run_coworker', () => {
    it('runs now and reports progress', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        id: 1,
        coworkerId: 7,
        status: 'success',
        itemsTotal: 3,
        itemsProcessed: 3,
        summary: 'Processed 3/3 image file(s) via mistral.',
        error: null,
        startedAt: 1700000000,
        finishedAt: 1700000050,
      });
      const res = await runCoworkerTool.handler({ id: 7 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7/run', { method: 'POST' });
      expect(res.content[0].text).toContain('processed 3/3');
      expect(res.content[0].text).toContain('success');
    });
  });

  describe('get_coworker_runs', () => {
    it('lists run history', async () => {
      mockFetchAiquilaAPI.mockResolvedValue([
        {
          id: 2,
          coworkerId: 7,
          status: 'success',
          itemsTotal: 5,
          itemsProcessed: 5,
          summary: 'ok',
          error: null,
          startedAt: 1700000000,
          finishedAt: 1700000100,
        },
      ]);
      const res = await getCoworkerRunsTool.handler({ id: 7 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7/runs', {
        queryParams: undefined,
      });
      expect(res.content[0].text).toContain('#2 success');
    });
  });

  const notFound = Object.assign(new Error('AIquila API error: 404 Not Found'), {
    statusCode: 404,
  });

  describe('set_coworker_schedule while disabled', () => {
    it('says no run is scheduled', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        ...sampleCoworker,
        isActive: false,
        nextRunAt: null,
      });
      const res = await setCoworkerScheduleTool.handler({ id: 7, cronSchedule: '0 1 * * *' });
      expect(res.content[0].text).toContain('no run scheduled while the coworker is disabled');
    });
  });

  describe('stop_coworker', () => {
    it('POSTs to stop and reports the cancelled run', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        id: 9,
        coworkerId: 7,
        status: 'cancelled',
        itemsTotal: 10,
        itemsProcessed: 4,
        startedAt: 1700000000,
        finishedAt: 1700000030,
      });
      const res = await stopCoworkerTool.handler({ id: 7 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7/stop', { method: 'POST' });
      expect(res.content[0].text).toContain('Run #9 cancelled after 4/10');
    });

    it('explains a 404', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(notFound);
      const res = await stopCoworkerTool.handler({ id: 7 });
      expect(res.isError).toBe(true);
      expect(res.content[0].text).toContain('no active run');
      expect(res.content[0].text).toContain('update the app');
    });

    it('passes other errors through', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(new Error('boom'));
      const res = await stopCoworkerTool.handler({ id: 7 });
      expect(res.content[0].text).toBe('Error stopping runs: boom');
    });
  });

  describe('get_coworker_run', () => {
    it('renders summary, error and log', async () => {
      mockFetchAiquilaAPI.mockResolvedValue({
        id: 3,
        coworkerId: 7,
        status: 'error',
        itemsTotal: 2,
        itemsProcessed: 1,
        summary: 'Tagged 1 image.\nSecond line',
        error: 'Provider timeout',
        startedAt: 1700000000,
        finishedAt: 1700000090,
        log: [
          { ts: 1700000001, level: 'info', message: 'Tagged: beach', item: '/Photos/a.jpg' },
          { ts: 1700000002, level: 'error', message: 'Provider timeout' },
        ],
      });
      const res = await getCoworkerRunTool.handler({ id: 7, runId: 3 });
      expect(mockFetchAiquilaAPI).toHaveBeenCalledWith('/coworkers/7/runs/3');
      const out = res.content[0].text;
      expect(out).toContain('#3 error — 1/2');
      expect(out).toContain('(90s)');
      expect(out).toContain('Second line');
      expect(out).toContain('Provider timeout');
      expect(out).toContain('[info] /Photos/a.jpg: Tagged: beach');
      expect(out).toContain('Log (2 entries)');
    });

    it('explains a 404', async () => {
      mockFetchAiquilaAPI.mockRejectedValue(notFound);
      const res = await getCoworkerRunTool.handler({ id: 7, runId: 3 });
      expect(res.isError).toBe(true);
      expect(res.content[0].text).toContain('Run 3 of coworker 7 not found');
    });
  });
});
