// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockExecuteOCC = vi.fn();

vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

describe('AIquila Internal Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('aiquila_show_config', () => {
    it('should return OCC output on success', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'api_key: sk-***\nmodel: claude-sonnet-4-5-20250929',
        stderr: '',
      });

      const { showConfigTool } = await import('../tools/apps/aiquila.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('api_key');
      expect(result.content[0].text).toContain('model');
      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:show', []);
    });

    it('should handle OCC failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '',
        stderr: 'Command not found',
        error: 'Command not found',
      });

      const { showConfigTool } = await import('../tools/apps/aiquila.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('Command not found');
    });

    it('should handle thrown errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('Network error'));

      const { showConfigTool } = await import('../tools/apps/aiquila.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('aiquila_configure', () => {
    it('should pass all config args', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Configuration updated',
        stderr: '',
      });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      const result = await configureTool.handler({
        apiKey: 'sk-test',
        model: 'claude-opus-4-6',
        maxTokens: 8192,
        timeout: 120,
      });

      expect(result.content[0].text).toContain('Configuration updated');
      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', [
        '--api-key',
        'sk-test',
        '--model',
        'claude-opus-4-6',
        '--max-tokens',
        '8192',
        '--timeout',
        '120',
      ]);
    });

    it('should only pass provided args', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Model updated',
        stderr: '',
      });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      await configureTool.handler({ model: 'claude-sonnet-4-5-20250929' });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', [
        '--model',
        'claude-sonnet-4-5-20250929',
      ]);
    });

    it('should handle errors', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '',
        stderr: 'Invalid API key',
        error: 'Invalid API key',
      });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      const result = await configureTool.handler({ apiKey: 'bad-key' });

      expect(result.content[0].text).toContain('Error');
    });
  });

  describe('aiquila_test', () => {
    it('should pass prompt to OCC', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Response: Hello! How can I help you?',
        stderr: '',
      });

      const { testTool } = await import('../tools/apps/aiquila.js');
      const result = await testTool.handler({ prompt: 'Say hello' });

      expect(result.content[0].text).toContain('Hello');
      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:test', ['--prompt', 'Say hello']);
    });

    it('should handle errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('API timeout'));

      const { testTool } = await import('../tools/apps/aiquila.js');
      const result = await testTool.handler({ prompt: 'test' });

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('API timeout');
    });
  });
});
