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
const mockExpressApp = { all: mockAll, listen: mockListen };
vi.mock('@modelcontextprotocol/sdk/server/express.js', () => ({
  createMcpExpressApp: vi.fn(() => mockExpressApp),
}));

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

describe('http transport', () => {
  const savedEnv = process.env;

  beforeEach(() => {
    vi.clearAllMocks();
    process.env = { ...savedEnv };
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
});
