// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export interface Feed {
  id: number;
  url: string;
  title: string;
  faviconLink: string | null;
  added: number;
  folderId: number;
  unreadCount: number;
  ordering: number;
  link: string | null;
  pinned: boolean;
  updateErrorCount: number;
  lastUpdateError: string | null;
}

export interface Folder {
  id: number;
  name: string;
  opened: boolean;
}

export interface Item {
  id: number;
  guid: string;
  guidHash: string;
  url: string;
  title: string;
  author: string | null;
  pubDate: number;
  body: string;
  enclosureMime: string | null;
  enclosureLink: string | null;
  feedId: number;
  unread: boolean;
  starred: boolean;
  lastModified: number;
  fingerprint: string;
}

/**
 * Make an authenticated request to the Nextcloud News REST API v1-3.
 *
 * Base path: /index.php/apps/news/api/v1-3
 *
 * Returns plain JSON (no OCS envelope), like the Notes API.
 */
export async function fetchNewsAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string | number | boolean | undefined>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/news/api/v1-3${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(options.queryParams)) {
      if (value !== undefined) params.append(key, String(value));
    }
    const qs = params.toString();
    if (qs) url += `?${qs}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[news] HTTP');

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
