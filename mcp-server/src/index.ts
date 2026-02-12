import { startStdio } from './transports/stdio.js';
import { startHttp } from './transports/http.js';

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
      console.error(`Unknown MCP_TRANSPORT: "${transport}". Use "stdio" or "http".`);
      process.exit(1);
  }
}

main().catch((err) => {
  console.error('Fatal error:', err);
  process.exit(1);
});
