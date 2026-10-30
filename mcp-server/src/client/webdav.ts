// SPDX-License-Identifier: MIT

import { createClient, WebDAVClient } from 'webdav';
import { currentIdentity } from '../nc-context.js';
import { getNextcloudConfig } from '../tools/types.js';

// The environment account's client, reused across requests (stdio, the
// internal token). A signed-in user's client is made per call instead, so their
// app password is not kept in memory beyond the request that needs it.
let envClient: WebDAVClient | null = null;

/**
 * Get the WebDAV client for the account of the request being served.
 */
export function getWebDAVClient(): WebDAVClient {
  const config = getNextcloudConfig();
  const create = () =>
    createClient(`${config.url}/remote.php/dav/files/${config.user}`, {
      username: config.loginName,
      password: config.password,
    });
  if (currentIdentity()?.kind === 'user') return create();
  envClient ??= create();
  return envClient;
}

/**
 * Reset the WebDAV client (useful for testing or reconnection scenarios)
 */
export function resetWebDAVClient(): void {
  envClient = null;
}
