// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

global.fetch = vi.fn();

const ocs = (data: unknown) => {
  const body = { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
  return {
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  };
};

const TRANSLATE_TYPE = {
  'core:text2text:translate': {
    inputShapeEnumValues: {
      origin_language: [
        { name: 'Detect language', value: 'detect_language' },
        { name: 'English', value: 'en' },
      ],
      target_language: [
        { name: 'English', value: 'en' },
        { name: 'Deutsch', value: 'de' },
      ],
    },
  },
};

/** Route fetch by URL; `types` is what /taskprocessing/tasktypes reports. */
function route(types: Record<string, unknown>, handlers: Record<string, () => unknown>) {
  (global.fetch as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
    if (url.includes('/taskprocessing/tasktypes')) return Promise.resolve(ocs({ types }));
    for (const [fragment, handler] of Object.entries(handlers)) {
      if (url.includes(fragment)) return Promise.resolve(ocs(handler()));
    }
    return Promise.reject(new Error(`unexpected fetch ${url}`));
  });
}

const calls = () => (global.fetch as ReturnType<typeof vi.fn>).mock.calls;

describe('Translate Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  describe('with a TaskProcessing translate provider', () => {
    it('should list the task type languages when no text is provided', async () => {
      route(TRANSLATE_TYPE, {});

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({});

      expect(result.content[0].text).toContain('Source languages: detect_language');
      expect(result.content[0].text).toContain('Target languages: en (English), de (Deutsch)');
      expect(calls().some(([url]) => url.includes('/translation/'))).toBe(false);
    });

    it('should schedule a translate task and return its output', async () => {
      route(TRANSLATE_TYPE, {
        '/taskprocessing/schedule': () => ({
          task: { id: 7, type: 'core:text2text:translate', status: 'STATUS_SCHEDULED' },
        }),
        '/taskprocessing/task/7': () => ({
          task: { id: 7, status: 'STATUS_SUCCESSFUL', output: { output: 'Hallo Welt' } },
        }),
      });
      vi.useFakeTimers();

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const pending = translateTextTool.handler({ text: 'Hello world', toLanguage: 'de' });
      await vi.advanceTimersByTimeAsync(1_000);
      const result = await pending;

      expect(result.content[0].text).toBe('Hallo Welt');
      const schedule = calls().find(([url]) => url.includes('/taskprocessing/schedule'));
      expect(JSON.parse(schedule![1].body)).toEqual({
        type: 'core:text2text:translate',
        appId: 'aiquila-mcp',
        input: { input: 'Hello world', origin_language: 'detect_language', target_language: 'de' },
      });
    });

    it('should report a failed task as an error', async () => {
      route(TRANSLATE_TYPE, {
        '/taskprocessing/schedule': () => ({
          task: { id: 8, status: 'STATUS_FAILED', userFacingErrorMessage: 'Quota exceeded' },
        }),
      });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({ text: 'x', toLanguage: 'de' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Quota exceeded');
    });

    it('should hand back the task ID when the task outlasts the wait', async () => {
      route(TRANSLATE_TYPE, {
        '/taskprocessing/schedule': () => ({ task: { id: 9, status: 'STATUS_SCHEDULED' } }),
        '/taskprocessing/task/9': () => ({ task: { id: 9, status: 'STATUS_RUNNING' } }),
      });
      vi.useFakeTimers();

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const pending = translateTextTool.handler({ text: 'x', toLanguage: 'de' });
      await vi.advanceTimersByTimeAsync(31_000);
      const result = await pending;

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('get_task_result with taskId 9');
    });
  });

  describe('without a TaskProcessing translate provider', () => {
    it('should list legacy language pairs', async () => {
      route({}, { '/translation/languages': () => ({ languages: [{ from: 'en', to: 'de' }] }) });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({});

      expect(result.content[0].text).toContain('"from": "en"');
    });

    it('should say when no provider is configured', async () => {
      route({}, { '/translation/languages': () => ({ languages: [] }) });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({});

      expect(result.content[0].text).toBe('No translation providers are configured in Nextcloud.');
    });

    it('should translate through the legacy Translation API', async () => {
      route({}, { '/translation/translate': () => ({ text: 'Hallo Welt' }) });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello world',
        fromLanguage: 'en',
        toLanguage: 'de',
      });

      expect(result.content[0].text).toBe('Hallo Welt');
      const call = calls().find(([url]) => url.includes('/translation/translate'));
      expect(call![1].method).toBe('POST');
      expect(call![1].body).toContain('fromLanguage=en');
    });
  });

  it('should require toLanguage when text is given', async () => {
    route(TRANSLATE_TYPE, {});

    const { translateTextTool } = await import('../tools/apps/translate.js');
    const result = await translateTextTool.handler({ text: 'Hello' });

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('toLanguage is required');
  });

  it('should return an error when the API fails', async () => {
    (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

    const { translateTextTool } = await import('../tools/apps/translate.js');
    const result = await translateTextTool.handler({ text: 'Hello', toLanguage: 'de' });

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('Network error');
  });
});
