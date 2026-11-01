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
      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', ['--show']);
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
      expect(result.isError).toBe(true);
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

    it('should pass effort and thinking, keeping empty strings as resets', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, exitCode: 0, stdout: 'ok', stderr: '' });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      await configureTool.handler({
        effort: '',
        thinking: 'off',
        taskEffort: 'low',
        taskThinking: '',
      });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', [
        '--effort',
        '',
        '--thinking',
        'off',
        '--task-effort',
        'low',
        '--task-thinking',
        '',
      ]);
    });

    it('should reject an unknown thinking mode', async () => {
      const { configureTool } = await import('../tools/apps/aiquila.js');
      expect(configureTool.inputSchema.safeParse({ thinking: 'maybe' }).success).toBe(false);
      expect(configureTool.inputSchema.safeParse({ thinking: 'auto' }).success).toBe(true);
    });

    it('configures a provider without activating it', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, exitCode: 0, stdout: 'ok', stderr: '' });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      await configureTool.handler({
        targetProvider: 'local',
        providerKey: 'tok',
        providerModel: 'qwen3',
        providerSettings: { local_base_url: 'http://llama:8080', timeout: '600' },
      });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', [
        '--target-provider',
        'local',
        '--provider-key',
        'tok',
        '--provider-model',
        'qwen3',
        '--provider-setting',
        'local_base_url=http://llama:8080',
        '--provider-setting',
        'timeout=600',
      ]);
    });

    it('activates a provider with provider', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, exitCode: 0, stdout: 'ok', stderr: '' });

      const { configureTool } = await import('../tools/apps/aiquila.js');
      await configureTool.handler({ provider: 'local' });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', ['--provider', 'local']);
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

  describe('aiquila_show_config targetProvider', () => {
    it('shows one provider by id', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, exitCode: 0, stdout: 'ok', stderr: '' });

      const { showConfigTool } = await import('../tools/apps/aiquila.js');
      await showConfigTool.handler({ targetProvider: 'local' });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:configure', [
        '--show',
        '--target-provider',
        'local',
      ]);
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

    it('passes provider and user through', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, exitCode: 0, stdout: 'ok', stderr: '' });

      const { testTool } = await import('../tools/apps/aiquila.js');
      await testTool.handler({ prompt: 'hi', provider: 'local', user: 'alice' });

      expect(mockExecuteOCC).toHaveBeenCalledWith('aiquila:test', [
        '--prompt',
        'hi',
        '--provider',
        'local',
        '--user',
        'alice',
      ]);
    });

    it('should handle errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('API timeout'));

      const { testTool } = await import('../tools/apps/aiquila.js');
      const result = await testTool.handler({ prompt: 'test' });

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('API timeout');
      expect(result.isError).toBe(true);
    });

    it('should surface the failure that aiquila:test prints on stdout', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '\n✗ Error: Invalid API key. Please check your configuration.\n',
        stderr: '',
      });

      const { testTool } = await import('../tools/apps/aiquila.js');
      const result = await testTool.handler({ prompt: 'test' });

      expect(result.content[0].text).toContain('Invalid API key');
      expect(result.isError).toBe(true);
    });
  });
});
