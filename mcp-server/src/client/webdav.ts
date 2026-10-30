// SPDX-License-Identifier: MIT

import { createClient, WebDAVClient } from 'webdav';
import { getNextcloudConfig } from '../tools/types.js';

// One client per account and credential. Under OAuth every signed-in user has
// their own; the bound keeps a busy server from holding one per grant forever.
const MAX_CLIENTS = 64;
const clients = new Map<string, WebDAVClient>();

/**
 * The WebDAV client for the account of the request being served. Clients are
 * reused per account and credential, so a revoked or replaced app password
 * never reuses a stale one.
 */
export function getWebDAVClient(): WebDAVClient {
  const config = getNextcloudConfig();
  const key = JSON.stringify([config.url, config.user, config.loginName, config.password]);
  let client = clients.get(key);
  if (client) {
    // Refresh its position, so the least recently used client is dropped first.
    clients.delete(key);
  } else {
    client = createClient(`${config.url}/remote.php/dav/files/${config.user}`, {
      username: config.loginName,
      password: config.password,
    });
    if (clients.size >= MAX_CLIENTS) clients.delete(clients.keys().next().value!);
  }
  clients.set(key, client);
  return client;
}

/**
 * Reset the WebDAV clients (useful for testing or reconnection scenarios)
 */
export function resetWebDAVClient(): void {
  clients.clear();
}
