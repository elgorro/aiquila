// SPDX-License-Identifier: MIT

import https from 'node:https';
import express from 'express';
import { createMcpHandler } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { createMcpExpressApp, requireBearerAuth } from '@modelcontextprotocol/express';
import { createServer, SERVER_VERSION } from '../server.js';
import { NextcloudOAuthProvider } from '../auth/provider.js';
import {
  probeStateDir,
  StateDirNotWritableError,
  stateUnwritableMessage,
  markStateUnwritableWarned,
  cimdEnabledFromEnv,
} from '../auth/store.js';
import { loginHandler } from '../auth/login.js';
import { authRouter } from '../auth/router.js';
import { LOGIN_STYLESHEET, LOGIN_STYLESHEET_PATH } from '../auth/login-page-css.js';
import { isPublicRequest } from './lazy-auth.js';
import { internalNetworks, internalTokenAllowed, isInternalToken } from '../auth/internal-token.js';
import { logger } from '../logger.js';
import { SUPPORT_NOTICE } from '../support.js';
import { fetchStatus } from '../client/ocs.js';

const DEFAULT_PORT = 3339;
const MCP_PATH = '/mcp';
const SCOPES_SUPPORTED = ['mcp:tools', 'mcp:resources', 'mcp:prompts'];

// Scope advertised in the WWW-Authenticate challenge. Clients request exactly
// this on authorisation; omitting it makes them fall back to the full
// `scopes_supported` union, which produces an over-broad consent prompt.
const CHALLENGE_SCOPE = 'mcp:tools';

// TLS error codes that indicate a self-signed or untrusted certificate.
// Network errors (ECONNREFUSED, ETIMEDOUT) are not included — they mean the
// proxy is not yet reachable, which is transient and should not log a warning.
const TLS_CERT_ERROR_CODES = new Set([
  'DEPTH_ZERO_SELF_SIGNED_CERT',
  'SELF_SIGNED_CERT_IN_CHAIN',
  'CERT_HAS_EXPIRED',
  'CERT_NOT_YET_VALID',
  'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
  'UNABLE_TO_GET_ISSUER_CERT',
  'ERR_TLS_CERT_ALTNAME_INVALID',
]);

/**
 * Advisory TLS certificate check — purely informational, never crashes the server.
 *
 * Most MCP clients require a CA-trusted cert. If the issuer URL still has a
 * self-signed or untrusted cert at startup this is logged as a warning so
 * operators know to fix it, but the MCP server continues running normally.
 *
 * Rationale: crashing or health-check-failing on a bad cert creates a deadlock on
 * fresh deployments — the container goes unhealthy → Traefik removes it from routing →
 * the ACME HTTP-01 challenge can never complete → the cert is never issued → repeat.
 */
async function checkIssuerTls(issuerUrl: string): Promise<void> {
  let url: URL;
  try {
    url = new URL(issuerUrl);
  } catch {
    logger.warn(
      { issuer: issuerUrl },
      '[startup] MCP_AUTH_ISSUER is not a valid URL — skipping TLS check'
    );
    return;
  }

  if (url.protocol !== 'https:') {
    logger.warn(
      { issuer: issuerUrl },
      '[startup] MCP_AUTH_ISSUER uses http:// — plain http exposes OAuth tokens; use https://'
    );
    return;
  }

  return new Promise((resolve) => {
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
        logger.warn(
          { issuer: issuerUrl, code },
          `[startup] TLS certificate not yet trusted (${code}). ` +
            `Most MCP clients require a CA-trusted certificate — self-signed certs ` +
            `may cause clients to refuse the connection. ` +
            `Use Let's Encrypt (via Traefik or Caddy with a real domain) or mount a CA-signed cert. ` +
            `The MCP server will keep running; re-deploy once the cert is valid.`
        );
      } else {
        logger.debug(
          { issuer: issuerUrl, code: err.code },
          '[startup] TLS check skipped — could not reach issuer (proxy not ready yet)'
        );
      }
      resolve();
    });

    req.end();
  });
}

