// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Mock createServer to avoid pulling in all tool dependencies
const mockServer = {};

vi.mock('../server.js', () => ({
  createServer: vi.fn(() => mockServer),
  SERVER_VERSION: '0.0.0-test',
}));

vi.mock('../tool-registry.js', () => ({
  getFilteredToolSets: vi.fn().mockResolvedValue([]),
}));

// Mock the MCP SDK serving entries
vi.mock('@modelcontextprotocol/server/stdio', () => ({
  serveStdio: vi.fn(() => ({ close: vi.fn() })),
}));

const mockMcpHandler = { fetch: vi.fn(), close: vi.fn() };
vi.mock('@modelcontextprotocol/server', () => ({
  createMcpHandler: vi.fn(() => mockMcpHandler),
}));

const mockNodeHandler = vi.fn();
vi.mock('@modelcontextprotocol/node', () => ({
  toNodeHandler: vi.fn(() => mockNodeHandler),
}));

const mockListen = vi.fn((_port: number, _host: string, cb: () => void) => cb());
const mockAll = vi.fn();
const mockUse = vi.fn();
const mockPost = vi.fn();
const mockGet = vi.fn();
const mockSet = vi.fn();
const mockExpressApp = {
  all: mockAll,
  get: mockGet,
  set: mockSet,
  listen: mockListen,
  use: mockUse,
  post: mockPost,
};
const mockBearerMiddleware = vi.fn();
vi.mock('@modelcontextprotocol/express', () => ({
  createMcpExpressApp: vi.fn(() => mockExpressApp),
  requireBearerAuth: vi.fn(() => mockBearerMiddleware),
}));

// Mock express (needed when auth is enabled)
const mockUrlencoded = vi.fn(() => 'urlencoded-middleware');
vi.mock('express', () => ({
  default: { urlencoded: mockUrlencoded },
}));

// Mock the authorization server router
const mockMcpAuthMiddleware = vi.fn();
vi.mock('../auth/router.js', () => ({
  authRouter: vi.fn(() => mockMcpAuthMiddleware),
}));

// Mock ocs fetchStatus to prevent real network calls during startup probe
vi.mock('../client/ocs.js', () => ({
  fetchStatus: vi.fn().mockResolvedValue({ version: '28.0.0' }),
}));

// Mock the auth provider and login handler
const mockProviderInstance = {};
vi.mock('../auth/provider.js', () => ({
  NextcloudOAuthProvider: vi.fn(function () {
    return mockProviderInstance;
  }),
  renderLoginForm: vi.fn(() => '<form></form>'),
}));

const mockLoginHandlerFn = vi.fn();
vi.mock('../auth/login.js', () => ({
  loginHandler: vi.fn(() => mockLoginHandlerFn),
}));

// ---- stdio transport ----

describe('stdio transport', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('serves stdio through serveStdio with a createServer factory', async () => {
    const { serveStdio } = await import('@modelcontextprotocol/server/stdio');
    const { createServer } = await import('../server.js');
    const { startStdio } = await import('../transports/stdio.js');
    await startStdio();

    expect(serveStdio).toHaveBeenCalledTimes(1);
    const [factory, opts] = vi.mocked(serveStdio).mock.calls[0];
    // The default legacy: 'serve' keeps 2025-era clients (Claude Desktop) working.
    expect(opts?.legacy).toBeUndefined();
    await factory({ era: 'modern' });
    expect(createServer).toHaveBeenCalled();
  });

  it('resolves the tool set at startup, before the first message', async () => {
    const { getFilteredToolSets } = await import('../tool-registry.js');
    const { startStdio } = await import('../transports/stdio.js');
    await startStdio();

    expect(getFilteredToolSets).toHaveBeenCalled();
  });
});

// ---- http transport (auth disabled) ----

