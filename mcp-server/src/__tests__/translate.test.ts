// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock fetch for OCS
global.fetch = vi.fn();

describe('Translate Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('translate_text', () => {
    it('should list available language pairs when no text is provided', async () => {
      const ocsResponse = {
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {
            languages: [
              { from: 'en', to: 'de' },
              { from: 'de', to: 'en' },
              { from: 'en', to: 'fr' },
            ],
          },
        },
      };

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve(ocsResponse),
        text: () => Promise.resolve(JSON.stringify(ocsResponse)),
      });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({});

      expect(result.content[0].text).toContain('"from": "en"');
      expect(result.content[0].text).toContain('"to": "de"');
      expect(result.isError).toBeUndefined();
    });

    it('should return message when no translation providers are configured', async () => {
      const ocsResponse = {
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { languages: [] },
        },
      };

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve(ocsResponse),
        text: () => Promise.resolve(JSON.stringify(ocsResponse)),
      });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({});

      expect(result.content[0].text).toBe('No translation providers are configured in Nextcloud.');
    });

    it('should translate text when all parameters are provided', async () => {
      const ocsResponse = {
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { text: 'Hallo Welt' },
        },
      };

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve(ocsResponse),
        text: () => Promise.resolve(JSON.stringify(ocsResponse)),
      });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello World',
        fromLanguage: 'en',
        toLanguage: 'de',
      });

      expect(result.content[0].text).toBe('Hallo Welt');
      expect(result.isError).toBeUndefined();
    });

    it('should return error when text is provided without fromLanguage', async () => {
      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello',
        toLanguage: 'de',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Both fromLanguage and toLanguage are required');
    });

    it('should return error when text is provided without toLanguage', async () => {
      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello',
        fromLanguage: 'en',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Both fromLanguage and toLanguage are required');
    });

    it('should handle API errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 500,
        statusText: 'Internal Server Error',
        text: () => Promise.resolve('Server error'),
      });

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello',
        fromLanguage: 'en',
        toLanguage: 'de',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error translating text');
    });

    it('should handle network errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { translateTextTool } = await import('../tools/apps/translate.js');
      const result = await translateTextTool.handler({
        text: 'Hello',
        fromLanguage: 'en',
        toLanguage: 'de',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error translating text');
      expect(result.content[0].text).toContain('Connection refused');
    });
  });
});
