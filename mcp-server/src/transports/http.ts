import { randomUUID } from 'node:crypto';
import express from 'express';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { mcpAuthRouter } from '@modelcontextprotocol/sdk/server/auth/router.js';
import { requireBearerAuth } from '@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js';
import { createServer } from '../server.js';
import { NextcloudOAuthProvider } from '../auth/provider.js';
import { loginHandler } from '../auth/login.js';

const DEFAULT_PORT = 3339;
const MCP_PATH = '/mcp';

export async function startHttp(): Promise<void> {
  const port = parseInt(process.env.MCP_PORT || String(DEFAULT_PORT), 10);
  const host = process.env.MCP_HOST || '0.0.0.0';
  const authEnabled = process.env.MCP_AUTH_ENABLED === 'true';

  const app = createMcpExpressApp({ host });

  const mcpServer = createServer();
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: () => randomUUID(),
  });

  if (authEnabled) {
    const issuerUrl = process.env.MCP_AUTH_ISSUER;
    if (!issuerUrl) {
      throw new Error('MCP_AUTH_ISSUER must be set when MCP_AUTH_ENABLED=true');
    }
    if (!process.env.MCP_AUTH_SECRET) {
      throw new Error('MCP_AUTH_SECRET must be set when MCP_AUTH_ENABLED=true');
    }

    const provider = new NextcloudOAuthProvider();

    // OAuth discovery + token endpoints (/.well-known/*, /oauth/*)
    app.use(mcpAuthRouter({ provider, issuerUrl: new URL(issuerUrl) }));

    // Parse URL-encoded form submissions from the login page
    app.use(express.urlencoded({ extended: false }));

    // Nextcloud credential validation → auth code issuance
    app.post('/auth/login', loginHandler(provider));

    // Protect /mcp with Bearer token auth
    app.all(MCP_PATH, requireBearerAuth({ verifier: provider }), async (req: any, res: any) => {
      await transport.handleRequest(req, res, req.body);
    });
  } else {
    app.all(MCP_PATH, async (req: any, res: any) => {
      await transport.handleRequest(req, res, req.body);
    });
  }

  await mcpServer.connect(transport);

  app.listen(port, host, () => {
    console.error(
      `AIquila MCP server running (http transport) at http://${host}:${port}${MCP_PATH}`
    );
    if (authEnabled) {
      console.error(`OAuth 2.0 authentication enabled (issuer: ${process.env.MCP_AUTH_ISSUER})`);
    }
  });
}
