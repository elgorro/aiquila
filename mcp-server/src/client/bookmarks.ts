import { getNextcloudConfig } from "../tools/types.js";

/**
 * Make an authenticated request to the Nextcloud Bookmarks app REST API.
 *
 * Base path: /index.php/apps/bookmarks/public/rest/v2
 * Returns parsed JSON with status validation.
 */
export async function fetchBookmarksAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string | string[]>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString("base64");

  let url = `${config.url}/index.php/apps/bookmarks/public/rest/v2${endpoint}`;
  if (options.queryParams) {
    const parts: string[] = [];
    for (const [key, value] of Object.entries(options.queryParams)) {
      if (Array.isArray(value)) {
        for (const v of value) {
          parts.push(`${encodeURIComponent(key)}[]=${encodeURIComponent(v)}`);
        }
      } else if (value !== undefined && value !== "") {
        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
      }
    }
    if (parts.length > 0) {
      url += `?${parts.join("&")}`;
    }
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
    const text = await response.text().catch(() => "");
    throw new Error(`Bookmarks API ${response.status}: ${text || response.statusText}`);
  }

  const json = await response.json() as { status: string; data?: unknown; item?: unknown };
  if (json.status !== "success") {
    throw new Error(`Bookmarks API error: ${JSON.stringify(json)}`);
  }

  return json as T;
}
