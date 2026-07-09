// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Terms of Service Tools
 *
 * The Terms of Service app (https://github.com/nextcloud/terms_of_service) lets admins
 * publish per-country / per-language terms that users must accept. It exposes its own OCS
 * API under `/ocs/v2.php/apps/terms_of_service`, so most tools call those endpoints
 * directly.
 *
 * Two enforcement flags (`tos_for_users`, `tos_on_public_shares`) are plain app config
 * keys: they are read via the admin form endpoint and written through core's
 * provisioning_api appconfig endpoints.
 */

const OCS_BASE = '/ocs/v2.php/apps/terms_of_service';
const APPCONFIG_BASE = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/terms_of_service';

interface Terms {
  id: number;
  countryCode: string;
  languageCode: string;
  body: string;
}

interface AdminFormData {
  terms: Terms[];
  countries: Record<string, string>;
  languages: Record<string, string>;
  tos_on_public_shares: string;
  tos_for_users: string;
}

function preview(body: string): string {
  const oneLine = body.replace(/\s+/g, ' ').trim();
  return oneLine.length > 80 ? `${oneLine.slice(0, 80)}…` : oneLine;
}

// ---------------------------------------------------------------------------
// get_terms_of_service
// ---------------------------------------------------------------------------

export const getTermsOfServiceTool = {
  name: 'get_terms_of_service',
  title: 'Get Terms of Service',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Read the Nextcloud Terms of Service admin configuration: all published terms (by ' +
    'country/language), the enforcement settings (tos_for_users, tos_on_public_shares), and ' +
    'the valid country and language codes that can be used when setting terms. Requires the ' +
    'configured Nextcloud user to be an admin.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchOCS<AdminFormData>(`${OCS_BASE}/terms/admin`);
      const data = result.ocs.data;

      const termsText =
        data.terms.length === 0
          ? '(none)'
          : data.terms
              .map((t) => `- #${t.id} [${t.countryCode}/${t.languageCode}] ${preview(t.body)}`)
              .join('\n');

      const settings = [
        `- tos_for_users: ${data.tos_for_users}`,
        `- tos_on_public_shares: ${data.tos_on_public_shares}`,
      ].join('\n');

      const countries = Object.keys(data.countries).join(', ');
      const languages = Object.keys(data.languages).join(', ');

      const text =
        `Terms of Service:\n${termsText}\n\n` +
        `Settings:\n${settings}\n\n` +
        `Valid country codes (use "--" for global): ${countries}\n` +
        `Valid language codes: ${languages}`;

      return {
        content: [{ type: 'text' as const, text }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error reading terms of service: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// set_terms_of_service
// ---------------------------------------------------------------------------

export const setTermsOfServiceTool = {
  name: 'set_terms_of_service',
  title: 'Set Terms of Service',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Create or update the terms of service for a given country/language pair. If terms ' +
    'already exist for that pair they are replaced, otherwise new terms are created. Use ' +
    'get_terms_of_service to discover valid country/language codes; an invalid code returns ' +
    'an error (HTTP 417). Requires the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    countryCode: z
      .string()
      .default('--')
      .describe('2-letter region code, or "--" for global (default)'),
    languageCode: z.string().describe('2-letter language code (e.g. "en")'),
    body: z.string().describe('The terms text (markdown: headers, formatting, lists, links)'),
  }),
  handler: async (args: { countryCode?: string; languageCode: string; body: string }) => {
    const countryCode = args.countryCode ?? '--';
    try {
      const result = await fetchOCS<Terms>(`${OCS_BASE}/terms`, {
        method: 'POST',
        body: {
          countryCode,
          languageCode: args.languageCode,
          body: args.body,
        },
      });
      const t = result.ocs.data;

      return {
        content: [
          {
            type: 'text' as const,
            text: `Saved terms #${t.id} for [${t.countryCode}/${t.languageCode}].`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error saving terms of service: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// delete_terms_of_service
// ---------------------------------------------------------------------------

export const deleteTermsOfServiceTool = {
  name: 'delete_terms_of_service',
  title: 'Delete Terms of Service',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete a single terms of service entry by its id (see get_terms_of_service). Requires ' +
    'the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    id: z.number().describe('The terms id to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchOCS(`${OCS_BASE}/terms/${args.id}`, { method: 'DELETE' });

      return {
        content: [{ type: 'text' as const, text: `Deleted terms #${args.id}.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting terms of service: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// reset_terms_signatures
// ---------------------------------------------------------------------------

export const resetTermsSignaturesTool = {
  name: 'reset_terms_signatures',
  title: 'Reset Terms Signatures',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Reset ALL users' terms of service signatures org-wide, forcing every user to accept " +
    'the terms again on next login. This is destructive and cannot be undone. Requires the ' +
    'configured Nextcloud user to be an admin.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      await fetchOCS(`${OCS_BASE}/sign`, { method: 'DELETE' });

      return {
        content: [
          {
            type: 'text' as const,
            text: 'Reset all terms of service signatures. All users must accept the terms again.',
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error resetting terms signatures: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// update_terms_settings
// ---------------------------------------------------------------------------

export const updateTermsSettingsTool = {
  name: 'update_terms_settings',
  title: 'Update Terms Settings',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Update the Terms of Service enforcement settings: whether logged-in users must accept ' +
    'the terms (tos_for_users) and whether the terms apply to public shares ' +
    '(tos_on_public_shares). Requires the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    tosForUsers: z.boolean().optional().describe('Require logged-in users to accept the terms'),
    tosOnPublicShares: z.boolean().optional().describe('Apply the terms to public shares'),
  }),
  handler: async (args: { tosForUsers?: boolean; tosOnPublicShares?: boolean }) => {
    const updates: Array<[string, boolean]> = [];
    if (args.tosForUsers !== undefined) updates.push(['tos_for_users', args.tosForUsers]);
    if (args.tosOnPublicShares !== undefined) {
      updates.push(['tos_on_public_shares', args.tosOnPublicShares]);
    }

    if (updates.length === 0) {
      return {
        content: [
          {
            type: 'text' as const,
            text: 'No settings provided. Specify tosForUsers and/or tosOnPublicShares.',
          },
        ],
        isError: true,
      };
    }

    try {
      const applied: string[] = [];
      for (const [key, enabled] of updates) {
        const value = enabled ? 'yes' : 'no';
        await fetchOCS(`${APPCONFIG_BASE}/${key}`, {
          method: 'POST',
          body: { value },
        });
        applied.push(`${key} = ${value}`);
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Updated terms of service settings:\n${applied.map((u) => `- ${u}`).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating terms settings: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const termsOfServiceTools = [
  getTermsOfServiceTool,
  setTermsOfServiceTool,
  deleteTermsOfServiceTool,
  resetTermsSignaturesTool,
  updateTermsSettingsTool,
];
