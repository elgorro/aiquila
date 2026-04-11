import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

describe('Assistant / AI Task Processing Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_text_tasks', () => {
    it('should return available task types', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            types: {
              'core:summarize': { name: 'Summarize', description: 'Summarize text' },
              'core:headline': { name: 'Headline', description: 'Generate a headline' },
            },
          },
        },
      });

      const { listTextTasksTool } = await import('../tools/apps/assistant.js');
      const result = await listTextTasksTool.handler();

      expect(result.content[0].text).toContain('Summarize');
      expect(result.content[0].text).toContain('Headline');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle empty task types', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { types: {} },
        },
      });

      const { listTextTasksTool } = await import('../tools/apps/assistant.js');
      const result = await listTextTasksTool.handler();

      expect(result.content[0].text).toContain('No AI task types');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('API error'));

      const { listTextTasksTool } = await import('../tools/apps/assistant.js');
      const result = await listTextTasksTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('API error');
    });
  });

  describe('process_text', () => {
    it('should schedule a task and return ID', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { task: { id: 42, type: 'core:summarize', status: 1 } },
        },
      });

      const { processTextTool } = await import('../tools/apps/assistant.js');
      const result = await processTextTool.handler({
        taskType: 'core:summarize',
        input: 'Long text to summarize',
      });

      expect(result.content[0].text).toContain('42');
      expect(result.content[0].text).toContain('scheduled');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/taskprocessing/schedule',
        expect.objectContaining({
          method: 'POST',
          jsonBody: expect.objectContaining({
            type: 'core:summarize',
            appId: 'aiquila-mcp',
            input: { input: 'Long text to summarize' },
          }),
        })
      );
    });

    it('should include customId when provided', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { task: { id: 43, type: 'core:summarize', status: 1 } },
        },
      });

      const { processTextTool } = await import('../tools/apps/assistant.js');
      await processTextTool.handler({
        taskType: 'core:summarize',
        input: 'text',
        customId: 'my-task-1',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/taskprocessing/schedule',
        expect.objectContaining({
          jsonBody: expect.objectContaining({ customId: 'my-task-1' }),
        })
      );
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Server error'));

      const { processTextTool } = await import('../tools/apps/assistant.js');
      const result = await processTextTool.handler({
        taskType: 'core:summarize',
        input: 'text',
      });

      expect(result.isError).toBe(true);
    });
  });

  describe('get_task_result', () => {
    it('should return result for successful task (text output)', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            task: {
              id: 42,
              status: 3,
              output: { output: 'This is the summary.' },
            },
          },
        },
      });

      const { getTaskResultTool } = await import('../tools/apps/assistant.js');
      const result = await getTaskResultTool.handler({ taskId: 42 });

      expect(result.content[0].text).toContain('completed successfully');
      expect(result.content[0].text).toContain('This is the summary.');
      expect(result).not.toHaveProperty('isError');
    });

    it('should stringify non-text output', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            task: {
              id: 42,
              status: 3,
              output: { topics: ['AI', 'ML'] },
            },
          },
        },
      });

      const { getTaskResultTool } = await import('../tools/apps/assistant.js');
      const result = await getTaskResultTool.handler({ taskId: 42 });

      expect(result.content[0].text).toContain('AI');
      expect(result.content[0].text).toContain('ML');
    });

    it('should return error for failed task', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            task: {
              id: 42,
              status: 4,
              errorMessage: 'Provider unavailable',
            },
          },
        },
      });

      const { getTaskResultTool } = await import('../tools/apps/assistant.js');
      const result = await getTaskResultTool.handler({ taskId: 42 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('failed');
      expect(result.content[0].text).toContain('Provider unavailable');
    });

    it('should return pending status for running task', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { task: { id: 42, status: 2 } },
        },
      });

      const { getTaskResultTool } = await import('../tools/apps/assistant.js');
      const result = await getTaskResultTool.handler({ taskId: 42 });

      expect(result.content[0].text).toContain('running');
      expect(result.content[0].text).toContain('Poll again');
      expect(result).not.toHaveProperty('isError');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Not found'));

      const { getTaskResultTool } = await import('../tools/apps/assistant.js');
      const result = await getTaskResultTool.handler({ taskId: 999 });

      expect(result.isError).toBe(true);
    });
  });

  describe('generate_image', () => {
    it('should schedule image generation task', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { task: { id: 50, status: 1 } },
        },
      });

      const { generateImageTool } = await import('../tools/apps/assistant.js');
      const result = await generateImageTool.handler({ prompt: 'A sunset over mountains' });

      expect(result.content[0].text).toContain('50');
      expect(result.content[0].text).toContain('scheduled');
      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/taskprocessing/schedule',
        expect.objectContaining({
          method: 'POST',
          jsonBody: expect.objectContaining({
            type: 'core:text2image',
            input: { input: 'A sunset over mountains', numberOfImages: 1 },
          }),
        })
      );
    });

    it('should include savePath in customId', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { task: { id: 51, status: 1 } },
        },
      });

      const { generateImageTool } = await import('../tools/apps/assistant.js');
      const result = await generateImageTool.handler({
        prompt: 'test',
        savePath: '/Photos/generated.png',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/taskprocessing/schedule',
        expect.objectContaining({
          jsonBody: expect.objectContaining({
            customId: 'savePath:/Photos/generated.png',
          }),
        })
      );
      expect(result.content[0].text).toContain('/Photos/generated.png');
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('No provider'));

      const { generateImageTool } = await import('../tools/apps/assistant.js');
      const result = await generateImageTool.handler({ prompt: 'test' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No provider');
    });
  });
});
