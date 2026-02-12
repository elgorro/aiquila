import { createClient, WebDAVClient } from 'webdav';

const NEXTCLOUD_URL = process.env.NEXTCLOUD_URL;
const NEXTCLOUD_USER = process.env.NEXTCLOUD_USER;
const NEXTCLOUD_PASSWORD = process.env.NEXTCLOUD_PASSWORD;

let webdavClient: WebDAVClient | null = null;

/**
 * Get or create a WebDAV client instance.
 * This singleton pattern ensures we reuse the same client connection.
 */
export function getWebDAVClient(): WebDAVClient {
  if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
    throw new Error(
      'NEXTCLOUD_URL, NEXTCLOUD_USER, and NEXTCLOUD_PASSWORD environment variables must be set'
    );
  }

  if (!webdavClient) {
    const webdavUrl = `${NEXTCLOUD_URL}/remote.php/dav/files/${NEXTCLOUD_USER}`;
    webdavClient = createClient(webdavUrl, {
      username: NEXTCLOUD_USER,
      password: NEXTCLOUD_PASSWORD,
    });
  }

  return webdavClient;
}

/**
 * Reset the WebDAV client (useful for testing or reconnection scenarios)
 */
export function resetWebDAVClient(): void {
  webdavClient = null;
}
