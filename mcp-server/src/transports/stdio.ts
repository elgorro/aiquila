import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createServer } from '../server.js';
import { logger } from '../logger.js';
import { fetchStatus } from '../client/ocs.js';

export async function startStdio(): Promise<void> {
  const server = createServer();
  const transport = new StdioServerTransport();
  await server.connect(transport);
  logger.info('AIquila MCP server running (stdio transport)');
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
