// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchPassmanAPI = vi.fn();
vi.mock('../client/passman.js', () => ({
  fetchPassmanAPI: (...args: unknown[]) => mockFetchPassmanAPI(...args),
}));

describe('Passman Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('passman_list_vaults', () => {
    it('lists vaults with metadata', async () => {
      mockFetchPassmanAPI.mockResolvedValue([
        { vault_id: 1, guid: 'V1', name: 'Personal', created: 1700000000, last_access: 1700001000 },
        { vault_id: 2, guid: 'V2', name: 'Work', created: 1700002000, last_access: 0 },
      ]);

      const { listVaultsTool } = await import('../tools/apps/passman.js');
      const result = await listVaultsTool.handler();

      expect(result.content[0].text).toContain('Vaults (2)');
      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('end-to-end encrypted');
    });

    it('handles empty vault list', async () => {
      mockFetchPassmanAPI.mockResolvedValue([]);
      const { listVaultsTool } = await import('../tools/apps/passman.js');
      const result = await listVaultsTool.handler();
      expect(result.content[0].text).toBe('No vaults found.');
    });
  });

  describe('passman_list_credentials', () => {
    it('lists credential metadata without secret fields', async () => {
      mockFetchPassmanAPI.mockResolvedValue({
        guid: 'V1',
        name: 'Personal',
        credentials: [
          {
            credential_id: 10,
            guid: 'C10',
            vault_id: 1,
            label: 'GitHub',
            created: 1700000000,
            changed: 1700000000,
            url: 'ENCRYPTED_CIPHERTEXT_URL',
            password: 'ENCRYPTED_CIPHERTEXT_PW',
            username: 'ENCRYPTED_CIPHERTEXT_USER',
            tags: ['x'],
            compromised: true,
          },
        ],
      });

      const { listCredentialsTool } = await import('../tools/apps/passman.js');
      const result = await listCredentialsTool.handler({ vault_guid: 'V1' });

      expect(result.content[0].text).toContain('GitHub');
      expect(result.content[0].text).toContain('has url');
      expect(result.content[0].text).toContain('compromised');
      // ciphertext / secret values must never leak
      expect(result.content[0].text).not.toContain('ENCRYPTED_CIPHERTEXT');
    });

    it('handles empty vault', async () => {
      mockFetchPassmanAPI.mockResolvedValue({ guid: 'V1', name: 'Personal', credentials: [] });
      const { listCredentialsTool } = await import('../tools/apps/passman.js');
      const result = await listCredentialsTool.handler({ vault_guid: 'V1' });
      expect(result.content[0].text).toBe('No credentials in this vault.');
    });

    it('handles 404 via status map', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchPassmanAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { listCredentialsTool } = await import('../tools/apps/passman.js');
      const result = await listCredentialsTool.handler({ vault_guid: 'nope' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toBe('Not found.');
    });
  });

  describe('passman_get_credential_info', () => {
    it('returns metadata for a matching credential', async () => {
      mockFetchPassmanAPI.mockResolvedValue({
        guid: 'V1',
        name: 'Personal',
        credentials: [
          {
            credential_id: 10,
            guid: 'C10',
            vault_id: 1,
            label: 'GitHub',
            created: 1700000000,
            changed: 1700000500,
            password: 'ENCRYPTED_CIPHERTEXT_PW',
          },
        ],
      });

      const { getCredentialInfoTool } = await import('../tools/apps/passman.js');
      const result = await getCredentialInfoTool.handler({
        vault_guid: 'V1',
        credential_guid: 'C10',
      });

      expect(result.content[0].text).toContain('# GitHub');
      expect(result.content[0].text).toContain('ID: 10');
      expect(result.content[0].text).not.toContain('ENCRYPTED_CIPHERTEXT');
    });

    it('errors when credential not in vault', async () => {
      mockFetchPassmanAPI.mockResolvedValue({ guid: 'V1', name: 'Personal', credentials: [] });
      const { getCredentialInfoTool } = await import('../tools/apps/passman.js');
      const result = await getCredentialInfoTool.handler({
        vault_guid: 'V1',
        credential_guid: 'missing',
      });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });
});
