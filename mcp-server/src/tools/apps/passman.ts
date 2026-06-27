// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  fetchPassmanAPI,
  type PassmanVault,
  type PassmanCredential,
} from '../../client/passman.js';
import { ApiError } from '../../client/aiquila.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Passman App Tools (password manager)
 * Uses the Passman REST API v2 (/index.php/apps/passman/api/v2).
 *
 * IMPORTANT: Passman is zero-knowledge / end-to-end encrypted. Credential
 * secrets (password, username, url, email, description, custom fields, OTP,
 * tags, files) are encrypted client-side (SJCL AES-256) with a vault password
 * that never reaches the server. This integration therefore exposes vault and
 * credential *metadata only* (notably the plaintext `label`) and cannot decrypt
 * or return any secret value.
 */

const passmanStatusMap: Record<number, string | ((e: ApiError) => string)> = {
  404: 'Not found.',
  403: 'Permission denied.',
  400: (e) => `Bad request: ${e.responseBody}`,
};

const E2E_NOTE =
  'Note: secret values (password, username, url, etc.) are end-to-end encrypted and cannot be retrieved by this server.';

function formatVault(vault: PassmanVault): string {
  const count = vault.credentials?.length ?? undefined;
  const parts = [`guid: ${vault.guid}`];
  if (count !== undefined) parts.push(`${count} credentials`);
  parts.push(`created: ${new Date(vault.created * 1000).toISOString()}`);
  if (vault.last_access) {
    parts.push(`last access: ${new Date(vault.last_access * 1000).toISOString()}`);
  }
  return `${vault.name} (${parts.join(', ')})`;
}

function hasValue(v: unknown): boolean {
  if (v === undefined || v === null || v === '') return false;
  if (Array.isArray(v)) return v.length > 0;
  return true;
}

function formatCredential(cred: PassmanCredential): string {
  const flags: string[] = [];
  if (hasValue(cred.url)) flags.push('has url');
  if (hasValue(cred.tags)) flags.push('tagged');
  if (cred.hidden) flags.push('hidden');
  if (cred.compromised) flags.push('compromised');
  if (cred.expire_time) {
    flags.push(`expires: ${new Date(cred.expire_time * 1000).toISOString()}`);
  }
  const meta = [`guid: ${cred.guid}`, ...flags].join(', ');
  return `[${cred.credential_id}] ${cred.label} (${meta})`;
}

export const listVaultsTool = {
  name: 'passman_list_vaults',
  description: `List all Passman password vaults. Returns name, guid, creation/last-access time and credential count. ${E2E_NOTE}`,
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const vaults = await fetchPassmanAPI<PassmanVault[]>('/vaults');

      if (!vaults || vaults.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No vaults found.' }] };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Vaults (${vaults.length}):\n\n${vaults.map(formatVault).join('\n')}\n\n${E2E_NOTE}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing vaults', passmanStatusMap);
    }
  },
};

export const listCredentialsTool = {
  name: 'passman_list_credentials',
  description: `List credentials (metadata only) in a Passman vault. Returns each credential's label, id, guid and non-secret flags. ${E2E_NOTE}`,
  inputSchema: z.object({
    vault_guid: z.string().describe('Vault GUID (from passman_list_vaults)'),
  }),
  handler: async (args: { vault_guid: string }) => {
    try {
      const vault = await fetchPassmanAPI<PassmanVault>(
        `/vaults/${encodeURIComponent(args.vault_guid)}`
      );
      const creds = vault.credentials ?? [];

      if (creds.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No credentials in this vault.' }] };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Credentials in "${vault.name}" (${creds.length}):\n\n${creds
              .map(formatCredential)
              .join('\n')}\n\n${E2E_NOTE}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing credentials', passmanStatusMap);
    }
  },
};

export const getCredentialInfoTool = {
  name: 'passman_get_credential_info',
  description: `Get non-secret metadata for a single Passman credential (label, id, timestamps, flags). ${E2E_NOTE}`,
  inputSchema: z.object({
    vault_guid: z.string().describe('Vault GUID (from passman_list_vaults)'),
    credential_guid: z.string().describe('Credential GUID (from passman_list_credentials)'),
  }),
  handler: async (args: { vault_guid: string; credential_guid: string }) => {
    try {
      const vault = await fetchPassmanAPI<PassmanVault>(
        `/vaults/${encodeURIComponent(args.vault_guid)}`
      );
      const cred = (vault.credentials ?? []).find((c) => c.guid === args.credential_guid);

      if (!cred) {
        return {
          content: [{ type: 'text' as const, text: 'Credential not found in this vault.' }],
          isError: true as const,
        };
      }

      const lines: string[] = [
        `# ${cred.label}`,
        '',
        `ID: ${cred.credential_id}`,
        `GUID: ${cred.guid}`,
        `Vault: ${vault.name}`,
        `Created: ${new Date(cred.created * 1000).toISOString()}`,
        `Changed: ${new Date(cred.changed * 1000).toISOString()}`,
        `Has URL: ${hasValue(cred.url)}`,
        `Tagged: ${hasValue(cred.tags)}`,
        `Hidden: ${Boolean(cred.hidden)}`,
        `Compromised: ${Boolean(cred.compromised)}`,
      ];
      if (cred.expire_time) {
        lines.push(`Expires: ${new Date(cred.expire_time * 1000).toISOString()}`);
      }
      lines.push('', E2E_NOTE);

      return { content: [{ type: 'text' as const, text: lines.join('\n') }] };
    } catch (error) {
      return handleAppError(error, 'Error getting credential info', passmanStatusMap);
    }
  },
};

export const passmanTools = [listVaultsTool, listCredentialsTool, getCredentialInfoTool];
