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

describe('Security & Integrity Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('check_core_integrity', () => {
    it('should return success on passing check', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: '[]',
        stderr: '',
      });

      const { checkCoreIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkCoreIntegrityTool.handler();

      expect(result.content[0].text).toContain('Core integrity check passed');
      expect(result.isError).toBe(false);
    });

    it('should report failure with exit code', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '{"EXTRA_FILE": {"lib/bad.php": {}}}',
        stderr: '',
      });

      const { checkCoreIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkCoreIntegrityTool.handler();

      expect(result.content[0].text).toContain('found issues');
      expect(result.content[0].text).toContain('EXTRA_FILE');
      expect(result.isError).toBe(true);
    });

    it('should include stderr when present', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '',
        stderr: 'Warning: something went wrong',
      });

      const { checkCoreIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkCoreIntegrityTool.handler();

      expect(result.content[0].text).toContain('stderr');
      expect(result.content[0].text).toContain('Warning: something went wrong');
    });

    it('should handle thrown errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('Connection refused'));

      const { checkCoreIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkCoreIntegrityTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error checking core integrity');
    });
  });

  describe('check_app_integrity', () => {
    it('should return success for a valid app', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: '[]',
        stderr: '',
      });

      const { checkAppIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkAppIntegrityTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('"tasks" integrity check passed');
      expect(result.isError).toBe(false);
      expect(mockExecuteOCC).toHaveBeenCalledWith('integrity:check-app', ['tasks']);
    });

    it('should report failure for a tampered app', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '{"INVALID_HASH": {"lib/Controller.php": {}}}',
        stderr: '',
      });

      const { checkAppIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkAppIntegrityTool.handler({ appId: 'deck' });

      expect(result.content[0].text).toContain('"deck" integrity check found issues');
      expect(result.isError).toBe(true);
    });

    it('should handle thrown errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('OCC not available'));

      const { checkAppIntegrityTool } = await import('../tools/system/security.js');
      const result = await checkAppIntegrityTool.handler({ appId: 'photos' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Error checking app integrity');
      expect(result.content[0].text).toContain('"photos"');
    });
  });
});