describe('http transport', () => {
  const savedEnv = process.env;

  beforeEach(() => {
    vi.clearAllMocks();
    process.env = { ...savedEnv, MCP_ALLOW_UNAUTHENTICATED: 'true' };
    delete process.env.MCP_AUTH_ENABLED;
  });

  afterEach(() => {
    process.env = savedEnv;
  });

  it('forwards /mcp requests to the SDK handler with the parsed body', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    const mcpCall = mockAll.mock.calls.find((c) => c[0] === '/mcp');
    const handler = mcpCall?.[1] as (req: unknown, res: unknown) => Promise<void>;
    expect(handler).toBeDefined();
    const req = { body: { jsonrpc: '2.0', id: 1, method: 'tools/list' } };
    const res = { on: vi.fn() };
    await handler(req, res);

    expect(mockNodeHandler).toHaveBeenCalledWith(req, res, req.body);
  });

  it('builds the handler once, from a createServer factory', async () => {
    const { createMcpHandler } = await import('@modelcontextprotocol/server');
    const { createServer } = await import('../server.js');
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(createMcpHandler).toHaveBeenCalledTimes(1);
    const factory = vi.mocked(createMcpHandler).mock.calls[0][0];
    await factory({ era: 'modern' });
    expect(createServer).toHaveBeenCalled();
  });

  it('should mount handler on /mcp', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockAll).toHaveBeenCalledWith('/mcp', expect.any(Function));
  });

  // Stateless mode makes the SDK answer `GET /mcp` with 405, because it does not offer the
  // optional server→client SSE stream. That is spec-compliant and the SDK *client* tolerates it
  // (streamableHttp.js: "405 indicates that the server does not offer an SSE stream at GET
  // endpoint"). Pinned so a future change doesn't add a session-backed GET stream in response to
  // a 405 sighting in devtools — see #384, where that 405 was a red herring.
  //
  // `legacy: 'stateless'` serves 2025-era clients that way; `'reject'` would cut off every
  // client that still opens with `initialize`.
  it('serves 2025-era clients statelessly (no sessions)', async () => {
    const { createMcpHandler } = await import('@modelcontextprotocol/server');
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(vi.mocked(createMcpHandler).mock.calls[0][1]).toMatchObject({ legacy: 'stateless' });
  });

  it('should listen on default port 3339', async () => {
    delete process.env.MCP_PORT;
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockListen).toHaveBeenCalledWith(3339, '0.0.0.0', expect.any(Function));
  });

  it('should respect MCP_PORT env var', async () => {
    process.env.MCP_PORT = '4000';
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockListen).toHaveBeenCalledWith(4000, '0.0.0.0', expect.any(Function));
  });

  it('should respect MCP_HOST env var', async () => {
    process.env.MCP_HOST = '127.0.0.1';
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockListen).toHaveBeenCalledWith(expect.any(Number), '127.0.0.1', expect.any(Function));
  });

  it('should not mount auth middleware when MCP_AUTH_ENABLED is unset', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockPost).not.toHaveBeenCalled();
    // /mcp is mounted with only one handler (no bearer middleware)
    expect(mockAll).toHaveBeenCalledWith('/mcp', expect.any(Function));
  });

  it('registers GET /health endpoint', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockGet).toHaveBeenCalledWith('/health', expect.any(Function));
  });

  it('/health handler returns 200 {"status":"ok"}', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    const healthCall = mockGet.mock.calls.find((c) => c[0] === '/health');
    const handler = healthCall?.[1];
    const res = { status: vi.fn().mockReturnThis(), json: vi.fn() };
    handler({}, res);
    expect(res.status).toHaveBeenCalledWith(200);
    expect(res.json).toHaveBeenCalledWith({ status: 'ok' });
  });
});

// ---- http transport (auth enabled) ----

