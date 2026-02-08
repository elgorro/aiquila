import { getNextcloudConfig } from "../tools/types.js";

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
  const auth = Buffer.from(`${config.user}:${config.password}`).toString(
    "base64"
  );

  let url = `${config.url}/index.php/apps/aiquila/api${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams(options.queryParams);
    url += `?${params.toString()}`;
  }

  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    "OCS-APIRequest": "true",
    Accept: "application/json",
  };

  let body: string | undefined;
  if (options.body) {
    body = JSON.stringify(options.body);
    headers["Content-Type"] = "application/json";
  }

  const response = await fetch(url, {
    method: options.method || "GET",
    headers,
    body,
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(
      `AIquila API error: ${response.status} ${response.statusText} - ${text}`
    );
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

  return fetchAiquilaAPI<OccExecutionResult>("/occ", {
    method: "POST",
    body,
  });
}
