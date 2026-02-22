import { randomUUID } from 'node:crypto';
import express from 'express';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { mcpAuthRouter } from '@modelcontextprotocol/sdk/server/auth/router.js';
import { requireBearerAuth } from '@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js';
import { createServer } from '../server.js';
import { NextcloudOAuthProvider } from '../auth/provider.js';
import { loginHandler } from '../auth/login.js';
import { logger } from '../logger.js';

const DEFAULT_PORT = 3339;
const MCP_PATH = '/mcp';

export async function startHttp(): Promise<void> {
  const port = parseInt(process.env.MCP_PORT || String(DEFAULT_PORT), 10);
  const host = process.env.MCP_HOST || '0.0.0.0';
  const authEnabled = process.env.MCP_AUTH_ENABLED === 'true';

  const app = createMcpExpressApp({ host });

  // When running behind a reverse proxy (e.g. Traefik, nginx, Caddy), the proxy
  // adds X-Forwarded-For headers. Without trust proxy being set, express-rate-limit
  // throws ERR_ERL_UNEXPECTED_X_FORWARDED_FOR. Set MCP_TRUST_PROXY to configure this:
  //   MCP_TRUST_PROXY=1      → trust one hop (recommended for a single reverse proxy)
  //   MCP_TRUST_PROXY=true   → trust all proxies
  //   MCP_TRUST_PROXY=false  → disabled (default)
  const trustProxy = process.env.MCP_TRUST_PROXY;
  if (trustProxy && trustProxy !== 'false') {
    if (trustProxy === 'true') {
      app.set('trust proxy', true);
    } else if (/^\d+$/.test(trustProxy)) {
      app.set('trust proxy', Number(trustProxy));
    } else {
      app.set('trust proxy', trustProxy);
    }
  }

  const mcpServer = createServer();
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: () => randomUUID(),
  });

  const registrationEnabled = process.env.MCP_REGISTRATION_ENABLED === 'true';
  const hasStaticClient = !!(process.env.MCP_CLIENT_ID && process.env.MCP_CLIENT_SECRET);
  const registrationToken = process.env.MCP_REGISTRATION_TOKEN;

  if (authEnabled) {
    const issuerUrl = process.env.MCP_AUTH_ISSUER;
    if (!issuerUrl) {
      throw new Error('MCP_AUTH_ISSUER must be set when MCP_AUTH_ENABLED=true');
    }
    if (!process.env.MCP_AUTH_SECRET) {
      throw new Error('MCP_AUTH_SECRET must be set when MCP_AUTH_ENABLED=true');
    }

    if (!registrationEnabled && !hasStaticClient) {
      logger.warn(
        'No clients configured. Set MCP_CLIENT_ID + MCP_CLIENT_SECRET or MCP_REGISTRATION_ENABLED=true'
      );
    }

    const provider = new NextcloudOAuthProvider();

    // When gated dynamic registration is desired, require a bearer token on POST /register.
    if (registrationEnabled && registrationToken) {
      app.use('/register', (req: any, res: any, next: any) => {
        if (req.headers['authorization'] !== `Bearer ${registrationToken}`) {
          res.status(401).json({
            error: 'unauthorized_client',
            error_description: 'Valid registration token required',
          });
          return;
        }
        next();
      });
    }

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
    logger.info({ host, port, path: MCP_PATH }, 'AIquila MCP server running (http transport)');
    if (authEnabled) {
      const tp = process.env.MCP_TRUST_PROXY;
      logger.info({ issuer: process.env.MCP_AUTH_ISSUER }, 'OAuth 2.0 authentication enabled');
      logger.info(
        { trustProxy: tp && tp !== 'false' ? tp : 'disabled' },
        'Trust proxy setting (set MCP_TRUST_PROXY=1 if behind a reverse proxy)'
      );
      if (hasStaticClient) {
        logger.info({ clientId: process.env.MCP_CLIENT_ID }, 'Static OAuth client configured');
      }
      if (registrationEnabled) {
        logger.info({ gated: !!registrationToken }, 'Dynamic client registration enabled');
      }
    }
    logger.info('View logs: docker compose logs -f   or   make logs-follow');
  });
}
