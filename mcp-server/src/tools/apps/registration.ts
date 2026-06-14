// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Registration Tools
 *
 * The Registration app (https://github.com/nextcloud/registration) lets visitors
 * self-register accounts via an email-verification flow. It exposes no API of its
 * own — all admin behaviour is driven by app config keys stored under the
 * `registration` app id. These tools read and write those keys through core's
 * provisioning_api appconfig OCS endpoints.
 *
 * Pending registrations are not exposed by any API. When `admin_approval_required`
 * is enabled, registrants become disabled Nextcloud users — approve or reject them
 * with the standard user tools (enable_user / delete_user).
 */

const APPCONFIG_BASE = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/registration';

/** Known Registration app config keys, with short descriptions. */
const REGISTRATION_KEYS: Record<string, string> = {
  allowed_domains:
    'Email domains allowed (or, with domains_is_blocklist, blocked) for registration (JSON array string)',
  domains_is_blocklist: 'Treat allowed_domains as a blocklist instead of an allowlist (yes/no)',
  show_domains: 'Show the email domain list to users (yes/no)',
  admin_approval_required: 'Newly registered users must be validated by an admin (yes/no)',
  registered_user_group: 'Group id newly registered users are added to',
  email_is_optional: 'Email address is optional during registration (yes/no)',
  email_is_login: 'Force the email address as the user id / login (yes/no)',
  disable_email_verification: 'Skip the email verification step (yes/no)',
  email_verification_hint: 'Text embedded in the verification email',
  additional_hint: 'Text displayed on the account creation form',
  username_policy_regex: 'Optional regex the chosen username must match',
  show_fullname: 'Show the full name field on the registration form (yes/no)',
  enforce_fullname: 'Make the full name field mandatory (yes/no)',
  show_phone: 'Show the phone field on the registration form (yes/no)',
  enforce_phone: 'Make the phone field mandatory (yes/no)',
};

const KNOWN_KEYS = Object.keys(REGISTRATION_KEYS);

// ---------------------------------------------------------------------------
// get_registration_settings
// ---------------------------------------------------------------------------

export const getRegistrationSettingsTool = {
  name: 'get_registration_settings',
  description:
    'Read the Nextcloud Registration app settings (self-service signup configuration), such as ' +
    'allowed email domains, whether admin approval is required, and the default group for new ' +
    'users. Requires the configured Nextcloud user to be an admin.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const entries = await Promise.all(
        KNOWN_KEYS.map(async (key) => {
          const result = await fetchOCS<string>(`${APPCONFIG_BASE}/${key}`);
          return [key, result.ocs.data] as const;
        })
      );

      const text = entries
        .map(([key, value]) => `- ${key}: ${value === '' ? '(default)' : value}`)
        .join('\n');

      return {
        content: [{ type: 'text' as const, text: `Registration settings:\n${text}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error reading registration settings: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// update_registration_settings
// ---------------------------------------------------------------------------

export const updateRegistrationSettingsTool = {
  name: 'update_registration_settings',
  description:
    'Update one or more Nextcloud Registration app settings. Boolean settings use the strings ' +
    "'yes'/'no'; allowed_domains takes a JSON array string (e.g. '[\"example.com\"]'). Requires " +
    'the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    settings: z
      .array(
        z.object({
          key: z
            .enum(KNOWN_KEYS as [string, ...string[]])
            .describe('The Registration config key to set'),
          value: z.string().describe('The value to store (boolean keys: "yes"/"no")'),
        })
      )
      .min(1)
      .describe('One or more key/value pairs to update'),
  }),
  handler: async (args: { settings: Array<{ key: string; value: string }> }) => {
    try {
      const updated: string[] = [];
      for (const { key, value } of args.settings) {
        await fetchOCS(`${APPCONFIG_BASE}/${key}`, {
          method: 'POST',
          body: { value },
        });
        updated.push(`${key} = ${value}`);
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Updated registration settings:\n${updated.map((u) => `- ${u}`).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating registration settings: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// reset_registration_setting
// ---------------------------------------------------------------------------

export const resetRegistrationSettingTool = {
  name: 'reset_registration_setting',
  description:
    'Reset a Nextcloud Registration app setting to its default by deleting the stored value. ' +
    'Requires the configured Nextcloud user to be an admin.',
  inputSchema: z.object({
    key: z
      .enum(KNOWN_KEYS as [string, ...string[]])
      .describe('The Registration config key to reset to its default'),
  }),
  handler: async (args: { key: string }) => {
    try {
      await fetchOCS(`${APPCONFIG_BASE}/${args.key}`, { method: 'DELETE' });

      return {
        content: [
          { type: 'text' as const, text: `Reset registration setting '${args.key}' to default.` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error resetting registration setting: ${error instanceof Error ? error.message : String(error)}`,
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

export const registrationTools = [
  getRegistrationSettingsTool,
  updateRegistrationSettingsTool,
  resetRegistrationSettingTool,
];
