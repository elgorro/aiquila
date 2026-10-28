// SPDX-License-Identifier: MIT

import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { createServer } from '../server.js';
import { getFilteredToolSets } from '../tool-registry.js';
import { logger } from '../logger.js';
import { fetchStatus } from '../client/ocs.js';
import { SUPPORT_NOTICE } from '../support.js';

export async function startStdio(): Promise<void> {
  // Resolve the tool set now so app detection runs at startup rather than on
  // the client's first message; the result is cached for the factory below.
  await getFilteredToolSets();

  // serveStdio picks the era from the client's opening message: a
  // `server/discover` probe or any request carrying the per-request `_meta`
  // envelope is served as 2026-07-28, an `initialize` handshake pins the
  // connection to the negotiated 2025-era revision. The factory may run twice —
  // once for a discover probe that the client then abandons for `initialize`.
  serveStdio(() => createServer(), {
    onerror: (err) => logger.error({ err: err.message }, '[mcp] stdio error'),
  });
  logger.info('AIquila MCP server running (stdio transport)');
  logger.info(SUPPORT_NOTICE);
  void (async () => {
    try {
      const t0 = Date.now();
      await fetchStatus();
      logger.info(
        { nc: process.env.NEXTCLOUD_URL, ms: Date.now() - t0 },
        '[startup] Nextcloud reachable'
      );
    } catch (err) {
      logger.warn(
        {
          nc: process.env.NEXTCLOUD_URL,
          err: err instanceof Error ? err.message : String(err),
        },
        '[startup] Nextcloud unreachable'
      );
    }
  })();
}
