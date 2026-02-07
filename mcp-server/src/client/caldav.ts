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

  const response = await fetch(url, {
    method: options.method || "GET",
    headers: {
      Authorization: `Basic ${auth}`,
      "Content-Type": "application/xml; charset=utf-8",
      ...options.headers,
    },
    body: options.body,
  });

  return response;
}