/**
 * Builds the HTTP application: CORS, health check, the OAuth authorization
 * server when MCP_AUTH_ENABLED=true, and the MCP endpoint. Reads its
 * configuration from the environment. startHttp() listens on it; tests drive
 * it directly.
 */
export async function createHttpApp(): Promise<express.Express> {
  const host = process.env.MCP_HOST || '0.0.0.0';
  const authEnabled = process.env.MCP_AUTH_ENABLED === 'true';

  // Lazy authentication is on by default: clients need to inspect a connector
  // before signing in. Operators who treat /mcp as a fully closed endpoint can
  // set MCP_LAZY_AUTH=false to require a bearer token on every JSON-RPC method.
  const lazyAuthEnabled = process.env.MCP_LAZY_AUTH !== 'false';

  if (!authEnabled) {
    if (process.env.MCP_ALLOW_UNAUTHENTICATED !== 'true') {
      throw new Error(
        'HTTP transport requires MCP_AUTH_ENABLED=true. ' +
          'Set MCP_ALLOW_UNAUTHENTICATED=true to override (development only).'
      );
    }
    logger.warn('[startup] Running in UNAUTHENTICATED mode — do not use in production');
  }

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

  // MCP_ALLOWED_HOSTS lets operators add extra trusted hostnames (e.g. Docker
  // service names like "mcp") so that container-to-container requests pass the
  // SDK's DNS rebinding protection.  Comma-separated, whitespace-trimmed.
  const extraHosts = process.env.MCP_ALLOWED_HOSTS;
  if (extraHosts) {
    const extras = extraHosts
      .split(',')
      .map((h) => h.trim())
      .filter(Boolean);
    if (allowedHosts) {
      allowedHosts = [...new Set([...allowedHosts, ...extras])];
    } else {
      allowedHosts = [...new Set(['localhost', '127.0.0.1', ...extras])];
    }
  }

  // Explicit CORS. Browser-based MCP clients need these headers; previously the
  // server sent none and relied entirely on the reverse proxy. The issuer origin
  // is trusted by default (it serves the login page), and MCP_CORS_ORIGINS adds
  // extra origins — comma-separated, whitespace-trimmed, same idiom as
  // MCP_ALLOWED_HOSTS. Origins are matched exactly and echoed back; the wildcard
  // '*' is never sent, since these endpoints are credentialed.
  const allowedOrigins = new Set<string>();
  const issuerForCors = process.env.MCP_AUTH_ISSUER;
  if (authEnabled && issuerForCors) {
    try {
      allowedOrigins.add(new URL(issuerForCors).origin);
    } catch {
      // Malformed issuer URL — the validation below will throw a clear error.
    }
  }
  const extraOrigins = process.env.MCP_CORS_ORIGINS;
  if (extraOrigins) {
    for (const raw of extraOrigins.split(',')) {
      const trimmed = raw.trim();
      if (!trimmed) continue;
      try {
        allowedOrigins.add(new URL(trimmed).origin);
      } catch {
        logger.warn({ origin: trimmed }, '[startup] Ignoring malformed MCP_CORS_ORIGINS entry');
      }
    }
  }

  const app = createMcpExpressApp({ host, allowedHosts });

  // Mounted ahead of every other route so that preflights are answered before
  // the auth chain runs — an OPTIONS request carries no Authorization header
  // and would otherwise be rejected with 401 by requireBearerAuth.
  app.use((req: any, res: any, next: any) => {
    const origin = req.headers?.origin;
    if (typeof origin === 'string' && allowedOrigins.has(origin)) {
      res.setHeader('Access-Control-Allow-Origin', origin);
      res.setHeader('Access-Control-Allow-Credentials', 'true');
      res.setHeader(
        'Access-Control-Allow-Headers',
        'Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Session-Id, Last-Event-ID'
      );
      res.setHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, WWW-Authenticate');
      res.setHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
      res.setHeader('Access-Control-Max-Age', '86400');
    }
    // Always vary on Origin: the response differs per origin even when no
    // headers are added, so a shared cache must not reuse one for another.
    res.setHeader('Vary', 'Origin');
    if (req.method === 'OPTIONS') {
      res.status(204).end();
      return;
    }
    next();
  });

  // Stylesheet for the OAuth login page. Served as its own document so the page
  // needs no inline <style>, which lets its CSP drop 'unsafe-inline'. Must stay
  // ahead of the auth middleware — the login page is shown to anonymous users.
  app.get(LOGIN_STYLESHEET_PATH, (_req: any, res: any) => {
    res
      .set('Content-Type', 'text/css; charset=utf-8')
      .set('Cache-Control', 'public, max-age=86400')
      .set('X-Content-Type-Options', 'nosniff')
      .status(200)
      .send(LOGIN_STYLESHEET);
  });

  // Simple health check — bypasses all auth middleware so Docker health checks
  // work even before OAuth is fully configured or TLS is verified.
  app.get('/health', (_req: any, res: any) => {
    res.status(200).json({ status: 'ok' });
  });

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

  // Dual-era and stateless. A request carrying the per-request `_meta`
  // envelope is served as 2026-07-28; anything else (the `initialize`
  // handshake and every 2025-era request after it) goes to the legacy leg,
  // which answers each request from a fresh server over a session-less
  // streamable transport — the same model this server has always used, so
  // distributed clients (Claude.ai, Cursor, …) can reach it from several IPs
  // without a shared session. Both legs answer GET and DELETE with 405.
  // `toNodeHandler` forwards `req.auth` (set by requireBearerAuth) and the
  // already-parsed `req.body`.
  const mcpHandler = toNodeHandler(
    createMcpHandler(() => createServer(), {
      legacy: 'stateless',
      onerror: (err) => logger.warn({ err: err.message }, '[mcp] Request error'),
    })
  );
  const handleMcpRequest = (req: any, res: any) => mcpHandler(req, res, req.body);

  // MCP 2026-07-28, Streamable HTTP, Security §1: the Origin header MUST be
  // validated on every request to the MCP endpoint, and one that is present
  // and not allowed is answered 403 (DNS rebinding). Requests without Origin
  // (server-side clients, the Nextcloud app) are unaffected. The allowed set
  // is the CORS one: the issuer origin plus MCP_CORS_ORIGINS.
  const checkOrigin = (req: any, res: any, next: any) => {
    const origin = req.headers?.origin;
    if (typeof origin === 'string' && !allowedOrigins.has(origin)) {
      logger.warn({ origin }, '[mcp] Request from a disallowed Origin rejected');
      res.status(403).json({
        jsonrpc: '2.0',
        error: { code: -32600, message: 'Origin not allowed' },
      });
      return;
    }
    next();
  };
  app.use(MCP_PATH, checkOrigin);
  app.all('/', checkOrigin);

  const registrationEnabled = process.env.MCP_REGISTRATION_ENABLED === 'true';
  const hasStaticClient = !!process.env.MCP_CLIENT_ID;
  const registrationToken = process.env.MCP_REGISTRATION_TOKEN;
  const cimdEnabled = cimdEnabledFromEnv();

  if (authEnabled) {
    const issuerUrl = process.env.MCP_AUTH_ISSUER;
    if (!issuerUrl) {
      throw new Error('MCP_AUTH_ISSUER must be set when MCP_AUTH_ENABLED=true');
    }
    if (!process.env.MCP_AUTH_SECRET) {
      throw new Error('MCP_AUTH_SECRET must be set when MCP_AUTH_ENABLED=true');
    }

    try {
      probeStateDir();
    } catch (err) {
      if (err instanceof StateDirNotWritableError) {
        // Degrade gracefully rather than crash-loop: the server still serves
        // requests, it just can't persist OAuth tokens until the operator fixes
        // the volume. Crashing here was un-fixable under `restart: unless-stopped`
        // because `docker compose exec` needs a running container (discussion #342).
        logger.warn(
          { dir: err.dir, code: err.cause.code },
          stateUnwritableMessage(err.dir, err.cause.code)
        );
        markStateUnwritableWarned();
      } else {
        throw err;
      }
    }

    const provider = new NextcloudOAuthProvider();
    const internalTokens = internalNetworks();

    if (!registrationEnabled && !hasStaticClient && !cimdEnabled) {
      logger.warn(
        'No clients configured. Set MCP_CLIENT_ID, MCP_REGISTRATION_ENABLED=true or MCP_CIMD_ENABLED=true'
      );
    }

    // OAuth discovery + authorization server endpoints
    const oauthPaths = ['/.well-known', '/register', '/authorize', '/token', '/revoke'];
    for (const p of oauthPaths) {
      app.use(p, (req: any, _res: any, next: any) => {
        logger.debug({ method: req.method, path: req.path }, '[oauth] Request');
        next();
      });
    }
    app.use(
      authRouter({
        provider,
        issuerUrl: new URL(issuerUrl),
        resourceServerUrl: new URL(MCP_PATH, issuerUrl),
        serviceDocumentationUrl: new URL('https://github.com/elgorro/aiquila'),
        scopesSupported: SCOPES_SUPPORTED,
        registrationToken: registrationEnabled ? registrationToken : undefined,
      })
    );

    // Fallback: serve protected resource metadata at the root well-known path too,
    // so clients that use the server root URL (without /mcp) can discover the resource.
    // authRouter only mounts it at /.well-known/oauth-protected-resource/mcp per RFC 9728.
    app.get('/.well-known/oauth-protected-resource', (_req, res) => {
      res.json({
        resource: new URL(MCP_PATH, issuerUrl).href,
        authorization_servers: [issuerUrl],
        scopes_supported: SCOPES_SUPPORTED,
        resource_documentation: 'https://github.com/elgorro/aiquila',
      });
    });

    // Parse URL-encoded form submissions from the login page
    app.use(express.urlencoded({ extended: false }));

    // Nextcloud credential validation → auth code issuance
    const loginRateLimit = (() => {
      const attempts = new Map<string, { count: number; resetAt: number }>();
      const MAX = 10,
        WINDOW_MS = 15 * 60 * 1000;
      return (req: any, res: any, next: any) => {
        const ip = req.ip ?? 'unknown';
        const now = Date.now();
        const entry = attempts.get(ip);
        if (!entry || now > entry.resetAt) {
          attempts.set(ip, { count: 1, resetAt: now + WINDOW_MS });
          return next();
        }
        entry.count++;
        if (entry.count > MAX) {
          logger.warn({ ip }, '[auth] Login rate limit exceeded');
          res.status(429).json({ error: 'too_many_requests' });
          return;
        }
        next();
      };
    })();
    app.post('/auth/login', loginRateLimit, loginHandler(provider));

    // Protect /mcp (and / as alias) with Bearer token auth
    const authMiddleware = [
      (req: any, res: any, next: any) => {
        res.on('finish', () => {
          if (res.statusCode === 401 || res.statusCode === 403) {
            logger.warn(
              { status: res.statusCode, rpcMethod: req.body?.method },
              '[mcp] Request rejected'
            );
          }
        });

        // The SDK appends `scope="..."` to the challenge only when
        // `requiredScopes` is set — but setting that would also *enforce* the
        // scope, and 403 every token issued before scopes were requested (plus
        // the internal service token, which carries none). Advertise the scope
        // without enforcing it by decorating the header on its way out.
        const setHeader = res.setHeader.bind(res);
        res.setHeader = (name: string, value: any) => {
          if (
            typeof name === 'string' &&
            name.toLowerCase() === 'www-authenticate' &&
            typeof value === 'string' &&
            !value.includes('scope=')
          ) {
            value = `${value}, scope="${CHALLENGE_SCOPE}"`;
          }
          return setHeader(name, value);
        };
        next();
      },

      // Lazy-auth gate: the handshake, capability listings, and public tools are
      // served anonymously so clients can inspect the connector before sign-in.
      // Everything else falls through to requireBearerAuth below.
      // Omitted entirely when MCP_LAZY_AUTH=false, restoring a chain in which
      // every JSON-RPC method requires a bearer token.
      ...(lazyAuthEnabled
        ? [
            async (req: any, res: any, next: any) => {
              if (!req.headers.authorization && isPublicRequest(req.body)) {
                logger.debug(
                  { rpcMethod: req.body?.method },
                  '[mcp] Public request (unauthenticated)'
                );
                await handleMcpRequest(req, res);
                return;
              }
              next();
            },
          ]
        : []),

      // MCP_INTERNAL_TOKEN works only on a direct connection from an internal
      // address; through the reverse proxy it is an invalid token.
      (req: any, res: any, next: any) => {
        const header = req.headers.authorization;
        const token =
          typeof header === 'string' && /^Bearer /i.test(header) ? header.slice(7).trim() : '';
        if (token && isInternalToken(token) && !internalTokenAllowed(req, internalTokens)) {
          logger.warn(
            { address: req.socket?.remoteAddress },
            '[auth] MCP_INTERNAL_TOKEN used from outside the internal network — rejected'
          );
          res
            .status(401)
            .set(
              'WWW-Authenticate',
              `Bearer error="invalid_token", error_description="Invalid token", resource_metadata="${new URL('/.well-known/oauth-protected-resource/mcp', issuerUrl).href}"`
            )
            .json({ error: 'invalid_token', error_description: 'Invalid token' });
          return;
        }
        next();
      },

      requireBearerAuth({
        verifier: provider,
        resourceMetadataUrl: new URL('/.well-known/oauth-protected-resource/mcp', issuerUrl).href,
      }),
      async (req: any, res: any) => {
        logger.debug({ method: req.method, rpcMethod: req.body?.method }, '[mcp] Request received');
        await handleMcpRequest(req, res);
      },
    ];
    app.all(MCP_PATH, ...authMiddleware);
    app.all('/', ...authMiddleware);
  } else {
    const mcpHandler = async (req: any, res: any) => {
      await handleMcpRequest(req, res);
    };
    app.all(MCP_PATH, mcpHandler);
    app.all('/', mcpHandler);
  }

  return app;
}

