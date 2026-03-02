import { randomUUID } from 'node:crypto';
import https from 'node:https';
import express from 'express';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { mcpAuthRouter } from '@modelcontextprotocol/sdk/server/auth/router.js';
import { requireBearerAuth } from '@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js';
import { createServer, SERVER_VERSION } from '../server.js';
import { NextcloudOAuthProvider } from '../auth/provider.js';
import { loginHandler } from '../auth/login.js';
import { logger } from '../logger.js';
import { fetchStatus } from '../client/ocs.js';

const DEFAULT_PORT = 3339;
const MCP_PATH = '/mcp';

// TLS error codes that indicate a self-signed or untrusted certificate.
// Network errors (ECONNREFUSED, ETIMEDOUT) are not included — they mean the
// proxy is not yet reachable, which is transient and should not fail fast.
const TLS_CERT_ERROR_CODES = new Set([
  'DEPTH_ZERO_SELF_SIGNED_CERT',
  'SELF_SIGNED_CERT_IN_CHAIN',
  'CERT_HAS_EXPIRED',
  'CERT_NOT_YET_VALID',
  'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
  'UNABLE_TO_GET_ISSUER_CERT',
  'ERR_TLS_CERT_ALTNAME_INVALID',
]);

async function checkIssuerTls(issuerUrl: string): Promise<void> {
  return new Promise((resolve) => {
    let url: URL;
    try {
      url = new URL(issuerUrl);
    } catch {
      logger.error({ issuer: issuerUrl }, '[startup] MCP_AUTH_ISSUER is not a valid URL');
      if (process.env.MCP_TLS_STRICT === 'true') process.exit(1);
      resolve();
      return;
    }

    if (url.protocol !== 'https:') {
      logger.error(
        { issuer: issuerUrl },
        '[startup] MCP_AUTH_ISSUER must use https:// — plain http exposes OAuth tokens'
      );
      if (process.env.MCP_TLS_STRICT === 'true') process.exit(1);
      resolve();
      return;
    }

    const req = https.request(
      {
        hostname: url.hostname,
        port: parseInt(url.port || '443', 10),
        path: '/',
        method: 'HEAD',
        rejectUnauthorized: true,
      },
      () => {
        logger.info({ issuer: issuerUrl }, '[startup] TLS certificate valid and trusted');
        resolve();
      }
    );

    req.on('error', (err: NodeJS.ErrnoException) => {
      const code = err.code ?? '';
      if (TLS_CERT_ERROR_CODES.has(code)) {
        const strict = process.env.MCP_TLS_STRICT === 'true';
        const msg =
          `[startup] TLS certificate rejected (${code}). ` +
          `Claude.ai and Claude mobile require a CA-trusted certificate — self-signed certs ` +
          `will cause Claude to refuse the connection. Use Let's Encrypt (via Traefik or Caddy ` +
          `with a real domain) or mount a CA-signed cert.`;
        if (strict) {
          logger.fatal({ issuer: issuerUrl, code }, msg);
          process.exit(1);
        } else {
          logger.error(
            { issuer: issuerUrl, code },
            msg + ' Set MCP_TLS_STRICT=true to make this a hard failure.'
          );
        }
      } else {
        // Transient network error — proxy may not be ready yet; do not fail
        logger.warn(
          { issuer: issuerUrl, code: err.code },
          '[startup] TLS check skipped — could not reach issuer (proxy not ready?)'
        );
      }
      resolve();
    });

    req.end();
  });
}

