import { startStdio } from './transports/stdio.js';
import { startHttp } from './transports/http.js';
import { logger } from './logger.js';

/**
 * AIquila MCP Server
 * Provides Model Context Protocol integration for Nextcloud
 *
 * Transport selection via MCP_TRANSPORT environment variable:
 *   - "stdio" (default): Standard input/output transport for Claude Desktop
 *   - "http": Streamable HTTP transport for Docker/network deployment
 */
async function main() {
  const transport = process.env.MCP_TRANSPORT || 'stdio';

  switch (transport) {
    case 'stdio':
      await startStdio();
      break;
    case 'http':
      await startHttp();
      break;
    default:
      logger.error({ transport }, 'Unknown MCP_TRANSPORT. Use "stdio" or "http".');
      process.exit(1);
  }
}

main().catch((err) => {
  logger.fatal({ err }, 'Fatal error');
  process.exit(1);
});
