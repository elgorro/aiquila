/**
 * Fetch data from CalDAV endpoint using basic authentication
 */
export async function fetchCalDAV(
  url: string,
  options: {
    method?: string;
    body?: string;
    headers?: Record<string, string>;
  } = {}
): Promise<Response> {
  const NEXTCLOUD_USER = process.env.NEXTCLOUD_USER;
  const NEXTCLOUD_PASSWORD = process.env.NEXTCLOUD_PASSWORD;

  if (!NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
    throw new Error(
      "NEXTCLOUD_USER and NEXTCLOUD_PASSWORD environment variables must be set"
    );
  }

  const auth = Buffer.from(`${NEXTCLOUD_USER}:${NEXTCLOUD_PASSWORD}`).toString(
    "base64"
  );

  const method = options.method || "GET";

  // Normalize URL: collapse double slashes (but not in protocol://)
  const normalizedUrl = url.replace(/([^:])\/\/+/g, "$1/");

  const response = await fetch(normalizedUrl, {
    method,
    headers: {
      Authorization: `Basic ${auth}`,
      "Content-Type": "application/xml; charset=utf-8",
      ...options.headers,
    },
    body: options.body,
    redirect: "manual",
  });

  // Handle redirects manually to preserve method and body
  if (response.status >= 300 && response.status < 400) {
    const location = response.headers.get("Location");
    if (!location) {
      throw new Error(
        `CalDAV server returned redirect ${response.status} without Location header`
      );
    }

    const redirectUrl = new URL(location, normalizedUrl).toString();

    // 307/308 preserve the method and body — safe to follow
    if (response.status === 307 || response.status === 308) {
      return fetchCalDAV(redirectUrl, options);
    }

    // 301/302/303: safe for GET/HEAD, but would change method for PUT/DELETE/etc.
    if (method === "GET" || method === "HEAD") {
      return fetchCalDAV(redirectUrl, {
        ...options,
        method: "GET",
        body: undefined,
      });
    }

    throw new Error(
      `CalDAV server returned redirect ${response.status} for ${method} request. ` +
        `This would change the HTTP method and drop the request body. ` +
        `Redirect target: ${redirectUrl}. ` +
        `Check that NEXTCLOUD_URL is correct and does not need trailing slash adjustment.`
    );
  }

  return response;
}