describe('http transport with auth enabled', () => {
  const savedEnv = process.env;
  let stateDir: string;

  beforeEach(() => {
    vi.clearAllMocks();
    stateDir = mkdtempSync(join(tmpdir(), 'aiquila-transports-'));
    process.env = {
      ...savedEnv,
      MCP_AUTH_ENABLED: 'true',
      MCP_AUTH_SECRET: 'test-secret-min-32-chars-required!!',
      MCP_AUTH_ISSUER: 'https://mcp.example.com',
      MCP_AUTH_STATE_DIR: stateDir,
    };
  });

  afterEach(() => {
    process.env = savedEnv;
    rmSync(stateDir, { recursive: true, force: true });
  });

  it('throws when MCP_AUTH_ISSUER is missing', async () => {
    delete process.env.MCP_AUTH_ISSUER;
    const { startHttp } = await import('../transports/http.js');
    await expect(startHttp()).rejects.toThrow('MCP_AUTH_ISSUER');
  });

  it('throws when MCP_AUTH_SECRET is missing', async () => {
    delete process.env.MCP_AUTH_SECRET;
    const { startHttp } = await import('../transports/http.js');
    await expect(startHttp()).rejects.toThrow('MCP_AUTH_SECRET');
  });

  it('mounts the OAuth auth router via app.use', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockUse).toHaveBeenCalledWith(mockMcpAuthMiddleware);
  });

  it('mounts urlencoded body parser via app.use', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockUse).toHaveBeenCalledWith('urlencoded-middleware');
  });

  it('registers POST /auth/login handler', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockPost).toHaveBeenCalledWith('/auth/login', expect.any(Function), mockLoginHandlerFn);
  });

  it('mounts /mcp with the lazy-auth gate ahead of the bearer middleware', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockAll).toHaveBeenCalledWith(
      '/mcp',
      expect.any(Function), // logging + challenge-scope decoration
      expect.any(Function), // lazy-auth gate
      expect.any(Function), // internal-token network check
      mockBearerMiddleware,
      expect.any(Function) // authenticated handler
    );
  });

  it('omits the lazy-auth gate when MCP_LAZY_AUTH=false', async () => {
    process.env.MCP_LAZY_AUTH = 'false';
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    // Chain collapses to logging + internal-token check + bearer + handler:
    // every method needs a token.
    expect(mockAll).toHaveBeenCalledWith(
      '/mcp',
      expect.any(Function),
      expect.any(Function),
      mockBearerMiddleware,
      expect.any(Function)
    );

    const mcpCall = mockAll.mock.calls.find((c) => c[0] === '/mcp');
    expect(mcpCall).toHaveLength(5);
    expect(mcpCall?.[3]).toBe(mockBearerMiddleware);
  });

  it('points the 401 challenge at the protected resource metadata', async () => {
    const { requireBearerAuth } = await import('@modelcontextprotocol/express');
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(requireBearerAuth).toHaveBeenCalledWith(
      expect.objectContaining({
        resourceMetadataUrl: expect.stringContaining(
          '/.well-known/oauth-protected-resource/mcp'
        ) as unknown as string,
      })
    );

    // Scopes must not be enforced: tokens issued without them (and the internal
    // service token) would otherwise start getting 403 insufficient_scope.
    const opts = (requireBearerAuth as unknown as { mock: { calls: [Record<string, unknown>][] } })
      .mock.calls[0][0];
    expect(opts.requiredScopes).toBeUndefined();
  });

  it('serves public requests without a bearer token', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    const mcpCall = mockAll.mock.calls.find((c) => c[0] === '/mcp');
    const gate = mcpCall?.[2] as (req: any, res: any, next: any) => Promise<void>;

    const next = vi.fn();
    const res = { on: vi.fn(), setHeader: vi.fn() };
    await gate(
      { headers: {}, body: { jsonrpc: '2.0', id: 1, method: 'tools/list' } },
      res as any,
      next
    );
    expect(next).not.toHaveBeenCalled();

    // ...but a protected tool falls through to the bearer middleware.
    const next2 = vi.fn();
    await gate(
      {
        headers: {},
        body: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'list_files' } },
      },
      res as any,
      next2
    );
    expect(next2).toHaveBeenCalled();
  });

  it('still forwards authenticated requests to the SDK handler', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    const mcpCall = mockAll.mock.calls.find((c) => c[0] === '/mcp');
    const handler = mcpCall?.[mcpCall.length - 1] as (req: unknown, res: unknown) => Promise<void>;
    expect(handler).toBeDefined();
    const req = { body: { jsonrpc: '2.0', id: 1, method: 'tools/call' } };
    const res = { on: vi.fn() };
    await handler(req, res);

    expect(mockNodeHandler).toHaveBeenCalledWith(req, res, req.body);
  });

  // ---- CORS ----

  /** Runs the CORS middleware (the first app.use registered) against a fake request. */
  async function runCors(headers: Record<string, string>, method = 'GET') {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    const middleware = mockUse.mock.calls
      .map((c) => c[0])
      .find((fn) => typeof fn === 'function' && fn.length === 3) as (
      req: unknown,
      res: unknown,
      next: unknown
    ) => void;
    expect(middleware).toBeDefined();

    const set: Record<string, string> = {};
    const next = vi.fn();
    const end = vi.fn();
    const res = {
      setHeader: (k: string, v: string) => {
        set[k] = v;
      },
      status: vi.fn(function (this: unknown) {
        return { end };
      }),
      end,
    };
    middleware({ headers, method }, res, next);
    return { set, next, res, end };
  }

  it('echoes the issuer origin back as an allowed CORS origin', async () => {
    const { set, next } = await runCors({ origin: 'https://mcp.example.com' });
    expect(set['Access-Control-Allow-Origin']).toBe('https://mcp.example.com');
    expect(set['Vary']).toBe('Origin');
    expect(next).toHaveBeenCalled();
  });

  it('does not send CORS headers for a foreign origin', async () => {
    const { set, next } = await runCors({ origin: 'https://evil.example' });
    expect(set['Access-Control-Allow-Origin']).toBeUndefined();
    // Vary is set unconditionally so shared caches cannot mix responses.
    expect(set['Vary']).toBe('Origin');
    expect(next).toHaveBeenCalled();
  });

  it('never responds with a wildcard origin', async () => {
    const { set } = await runCors({ origin: 'https://mcp.example.com' });
    expect(set['Access-Control-Allow-Origin']).not.toBe('*');
  });

  it('allows extra origins from MCP_CORS_ORIGINS', async () => {
    process.env.MCP_CORS_ORIGINS = 'https://a.example, https://b.example';
    const { set } = await runCors({ origin: 'https://b.example' });
    expect(set['Access-Control-Allow-Origin']).toBe('https://b.example');
  });

  it('ignores malformed MCP_CORS_ORIGINS entries without throwing', async () => {
    process.env.MCP_CORS_ORIGINS = 'not-a-url';
    const { set } = await runCors({ origin: 'https://mcp.example.com' });
    // The issuer origin still works; the bad entry is simply dropped.
    expect(set['Access-Control-Allow-Origin']).toBe('https://mcp.example.com');
  });

  it('answers OPTIONS preflight with 204 before the auth chain runs', async () => {
    const { res, next, end } = await runCors({ origin: 'https://mcp.example.com' }, 'OPTIONS');
    expect(res.status).toHaveBeenCalledWith(204);
    expect(end).toHaveBeenCalled();
    expect(next).not.toHaveBeenCalled();
  });

  it('serves the login stylesheet unauthenticated as text/css', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    const cssCall = mockGet.mock.calls.find((c) => c[0] === '/auth/login.css');
    expect(cssCall).toBeDefined();

    const set = vi.fn().mockReturnThis();
    const send = vi.fn();
    const res = { set, status: vi.fn().mockReturnThis(), send };
    (cssCall![1] as (req: unknown, res: unknown) => void)({}, res);
    expect(set).toHaveBeenCalledWith('Content-Type', 'text/css; charset=utf-8');
    expect(send).toHaveBeenCalledWith(expect.stringContaining('.card {'));
  });
});
