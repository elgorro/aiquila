// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const APPCONFIG_BASE = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/registration';

function ok(data: unknown) {
  return { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
}

describe('Registration Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('get_registration_settings', () => {
    it('should read each known key and format the result', async () => {
      mockFetchOCS.mockImplementation((path: string) => {
        if (path.endsWith('/admin_approval_required')) return Promise.resolve(ok('yes'));
        return Promise.resolve(ok(''));
      });

      const { getRegistrationSettingsTool } = await import('../tools/apps/registration.js');
      const result = await getRegistrationSettingsTool.handler();

      expect(result.content[0].text).toContain('admin_approval_required: yes');
      expect(result.content[0].text).toContain('allowed_domains: (default)');
      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/admin_approval_required`);
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { getRegistrationSettingsTool } = await import('../tools/apps/registration.js');
      const result = await getRegistrationSettingsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });

  describe('update_registration_settings', () => {
    it('should POST each setting with a form value body', async () => {
      mockFetchOCS.mockResolvedValue(ok(true));

      const { updateRegistrationSettingsTool } = await import('../tools/apps/registration.js');
      const result = await updateRegistrationSettingsTool.handler({
        settings: [
          { key: 'admin_approval_required', value: 'yes' },
          { key: 'registered_user_group', value: 'newcomers' },
        ],
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/admin_approval_required`, {
        method: 'POST',
        body: { value: 'yes' },
      });
      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/registered_user_group`, {
        method: 'POST',
        body: { value: 'newcomers' },
      });
      expect(result.content[0].text).toContain('admin_approval_required = yes');
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('denied'));

      const { updateRegistrationSettingsTool } = await import('../tools/apps/registration.js');
      const result = await updateRegistrationSettingsTool.handler({
        settings: [{ key: 'show_phone', value: 'no' }],
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('denied');
    });
  });

  describe('reset_registration_setting', () => {
    it('should DELETE the key', async () => {
      mockFetchOCS.mockResolvedValue(ok(true));

      const { resetRegistrationSettingTool } = await import('../tools/apps/registration.js');
      const result = await resetRegistrationSettingTool.handler({ key: 'allowed_domains' });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/allowed_domains`, {
        method: 'DELETE',
      });
      expect(result.content[0].text).toContain("Reset registration setting 'allowed_domains'");
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('nope'));

      const { resetRegistrationSettingTool } = await import('../tools/apps/registration.js');
      const result = await resetRegistrationSettingTool.handler({ key: 'allowed_domains' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('nope');
    });
  });
});
