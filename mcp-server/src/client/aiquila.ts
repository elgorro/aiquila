import { getNextcloudConfig } from '../tools/types.js';

/**
 * Custom error for HTTP API failures, preserving the status code.
 */
export class ApiError extends Error {
  public readonly statusCode: number;
  public readonly statusText: string;
  public readonly responseBody: string;

  constructor(statusCode: number, statusText: string, responseBody: string) {
    super(`AIquila API error: ${statusCode} ${statusText}`);
    this.name = 'ApiError';
    this.statusCode = statusCode;
    this.statusText = statusText;
    this.responseBody = responseBody;
  }
}

/**
 * Format an error from an OCC command execution into a clear, actionable message.
 */
export function formatOccError(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.statusCode) {
      case 401:
        return 'Authentication failed. Check that NEXTCLOUD_USER and NEXTCLOUD_PASSWORD are correct.';
      case 403:
        return 'Permission denied. OCC commands require admin rights. Ensure the Nextcloud user is an admin.';
      case 404:
        return 'OCC endpoint not found. Ensure the AIquila app is installed and enabled.';
      default:
        return `Request failed (HTTP ${error.statusCode} ${error.statusText}).`;
    }
  }
  return error instanceof Error ? error.message : String(error);
}

/**
 * Response from the OCC execution endpoint
 */
export interface OccExecutionResult {
  success: boolean;
  stdout?: string;
  stderr?: string;
  exitCode?: number;
  error?: string;
}

/**
 * Make an authenticated request to the AIquila app REST API.
 *
 * For endpoints under /index.php/apps/aiquila/api/...
 * Uses Basic Auth + OCS-APIRequest header for CSRF bypass.
 */
export async function fetchAiquilaAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/aiquila/api${endpoint}`;
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
    body = JSON.stringify(options.body);
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    method: options.method || 'GET',
    headers,
    body,
  });

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  return (await response.json()) as T;
}

/**
 * Execute an OCC command via the AIquila app endpoint.
 */
export async function executeOCC(
  command: string,
  args: string[] = [],
  timeout?: number
): Promise<OccExecutionResult> {
  const body: Record<string, unknown> = { command, args };
  if (timeout !== undefined) {
    body.timeout = timeout;
  }

  return fetchAiquilaAPI<OccExecutionResult>('/occ', {
    method: 'POST',
    body,
  });
}
