// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

/**
 * Passman vault metadata.
 *
 * Note: the actual credential secrets are end-to-end encrypted client-side
 * (SJCL AES-256) with a vault password that never reaches the server, so the
 * server — and therefore this MCP integration — only ever sees ciphertext for
 * sensitive fields.
 */
export interface PassmanVault {
  vault_id: number;
  guid: string;
  name: string;
  created: number;
  last_access: number;
  public_sharing_key?: string;
  challenge_password?: string;
  /** Present on GET /vaults/{guid} */
  credentials?: PassmanCredential[];
}

/**
 * Passman credential. Sensitive fields (password, username, url, email,
 * description, custom_fields, otp, tags, files, icon) are client-side encrypted
 * ciphertext and are intentionally NOT surfaced by this integration. Only
 * `label` is plaintext.
 */
export interface PassmanCredential {
  credential_id: number;
  guid: string;
  vault_id: number;
  label: string;
  created: number;
  changed: number;
  tags?: unknown;
  url?: unknown;
  renew_interval?: number;
  expire_time?: number;
  delete_time?: number;
  hidden?: number | boolean;
  shared_key?: string | null;
  compromised?: boolean;
}

/**
 * Make an authenticated request to the Nextcloud Passman REST API v2.
 *
 * Base path: /index.php/apps/passman/api/v2
 */
export async function fetchPassmanAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/passman/api/v2${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams(options.queryParams);
    url += `?${params.toString()}`;
  }

  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };

  let body: string | undefined;
  if (options.body !== undefined) {
    body = JSON.stringify(options.body);
    headers['Content-Type'] = 'application/json';
  }

  const method = options.method ?? 'GET';
  const t0 = Date.now();
  const response = await fetch(url, { method, headers, body });
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[passman] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (
    response.status === 200 &&
    response.headers.get('content-type')?.includes('application/json')
  ) {
    return (await response.json()) as T;
  }

  return undefined as T;
}
