// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';

/**
 * What Nextcloud says about the account behind a grant:
 * - `active`: it exists and is enabled
 * - `disabled` / `missing`: the grant must end
 * - `unknown`: Nextcloud could not tell us (unreachable, or the service
 *   account may not read other users — the provisioning API answers OCS 998)
 */
export type NextcloudUserState = 'active' | 'disabled' | 'missing' | 'unknown';

export type NextcloudUserCheck = (userId: string) => Promise<NextcloudUserState>;

let warnedUnknown = false;

/**
 * Looks the user up with the provisioning API, as the configured service
 * account. Reading another user needs admin or sub-admin rights; without them
 * the answer is `unknown` and a warning is logged once.
 */
export const checkNextcloudUser: NextcloudUserCheck = async (userId) => {
  try {
    const config = getNextcloudConfig();
    const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');
    const resp = await fetch(`${config.url}/ocs/v2.php/cloud/users/${encodeURIComponent(userId)}`, {
      headers: {
        Authorization: `Basic ${auth}`,
        'OCS-APIRequest': 'true',
        Accept: 'application/json',
      },
      signal: AbortSignal.timeout(5000),
    });
    const body = (await resp.json().catch(() => null)) as {
      ocs?: { meta?: { statuscode?: number }; data?: { enabled?: unknown } };
    } | null;
    const code = body?.ocs?.meta?.statuscode;
    if (resp.ok && code === 200) {
      return body?.ocs?.data?.enabled === false ? 'disabled' : 'active';
    }
    // OCS 404 is "User does not exist"; 998 (also HTTP 404) is "not allowed to see".
    if (code === 404) return 'missing';
    if (!warnedUnknown) {
      warnedUnknown = true;
      logger.warn(
        { status: resp.status, ocsStatus: code },
        '[auth] Cannot read Nextcloud users with the service account — a disabled or ' +
          'deleted user keeps their grant until it expires. Give NEXTCLOUD_USER admin rights ' +
          'to enforce this.'
      );
    }
    return 'unknown';
  } catch (err) {
    logger.warn({ err }, '[auth] Nextcloud user check failed');
    return 'unknown';
  }
};