export async function startHttp(): Promise<void> {
  const port = parseInt(process.env.MCP_PORT || String(DEFAULT_PORT), 10);
  const host = process.env.MCP_HOST || '0.0.0.0';
  const authEnabled = process.env.MCP_AUTH_ENABLED === 'true';

  // When auth is enabled, derive allowedHosts from the public issuer URL.
  // This suppresses the MCP SDK's "binding to 0.0.0.0 without DNS rebinding
  // protection" console.warn (which breaks structured-log parsers like jq) and
  // adds an extra layer of host-header validation in front of the bearer-auth
  // middleware.  Local access (localhost / 127.0.0.1) is always included so
  // test scripts and Docker health-checks keep working.
  let allowedHosts: string[] | undefined;
  if (authEnabled) {
    const issuerStr = process.env.MCP_AUTH_ISSUER;
    if (issuerStr) {
      try {
        const issuerHostname = new URL(issuerStr).hostname;
        allowedHosts = [...new Set([issuerHostname, 'localhost', '127.0.0.1'])];
      } catch {
        // Malformed issuer URL — the validation below will throw a clear error.
      }
    }
  }

  const app = createMcpExpressApp({ host, allowedHosts });

  // When running behind a reverse proxy (e.g. Traefik, nginx, Caddy), the proxy
  // adds X-Forwarded-For headers. Without trust proxy being set, express-rate-limit
  // throws ERR_ERL_UNEXPECTED_X_FORWARDED_FOR. Set MCP_TRUST_PROXY to configure this:
  //   MCP_TRUST_PROXY=1      → trust one hop (recommended for a single reverse proxy)
  //   MCP_TRUST_PROXY=true   → same as 1 (boolean true rejected by express-rate-limit v8)
  //   MCP_TRUST_PROXY=false  → disabled (default)
  const trustProxy = process.env.MCP_TRUST_PROXY;
  if (trustProxy && trustProxy !== 'false') {
    if (trustProxy === 'true') {
      // express-rate-limit v8 rejects the boolean `true` with ERR_ERL_PERMISSIVE_TRUST_PROXY.
      // Treat 'true' as 1 (single proxy hop) — correct for a single Traefik/nginx in front.
      app.set('trust proxy', 1);
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
  const hasStaticClient = !!process.env.MCP_CLIENT_ID;
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
      logger.warn('No clients configured. Set MCP_CLIENT_ID or MCP_REGISTRATION_ENABLED=true');
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
    const oauthPaths = ['/.well-known', '/register', '/authorize', '/token'];
    for (const p of oauthPaths) {
      app.use(p, (req: any, _res: any, next: any) => {
        logger.debug({ method: req.method, path: req.path }, '[oauth] Request');
        next();
      });
    }
    app.use(mcpAuthRouter({ provider, issuerUrl: new URL(issuerUrl) }));

    // Parse URL-encoded form submissions from the login page
    app.use(express.urlencoded({ extended: false }));

    // Nextcloud credential validation → auth code issuance
    app.post('/auth/login', loginHandler(provider));

    // Protect /mcp with Bearer token auth
    app.all(
      MCP_PATH,
      (req: any, res: any, next: any) => {
        res.on('finish', () => {
          if (res.statusCode === 401 || res.statusCode === 403) {
            logger.warn(
              { status: res.statusCode, rpcMethod: req.body?.method },
              '[mcp] Request rejected'
            );
          }
        });
        next();
      },
      requireBearerAuth({ verifier: provider }),
      async (req: any, res: any) => {
        logger.debug({ method: req.method, rpcMethod: req.body?.method }, '[mcp] Request received');
        await transport.handleRequest(req, res, req.body);
      }
    );
  } else {
    app.all(MCP_PATH, async (req: any, res: any) => {
      await transport.handleRequest(req, res, req.body);
    });
  }

  await mcpServer.connect(transport);

  app.listen(port, host, () => {
    logger.info(
      { version: SERVER_VERSION, host, port, path: MCP_PATH },
      'AIquila MCP server running (http transport)'
    );
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
    void (async () => {
      // TLS certificate check (only when auth is enabled and issuer is configured)
      if (authEnabled) {
        await checkIssuerTls(process.env.MCP_AUTH_ISSUER!);
      }
      // Nextcloud reachability probe
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
  });
}
