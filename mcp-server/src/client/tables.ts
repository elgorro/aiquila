// SPDX-License-Identifier: MIT

import { basicAuth, getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

/** The `message` Tables puts in an error body. */
function tablesErrorMessage(body: string): string | undefined {
  try {
    const { message } = JSON.parse(body) as { message?: unknown };
    return typeof message === 'string' && message ? message : undefined;
  } catch {
    return undefined;
  }
}

/**
 * Nextcloud Tables REST API v1.
 *
 * OCS API v2 covers tables and structure, but has no row listing and no
 * relation columns, so those use this one. Without the OCS-APIRequest header
 * and with an account password, this API answers as if no user were signed in.
 */
export async function fetchTablesAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = basicAuth(config);

  let url = `${config.url}/index.php/apps/tables/api/1${endpoint}`;
  if (options.queryParams) {
    url += `?${new URLSearchParams(options.queryParams).toString()}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[tables] HTTP');

  if (!response.ok) {
    const responseBody = await response.text();
    throw new ApiError(
      response.status,
      response.statusText,
      responseBody,
      tablesErrorMessage(responseBody)
    );
  }
  return (await response.json()) as T;
}
