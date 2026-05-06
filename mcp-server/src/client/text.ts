// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export interface TextWorkspaceFile {
  id: number;
  mimetype: string;
  name: string;
  path: string;
}

export interface TextWorkspaceFolder {
  permissions: number;
}

export interface TextWorkspaceResponse {
  file?: TextWorkspaceFile;
  folder?: TextWorkspaceFolder;
  message?: string;
}

export interface TextDirectEditResponse {
  url: string;
}

interface OcsEnvelope<T> {
  ocs: {
    meta: {
      status: string;
      statuscode: number;
      message: string;
    };
    data: T;
  };
}

/**
 * Make an authenticated request to the Nextcloud Text app OCS API.
 *
 * Base path: /ocs/v2.php/apps/text
 * Unwraps the OCS envelope and returns `ocs.data`.
 * Throws {@link ApiError} on HTTP errors or non-success OCS status codes.
 */
export async function fetchTextAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/ocs/v2.php/apps/text${endpoint}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[text] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    return undefined as T;
  }

  const json = (await response.json()) as OcsEnvelope<T>;
  const code = json?.ocs?.meta?.statuscode;
  if (code !== undefined && code !== 200 && code !== 100) {
    throw new ApiError(code, json.ocs.meta.status ?? 'error', json.ocs.meta.message ?? '');
  }
  return json.ocs.data;
}
