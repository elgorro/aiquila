import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock createServer to avoid pulling in all tool dependencies
const mockConnect = vi.fn();
const mockServer = { connect: mockConnect };

vi.mock('../server.js', () => ({
  createServer: vi.fn(() => mockServer),
}));

// Mock the MCP SDK transports (must be constructable — no arrow functions)
const mockStdioTransport = {};
vi.mock('@modelcontextprotocol/sdk/server/stdio.js', () => {
  return {
    StdioServerTransport: vi.fn(function () {
      return mockStdioTransport;
    }),
  };
});

const mockHttpTransport = { handleRequest: vi.fn() };
vi.mock('@modelcontextprotocol/sdk/server/streamableHttp.js', () => {
  return {
    StreamableHTTPServerTransport: vi.fn(function () {
      return mockHttpTransport;
    }),
  };
});

const mockListen = vi.fn((_port: number, _host: string, cb: () => void) => cb());
const mockAll = vi.fn();
const mockUse = vi.fn();
const mockPost = vi.fn();
const mockExpressApp = { all: mockAll, listen: mockListen, use: mockUse, post: mockPost };
vi.mock('@modelcontextprotocol/sdk/server/express.js', () => ({
  createMcpExpressApp: vi.fn(() => mockExpressApp),
}));

// Mock express (needed when auth is enabled)
const mockUrlencoded = vi.fn(() => 'urlencoded-middleware');
vi.mock('express', () => ({
  default: { urlencoded: mockUrlencoded },
}));

// Mock auth SDK modules
const mockMcpAuthMiddleware = vi.fn();
vi.mock('@modelcontextprotocol/sdk/server/auth/router.js', () => ({
  mcpAuthRouter: vi.fn(() => mockMcpAuthMiddleware),
}));

const mockBearerMiddleware = vi.fn();
vi.mock('@modelcontextprotocol/sdk/server/auth/middleware/bearerAuth.js', () => ({
  requireBearerAuth: vi.fn(() => mockBearerMiddleware),
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

  it('should create server and connect with StdioServerTransport', async () => {
    const { startStdio } = await import('../transports/stdio.js');
    await startStdio();

    expect(mockConnect).toHaveBeenCalledWith(mockStdioTransport);
  });
});

// ---- http transport (auth disabled) ----

describe('http transport', () => {
  const savedEnv = process.env;

  beforeEach(() => {
    vi.clearAllMocks();
    process.env = { ...savedEnv };
    delete process.env.MCP_AUTH_ENABLED;
  });

  afterEach(() => {
    process.env = savedEnv;
  });

  it('should create server and connect with StreamableHTTPServerTransport', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockConnect).toHaveBeenCalledWith(mockHttpTransport);
  });

  it('should mount handler on /mcp', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();

    expect(mockAll).toHaveBeenCalledWith('/mcp', expect.any(Function));
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
});

// ---- http transport (auth enabled) ----

describe('http transport with auth enabled', () => {
  const savedEnv = process.env;

  beforeEach(() => {
    vi.clearAllMocks();
    process.env = {
      ...savedEnv,
      MCP_AUTH_ENABLED: 'true',
      MCP_AUTH_SECRET: 'test-secret-min-32-chars-required!!',
      MCP_AUTH_ISSUER: 'https://mcp.example.com',
    };
  });

  afterEach(() => {
    process.env = savedEnv;
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
    expect(mockPost).toHaveBeenCalledWith('/auth/login', mockLoginHandlerFn);
  });

  it('mounts /mcp with bearer middleware and handler', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockAll).toHaveBeenCalledWith('/mcp', mockBearerMiddleware, expect.any(Function));
  });

  it('still connects the MCP server to the transport', async () => {
    const { startHttp } = await import('../transports/http.js');
    await startHttp();
    expect(mockConnect).toHaveBeenCalledWith(mockHttpTransport);
  });
});
