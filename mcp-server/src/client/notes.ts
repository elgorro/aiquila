import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export interface Note {
  id: number;
  etag: string;
  readonly: boolean;
  content: string;
  title: string;
  category: string;
  favorite: boolean;
  modified: number;
}

/**
 * Make an authenticated request to the Nextcloud Notes REST API v1.
 *
 * Base path: /index.php/apps/notes/api/v1
 */
export async function fetchNotesAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
    ifMatch?: string;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/notes/api/v1${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams(options.queryParams);
    url += `?${params.toString()}`;
  }

  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };

  if (options.ifMatch) {
    headers['If-Match'] = options.ifMatch;
  }

  let body: string | undefined;
  if (options.body !== undefined) {
    body = JSON.stringify(options.body);
    headers['Content-Type'] = 'application/json';
  }

  const method = options.method ?? 'GET';
  const t0 = Date.now();
  const response = await fetch(url, { method, headers, body });
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[notes] HTTP');

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
