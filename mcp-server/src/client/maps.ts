// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';

/**
 * Nextcloud Maps API client.
 *
 * A single helper, fetchMapsAPI, targeting the internal controller endpoints
 * (/apps/maps/...). Those are a strict superset of the CORS-enabled external
 * REST API (/apps/maps/api/1.0/...): they accept the same parameters plus the
 * optional myMapId used to scope an operation to a custom "My Map".
 */

interface FetchOptions {
  method?: string;
  body?: unknown;
  queryParams?: Record<string, string | string[]>;
}

function buildUrl(
  base: string,
  endpoint: string,
  queryParams?: Record<string, string | string[]>
): string {
  let url = `${base}${endpoint}`;
  if (queryParams) {
    const parts: string[] = [];
    for (const [key, value] of Object.entries(queryParams)) {
      if (Array.isArray(value)) {
        for (const v of value) {
          parts.push(`${encodeURIComponent(key)}[]=${encodeURIComponent(v)}`);
        }
      } else if (value !== undefined && value !== '') {
        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
      }
    }
    if (parts.length > 0) {
      url += `?${parts.join('&')}`;
    }
  }
  return url;
}

function buildHeaders(auth: string, hasBody: boolean): Record<string, string> {
  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };
  if (hasBody) {
    headers['Content-Type'] = 'application/json';
  }
  return headers;
}

/**
 * Fetch from the internal Maps controller endpoints.
 * Base: /apps/maps
 */
export async function fetchMapsAPI<T = unknown>(
  endpoint: string,
  options: FetchOptions = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');
  const url = buildUrl(`${config.url}/apps/maps`, endpoint, options.queryParams);
  const body = options.body ? JSON.stringify(options.body) : undefined;

  const t0 = Date.now();
  const response = await fetch(url, {
    method: options.method || 'GET',
    headers: buildHeaders(auth, !!options.body),
    body,
  });
  logger.trace(
    { method: options.method || 'GET', url, status: response.status, ms: Date.now() - t0 },
    '[nc] HTTP'
  );

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    throw new Error(`Maps API ${response.status}: ${text || response.statusText}`);
  }

  return (await response.json()) as T;
}
