// SPDX-License-Identifier: MIT

// Test harness for the RFC conformance suite: the real HTTP app from
// createHttpApp() on an ephemeral port, in front of a fake Nextcloud that
// answers the few OCS endpoints the authorization server calls. Each suite
// starts its own stack, so rate limits and stores start empty.

import { createHash, randomBytes } from 'node:crypto';
import { mkdtempSync, rmSync } from 'node:fs';
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { logger } from '../../logger.js';
import { _resetCache } from '../../tool-registry.js';

export const REDIRECT_URI = 'http://127.0.0.1:9999/callback';
export const NC_PASSWORD = 'correct horse battery staple';

export interface FakeUser {
  password: string;
  enabled: boolean;
}

/** A Nextcloud that knows the endpoints the auth flow and the startup probe use. */
export class FakeNextcloud {
  readonly users = new Map<string, FakeUser>([
    ['alice', { password: NC_PASSWORD, enabled: true }],
    ['bob', { password: NC_PASSWORD, enabled: true }],
  ]);
  // Every request's headers, newest last.
  readonly requests: { url: string; headers: IncomingMessage['headers'] }[] = [];
  private server?: Server;
  url = '';

  async start(): Promise<void> {
    this.server = createServer((req, res) => this.handle(req, res));
    await new Promise<void>((r) => this.server!.listen(0, '127.0.0.1', r));
    this.url = `http://127.0.0.1:${(this.server.address() as AddressInfo).port}`;
  }

  stop(): Promise<void> {
    return new Promise((r) => (this.server ? this.server.close(() => r()) : r()));
  }

  private basicUser(req: IncomingMessage): string | undefined {
    const header = req.headers.authorization ?? '';
    if (!header.startsWith('Basic ')) return undefined;
    const [user, ...rest] = Buffer.from(header.slice(6), 'base64').toString().split(':');
    const known = this.users.get(user);
    return known && known.password === rest.join(':') && known.enabled ? user : undefined;
  }

  private handle(req: IncomingMessage, res: ServerResponse): void {
    this.requests.push({ url: req.url ?? '', headers: req.headers });
    const json = (status: number, statuscode: number, data: unknown) => {
      res.writeHead(status, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ ocs: { meta: { statuscode }, data } }));
    };
    const url = new URL(req.url ?? '/', 'http://nc');
    if (url.pathname === '/status.php') {
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ installed: true, version: '34.0.0' }));
      return;
    }
    if (url.pathname === '/ocs/v2.php/cloud/user') {
      const user = this.basicUser(req);
      if (!user) return json(401, 997, []);
      return json(200, 200, { id: user });
    }
    const m = url.pathname.match(/^\/ocs\/v2\.php\/cloud\/users\/([^/]+)$/);
    if (m) {
      const user = this.users.get(decodeURIComponent(m[1]));
      if (!user) return json(404, 404, []);
      return json(200, 200, { id: m[1], enabled: user.enabled });
    }
    json(404, 404, []);
  }
}

export interface Stack {
  base: string;
  issuer: string;
  resource: string;
  nc: FakeNextcloud;
  stateDir: string;
  close(): Promise<void>;
}

/**
 * Starts the full HTTP app with OAuth on, dynamic registration open and the
 * `status` tool set. `env` overrides or adds variables.
 */
export async function startStack(env: Record<string, string | undefined> = {}): Promise<Stack> {
  logger.level = 'silent';
  const nc = new FakeNextcloud();
  await nc.start();

  const server = createServer();
  await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
  const stateDir = mkdtempSync(join(tmpdir(), 'aiquila-rfc-'));

  const saved = process.env;
  process.env = {
    ...saved,
    MCP_AUTH_ENABLED: 'true',
    MCP_AUTH_SECRET: randomBytes(32).toString('hex'),
    MCP_AUTH_ISSUER: base,
    MCP_AUTH_STATE_DIR: stateDir,
    MCP_REGISTRATION_ENABLED: 'true',
    MCP_CIMD_ENABLED: 'false',
    MCP_TOOLS: 'status',
    NEXTCLOUD_URL: nc.url,
    NEXTCLOUD_USER: 'service',
    NEXTCLOUD_PASSWORD: 'service-password',
    ...env,
  };
  for (const [k, v] of Object.entries(env)) if (v === undefined) delete process.env[k];
  _resetCache();

  const { createHttpApp } = await import('../../transports/http.js');
  const app = await createHttpApp();
  server.on('request', app);

  return {
    base,
    issuer: new URL(base).href,
    resource: new URL('/mcp', base).href,
    nc,
    stateDir,
    async close() {
      server.closeAllConnections();
      await new Promise((r) => server.close(r));
      await nc.stop();
      process.env = saved;
      rmSync(stateDir, { recursive: true, force: true });
    },
  };
}

// --- OAuth client helpers ---

export function pkce(): { verifier: string; challenge: string } {
  const verifier = randomBytes(48).toString('base64url');
  return { verifier, challenge: createHash('sha256').update(verifier).digest('base64url') };
}

export function form(body: Record<string, string | undefined>, headers = {}): RequestInit {
  const clean = Object.fromEntries(
    Object.entries(body).filter((e): e is [string, string] => e[1] !== undefined)
  );
  return {
    method: 'POST',
    redirect: 'manual',
    headers: { 'content-type': 'application/x-www-form-urlencoded', ...headers },
    body: new URLSearchParams(clean).toString(),
  };
}

