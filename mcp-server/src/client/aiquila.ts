// SPDX-License-Identifier: MIT

import { basicAuth, getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';

/**
 * Custom error for HTTP API failures, preserving the status code.
 */
export class ApiError extends Error {
  public readonly statusCode: number;
  public readonly statusText: string;
  public readonly responseBody: string;
  public readonly detail: string | undefined;

  /**
   * @param detail Optional reason safe to show the caller, appended to the message.
   *               Never pass a raw response body here — see {@link aiquilaErrorDetail}.
   */
  constructor(statusCode: number, statusText: string, responseBody: string, detail?: string) {
    super(`AIquila API error: ${statusCode} ${statusText}${detail ? `: ${detail}` : ''}`);
    this.name = 'ApiError';
    this.statusCode = statusCode;
    this.statusText = statusText;
    this.responseBody = responseBody;
    this.detail = detail;
  }
}

/** Upper bound on the reason appended to an error message. */
const MAX_ERROR_DETAIL_LENGTH = 300;

/**
 * Extract the user-facing reason from an AIquila error response body.
 *
 * The app's controllers answer every error with `{"error": "...", "errorId": "..."}`,
 * where `error` is a fixed message written by the app (never an exception message or
 * a path) and `errorId` correlates with the Nextcloud log. Only that shape is read;
 * anything else — an HTML error page, a proxy's body, an empty body — yields undefined,
 * so raw bodies never reach the message.
 */
export function aiquilaErrorDetail(body: string): string | undefined {
  let parsed: unknown;
  try {
    parsed = JSON.parse(body);
  } catch {
    return undefined;
  }
  if (typeof parsed !== 'object' || parsed === null) return undefined;
  const { error, errorId } = parsed as { error?: unknown; errorId?: unknown };
  if (typeof error !== 'string') return undefined;

  let detail = error.replace(/\s+/g, ' ').trim();
  if (detail === '') return undefined;
  if (detail.length > MAX_ERROR_DETAIL_LENGTH) {
    detail = `${detail.slice(0, MAX_ERROR_DETAIL_LENGTH - 1)}…`;
  }
  if (typeof errorId === 'string' && /^[0-9a-f]{1,32}$/i.test(errorId)) {
    detail += ` (error ID ${errorId})`;
  }
  return detail;
}

/**
 * Format an error from an OCC command execution into a clear, actionable message.
 */
export function formatOccError(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.statusCode) {
      case 401:
        return "Authentication failed. Nextcloud no longer accepts this account's credentials; sign in again.";
      case 403:
        // The app's own refusal (OCC allowlist) carries a reason; the admin check does not.
        if (error.detail) return `Refused by Nextcloud: ${error.detail}`;
        return 'Permission denied. OCC commands require admin rights, and this Nextcloud account is not an admin.';
      case 404:
        return 'OCC endpoint not found. Ensure the AIquila app is installed and enabled.';
      default:
        return `Request failed (HTTP ${error.statusCode} ${error.statusText})${error.detail ? `: ${error.detail}` : '.'}`;
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
  const auth = basicAuth(config);

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

  const t0 = Date.now();
  const response = await fetch(url, {
    method: options.method || 'GET',
    headers,
    body,
  });
  logger.trace(
    { method: options.method || 'GET', url, status: response.status, ms: Date.now() - t0 },
    '[nc] HTTP'
  );

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text, aiquilaErrorDetail(text));
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
