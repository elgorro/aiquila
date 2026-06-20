// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const OCS_BASE = '/ocs/v2.php/apps/terms_of_service';
const APPCONFIG_BASE = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/terms_of_service';

function ok(data: unknown) {
  return { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
}

describe('Terms of Service Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('get_terms_of_service', () => {
    it('should fetch admin form data and format terms, settings and codes', async () => {
      mockFetchOCS.mockResolvedValue(
        ok({
          terms: [{ id: 3, countryCode: '--', languageCode: 'en', body: 'Be nice.' }],
          countries: { '--': 'Global', DE: 'Germany' },
          languages: { en: 'English', de: 'German' },
          tos_on_public_shares: '0',
          tos_for_users: '1',
        })
      );

      const { getTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await getTermsOfServiceTool.handler();

      expect(mockFetchOCS).toHaveBeenCalledWith(`${OCS_BASE}/terms/admin`);
      expect(result.content[0].text).toContain('#3 [--/en] Be nice.');
      expect(result.content[0].text).toContain('tos_for_users: 1');
      expect(result.content[0].text).toContain('tos_on_public_shares: 0');
      expect(result.content[0].text).toContain('DE');
      expect(result.content[0].text).toContain('de');
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { getTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await getTermsOfServiceTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });

  describe('set_terms_of_service', () => {
    it('should POST country/language/body and report the saved id', async () => {
      mockFetchOCS.mockResolvedValue(
        ok({ id: 7, countryCode: 'DE', languageCode: 'de', body: 'x' })
      );

      const { setTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await setTermsOfServiceTool.handler({
        countryCode: 'DE',
        languageCode: 'de',
        body: 'x',
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${OCS_BASE}/terms`, {
        method: 'POST',
        body: { countryCode: 'DE', languageCode: 'de', body: 'x' },
      });
      expect(result.content[0].text).toContain('Saved terms #7 for [DE/de]');
    });

    it('should default countryCode to "--"', async () => {
      mockFetchOCS.mockResolvedValue(
        ok({ id: 1, countryCode: '--', languageCode: 'en', body: 'x' })
      );

      const { setTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      await setTermsOfServiceTool.handler({ languageCode: 'en', body: 'x' });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${OCS_BASE}/terms`, {
        method: 'POST',
        body: { countryCode: '--', languageCode: 'en', body: 'x' },
      });
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('417'));

      const { setTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await setTermsOfServiceTool.handler({ languageCode: 'zz', body: 'x' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('417');
    });
  });

  describe('delete_terms_of_service', () => {
    it('should DELETE the term by id', async () => {
      mockFetchOCS.mockResolvedValue(ok([]));

      const { deleteTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await deleteTermsOfServiceTool.handler({ id: 5 });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${OCS_BASE}/terms/5`, { method: 'DELETE' });
      expect(result.content[0].text).toContain('Deleted terms #5');
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('nope'));

      const { deleteTermsOfServiceTool } = await import('../tools/apps/terms-of-service.js');
      const result = await deleteTermsOfServiceTool.handler({ id: 5 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('nope');
    });
  });

  describe('reset_terms_signatures', () => {
    it('should DELETE the sign endpoint', async () => {
      mockFetchOCS.mockResolvedValue(ok([]));

      const { resetTermsSignaturesTool } = await import('../tools/apps/terms-of-service.js');
      const result = await resetTermsSignaturesTool.handler();

      expect(mockFetchOCS).toHaveBeenCalledWith(`${OCS_BASE}/sign`, { method: 'DELETE' });
      expect(result.content[0].text).toContain('Reset all terms of service signatures');
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { resetTermsSignaturesTool } = await import('../tools/apps/terms-of-service.js');
      const result = await resetTermsSignaturesTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });

  describe('update_terms_settings', () => {
    it('should write yes/no for the provided settings', async () => {
      mockFetchOCS.mockResolvedValue(ok(''));

      const { updateTermsSettingsTool } = await import('../tools/apps/terms-of-service.js');
      const result = await updateTermsSettingsTool.handler({
        tosForUsers: true,
        tosOnPublicShares: false,
      });

      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/tos_for_users`, {
        method: 'POST',
        body: { value: 'yes' },
      });
      expect(mockFetchOCS).toHaveBeenCalledWith(`${APPCONFIG_BASE}/tos_on_public_shares`, {
        method: 'POST',
        body: { value: 'no' },
      });
      expect(result.content[0].text).toContain('tos_for_users = yes');
      expect(result.content[0].text).toContain('tos_on_public_shares = no');
    });

    it('should error when no settings are provided', async () => {
      const { updateTermsSettingsTool } = await import('../tools/apps/terms-of-service.js');
      const result = await updateTermsSettingsTool.handler({});

      expect(result.isError).toBe(true);
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });

    it('should return isError on failure', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { updateTermsSettingsTool } = await import('../tools/apps/terms-of-service.js');
      const result = await updateTermsSettingsTool.handler({ tosForUsers: true });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });
});
