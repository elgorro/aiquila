import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the OCS client module
const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

// Mock the AIquila API client module
const mockExecuteOCC = vi.fn();
vi.mock('../client/aiquila.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/aiquila.js')>('../client/aiquila.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

describe('App Management Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_apps', () => {
    it('should return list of apps', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { apps: ['files', 'activity', 'photos', 'tasks'] },
        },
      });

      const { listAppsTool } = await import('../tools/system/apps.js');
      const result = await listAppsTool.handler({});

      expect(result.content[0].text).toContain('files');
      expect(result.content[0].text).toContain('tasks');
      expect(result).not.toHaveProperty('isError');
    });

    it('should pass filter parameter', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { apps: ['survey_client'] },
        },
      });

      const { listAppsTool } = await import('../tools/system/apps.js');
      await listAppsTool.handler({ filter: 'disabled' });

      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps', {
        queryParams: { filter: 'disabled' },
      });
    });

    it('should handle errors', async () => {
      mockFetchOCS.mockRejectedValue(
        new Error(
          'Permission denied. This operation requires admin or sub-admin privileges. ' +
            'Ensure the configured Nextcloud user has sufficient permissions.'
        )
      );

      const { listAppsTool } = await import('../tools/system/apps.js');
      const result = await listAppsTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Permission denied');
    });
  });

  describe('get_app_info', () => {
    it('should return app details', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: { id: 'tasks', name: 'Tasks', version: '0.15.0' },
        },
      });

      const { getAppInfoTool } = await import('../tools/system/apps.js');
      const result = await getAppInfoTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks');
      expect(result.content[0].text).toContain('0.15.0');
    });
  });

  describe('enable_app', () => {
    it('should enable an app successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { enableAppTool } = await import('../tools/system/apps.js');
      const result = await enableAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('enabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps/tasks', { method: 'POST' });
    });
  });

  describe('disable_app', () => {
    it('should disable an app successfully', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: {
          meta: { status: 'ok', statuscode: 200, message: 'OK' },
          data: {},
        },
      });

      const { disableAppTool } = await import('../tools/system/apps.js');
      const result = await disableAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('disabled successfully');
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/apps/tasks', {
        method: 'DELETE',
      });
    });
  });

  describe('install_app', () => {
    it('should install an app successfully', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks installed', stderr: '' });

      const { installAppTool } = await import('../tools/system/apps.js');
      const result = await installAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks installed');
      expect(result).not.toHaveProperty('isError');
      expect(mockExecuteOCC).toHaveBeenCalledWith('app:install', ['tasks'], 300);
    });

    it('should pass --keep-disabled flag when requested', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks installed', stderr: '' });

      const { installAppTool } = await import('../tools/system/apps.js');
      await installAppTool.handler({ appId: 'tasks', keepDisabled: true });

      expect(mockExecuteOCC).toHaveBeenCalledWith('app:install', ['tasks', '--keep-disabled'], 300);
    });

    it('should return error on failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        stdout: '',
        stderr: 'App not found in App Store',
      });

      const { installAppTool } = await import('../tools/system/apps.js');
      const result = await installAppTool.handler({ appId: 'nonexistent' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('App not found in App Store');
    });
  });

  describe('uninstall_app', () => {
    it('should remove an app successfully', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks removed', stderr: '' });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      const result = await uninstallAppTool.handler({ appId: 'tasks' });

      expect(result.content[0].text).toContain('tasks removed');
      expect(result).not.toHaveProperty('isError');
      expect(mockExecuteOCC).toHaveBeenCalledWith('app:remove', ['tasks'], 120);
    });

    it('should pass --keep-data flag when requested', async () => {
      mockExecuteOCC.mockResolvedValue({ success: true, stdout: 'tasks removed', stderr: '' });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      await uninstallAppTool.handler({ appId: 'tasks', keepData: true });

      expect(mockExecuteOCC).toHaveBeenCalledWith('app:remove', ['tasks', '--keep-data'], 120);
    });

    it('should return error on failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        stdout: '',
        stderr: 'App is not installed',
      });

      const { uninstallAppTool } = await import('../tools/system/apps.js');
      const result = await uninstallAppTool.handler({ appId: 'tasks' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('App is not installed');
    });
  });
});
