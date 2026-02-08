import { getNextcloudConfig } from "../tools/types.js";

/**
 * Make an authenticated request to the Nextcloud Mail app REST API.
 *
 * For non-OCS endpoints under /index.php/apps/mail/api/...
 * Returns raw Response for caller to parse.
 */
export async function fetchMailAPI(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<Response> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString("base64");

  let url = `${config.url}/index.php/apps/mail/api${endpoint}`;
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

  return fetch(url, {
    method: options.method || "GET",
    headers,
    body,
  });
}
