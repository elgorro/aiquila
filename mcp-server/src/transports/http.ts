import { randomUUID } from 'node:crypto';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { createServer } from '../server.js';

const DEFAULT_PORT = 3339;
const MCP_PATH = '/mcp';

export async function startHttp(): Promise<void> {
  const port = parseInt(process.env.MCP_PORT || String(DEFAULT_PORT), 10);
  const host = process.env.MCP_HOST || '0.0.0.0';

  const app = createMcpExpressApp({ host });

  const mcpServer = createServer();
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: () => randomUUID(),
  });

  app.all(MCP_PATH, async (req: any, res: any) => {
    await transport.handleRequest(req, res, req.body);
  });

  await mcpServer.connect(transport);

  app.listen(port, host, () => {
    console.error(
      `AIquila MCP server running (http transport) at http://${host}:${port}${MCP_PATH}`
    );
  });
}