export async function startHttp(): Promise<void> {
  const port = parseInt(process.env.MCP_PORT || String(DEFAULT_PORT), 10);
  const host = process.env.MCP_HOST || '0.0.0.0';
  const authEnabled = process.env.MCP_AUTH_ENABLED === 'true';
  const lazyAuthEnabled = process.env.MCP_LAZY_AUTH !== 'false';
  const registrationEnabled = process.env.MCP_REGISTRATION_ENABLED === 'true';
  const hasStaticClient = !!process.env.MCP_CLIENT_ID;
  const registrationToken = process.env.MCP_REGISTRATION_TOKEN;
  const cimdEnabled = cimdEnabledFromEnv();

  const app = await createHttpApp();
  app.listen(port, host, () => {
    logger.info(
      { version: SERVER_VERSION, host, port, path: MCP_PATH },
      'AIquila MCP server running (http transport)'
    );
    if (authEnabled) {
      const tp = process.env.MCP_TRUST_PROXY;
      logger.info({ issuer: process.env.MCP_AUTH_ISSUER }, 'OAuth 2.0 authentication enabled');
      logger.info(
        { lazyAuth: lazyAuthEnabled },
        lazyAuthEnabled
          ? 'Lazy auth enabled — tools/list and public tools are readable without a token (set MCP_LAZY_AUTH=false to require one)'
          : 'Lazy auth disabled — every JSON-RPC method requires a bearer token'
      );
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
      logger.info(
        { cimd: cimdEnabled },
        cimdEnabled
          ? 'Client ID Metadata Documents enabled — https:// client_ids are fetched and validated'
          : 'Client ID Metadata Documents disabled'
      );
    }
    if (process.env.MCP_INTERNAL_TOKEN) {
      logger.info('Internal bearer token configured (MCP_INTERNAL_TOKEN)');
    }
    logger.info('View logs: docker compose logs -f   or   make logs-follow');
    logger.info(SUPPORT_NOTICE);
    void (async () => {
      // Advisory TLS check — logs a warning if the cert is self-signed or untrusted,
      // but never crashes the server or delays startup.
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
