import { getNextcloudConfig } from '../tools/types.js';

/**
 * OCS API response envelope
 */
export interface OcsResponse<T = unknown> {
  ocs: {
    meta: {
      status: string;
      statuscode: number;
      message: string;
      totalitems?: string;
      itemsperpage?: string;
    };
    data: T;
  };
}

/**
 * Options for OCS API requests
 */
interface OcsRequestOptions {
  method?: string;
  body?: Record<string, string>;
  queryParams?: Record<string, string>;
}

/**
 * Make an authenticated request to the Nextcloud OCS API.
 *
 * Handles Basic Auth, required OCS headers, URL-encoded bodies,
 * query parameters, and dual error checking (HTTP + OCS status).
 */
export async function fetchOCS<T = unknown>(
  path: string,
  options: OcsRequestOptions = {}
): Promise<OcsResponse<T>> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}${path}`;
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
  if (options.body) {
    body = new URLSearchParams(options.body).toString();
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }

  const response = await fetch(url, {
    method: options.method || 'GET',
    headers,
    body,
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`OCS API error: ${response.status} ${response.statusText} - ${text}`);
  }

  const json = (await response.json()) as OcsResponse<T>;

  if (json.ocs.meta.statuscode !== 200 && json.ocs.meta.statuscode !== 100) {
    throw new Error(`OCS API error: ${json.ocs.meta.statuscode} - ${json.ocs.meta.message}`);
  }

  return json;
}

/**
 * Fetch the plain /status.php endpoint (not OCS, returns JSON directly).
 */
export async function fetchStatus(): Promise<Record<string, unknown>> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  const response = await fetch(`${config.url}/status.php`, {
    headers: {
      Authorization: `Basic ${auth}`,
    },
  });

  if (!response.ok) {
    throw new Error(`Status check failed: ${response.status} ${response.statusText}`);
  }

  return (await response.json()) as Record<string, unknown>;
}
