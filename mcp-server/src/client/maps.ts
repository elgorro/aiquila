import { getNextcloudConfig } from "../tools/types.js";

/**
 * Nextcloud Maps API client.
 *
 * Two helpers:
 *  - fetchMapsExternalAPI  — CORS-enabled external REST API  (/apps/maps/api/1.0/...)
 *  - fetchMapsAPI          — Internal controller endpoints    (/apps/maps/...)
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
      } else if (value !== undefined && value !== "") {
        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
      }
    }
    if (parts.length > 0) {
      url += `?${parts.join("&")}`;
    }
  }
  return url;
}

function buildHeaders(auth: string, hasBody: boolean): Record<string, string> {
  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    "OCS-APIRequest": "true",
    Accept: "application/json",
  };
  if (hasBody) {
    headers["Content-Type"] = "application/json";
  }
  return headers;
}

/**
 * Fetch from the CORS-enabled external Maps API.
 * Base: /apps/maps/api/1.0
 * Used for: favorites, devices
 */
export async function fetchMapsExternalAPI<T = unknown>(
  endpoint: string,
  options: FetchOptions = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString("base64");
  const url = buildUrl(`${config.url}/apps/maps/api/1.0`, endpoint, options.queryParams);
  const body = options.body ? JSON.stringify(options.body) : undefined;

  const response = await fetch(url, {
    method: options.method || "GET",
    headers: buildHeaders(auth, !!options.body),
    body,
  });

  if (!response.ok) {
    const text = await response.text().catch(() => "");
    throw new Error(`Maps API ${response.status}: ${text || response.statusText}`);
  }

  return (await response.json()) as T;
}

/**
 * Fetch from the internal Maps controller endpoints.
 * Base: /apps/maps
 * Used for: tracks, photos, my maps, routing, import/export
 */
export async function fetchMapsAPI<T = unknown>(
  endpoint: string,
  options: FetchOptions = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString("base64");
  const url = buildUrl(`${config.url}/apps/maps`, endpoint, options.queryParams);
  const body = options.body ? JSON.stringify(options.body) : undefined;

  const response = await fetch(url, {
    method: options.method || "GET",
    headers: buildHeaders(auth, !!options.body),
    body,
  });

  if (!response.ok) {
    const text = await response.text().catch(() => "");
    throw new Error(`Maps API ${response.status}: ${text || response.statusText}`);
  }

  return (await response.json()) as T;
}