export async function register(
  s: Stack,
  metadata: Record<string, unknown> = {}
): Promise<{ client_id: string; client_secret?: string; [k: string]: unknown }> {
  const res = await fetch(`${s.base}/register`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({
      client_name: 'RFC test client',
      redirect_uris: [REDIRECT_URI],
      token_endpoint_auth_method: 'none',
      grant_types: ['authorization_code', 'refresh_token'],
      response_types: ['code'],
      ...metadata,
    }),
  });
  if (res.status !== 201) throw new Error(`register: ${res.status} ${await res.text()}`);
  return res.json();
}

/** The hidden fields of the login form /authorize rendered. */
export function hiddenFields(html: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [, name, value] of html.matchAll(
    /<input type="hidden" name="([^"]+)" value="([^"]*)">/g
  )) {
    out[name] = value
      .replace(/&quot;/g, '"')
      .replace(/&#39;/g, "'")
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&amp;/g, '&');
  }
  return out;
}

/** The cookies a response set, as a Cookie request header value. */
export function cookieHeader(res: Response): string {
  return res.headers
    .getSetCookie()
    .map((c) => c.split(';')[0])
    .join('; ');
}

export interface AuthorizeOptions {
  clientId: string;
  challenge: string;
  state?: string;
  scope?: string;
  redirectUri?: string;
  resource?: string;
  username?: string;
  password?: string;
}

/**
 * /authorize, then the login POST a browser would make. Returns the redirect
 * the login answered with.
 */
export async function authorizeAndLogin(s: Stack, o: AuthorizeOptions): Promise<Response> {
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: o.clientId,
    redirect_uri: o.redirectUri ?? REDIRECT_URI,
    code_challenge: o.challenge,
    code_challenge_method: 'S256',
    state: o.state ?? 'xyz',
    ...(o.scope ? { scope: o.scope } : {}),
    ...(o.resource ? { resource: o.resource } : {}),
  });
  const page = await fetch(`${s.base}/authorize?${q}`, { redirect: 'manual' });
  if (page.status !== 200) throw new Error(`authorize: ${page.status} ${await page.text()}`);
  const fields = hiddenFields(await page.text());
  return fetch(
    `${s.base}/auth/login`,
    form(
      { ...fields, username: o.username ?? 'alice', password: o.password ?? NC_PASSWORD },
      { cookie: cookieHeader(page) }
    )
  );
}

/** Runs the whole authorization code flow and returns the token response. */
export async function obtainTokens(
  s: Stack,
  clientId: string,
  extra: Partial<AuthorizeOptions> = {}
): Promise<{ access_token: string; refresh_token: string; [k: string]: unknown }> {
  const { verifier, challenge } = pkce();
  const login = await authorizeAndLogin(s, { clientId, challenge, ...extra });
  const code = new URL(login.headers.get('location')!).searchParams.get('code');
  if (!code) throw new Error(`login: ${login.status} ${login.headers.get('location')}`);
  const res = await fetch(
    `${s.base}/token`,
    form({ grant_type: 'authorization_code', code, code_verifier: verifier, client_id: clientId })
  );
  if (res.status !== 200) throw new Error(`token: ${res.status} ${await res.text()}`);
  return res.json();
}

// --- MCP client helpers ---

export const MODERN = '2026-07-28';
export const LEGACY = '2025-11-25';

export function modernBody(id: number, method: string, params: Record<string, unknown> = {}) {
  return {
    jsonrpc: '2.0',
    id,
    method,
    params: {
      ...params,
      _meta: {
        'io.modelcontextprotocol/protocolVersion': MODERN,
        'io.modelcontextprotocol/clientInfo': { name: 'rfc-test', version: '1.0' },
        'io.modelcontextprotocol/clientCapabilities': {},
      },
    },
  };
}

/** POSTs one JSON-RPC message to /mcp the way a 2026-07-28 client does. */
export function mcp(
  s: Stack,
  body: { method: string; params?: { name?: string } } & Record<string, unknown>,
  opts: { token?: string; headers?: Record<string, string> } = {}
): Promise<Response> {
  const name = body.params?.name;
  return fetch(`${s.base}/mcp`, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      accept: 'application/json, text/event-stream',
      'mcp-protocol-version': MODERN,
      'mcp-method': body.method,
      ...(typeof name === 'string' ? { 'mcp-name': name } : {}),
      ...(opts.token ? { authorization: `Bearer ${opts.token}` } : {}),
      ...opts.headers,
    },
    body: JSON.stringify(body),
  });
}

/** Reads a JSON-RPC response from a JSON or single-event SSE body. */
export async function rpcResult(res: Response): Promise<any> {
  const text = await res.text();
  if ((res.headers.get('content-type') ?? '').includes('text/event-stream')) {
    const data = text
      .split('\n')
      .filter((l) => l.startsWith('data:'))
      .map((l) => l.slice(5).trim())
      .pop();
    return data ? JSON.parse(data) : undefined;
  }
  return text ? JSON.parse(text) : undefined;
}

/** Decodes a JWT's header and payload without verifying it. */
export function decodeJwt(token: string): { header: any; payload: any } {
  const [h, p] = token.split('.');
  return {
    header: JSON.parse(Buffer.from(h, 'base64url').toString()),
    payload: JSON.parse(Buffer.from(p, 'base64url').toString()),
  };
}
