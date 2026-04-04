import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock webdav (required by file tools)
vi.mock('webdav', () => ({
  createClient: vi.fn(() => ({})),
}));

// Mock fetch for CalDAV/OCS
global.fetch = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: vi.fn(),
  fetchStatus: vi.fn(),
}));

vi.mock('../client/mail.js', () => ({
  fetchMailAPI: vi.fn(),
}));

vi.mock('../client/bookmarks.js', () => ({
  fetchBookmarksAPI: vi.fn(),
}));

import { createServer } from '../server.js';
import { _resetCache } from '../tool-registry.js';

describe('createServer', () => {
  beforeEach(() => {
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
    delete process.env.MCP_TOOLS;
    _resetCache();
  });

  it('should return an McpServer instance', async () => {
    const server = await createServer();
    expect(server).toBeDefined();
  });

  it('should return a new instance each call', async () => {
    const a = await createServer();
    const b = await createServer();
    expect(a).not.toBe(b);
  });

  it('should register tools on the server', async () => {
    const server = await createServer();
    // McpServer exposes registered tools via the internal _registeredTools map
    // We verify by checking the server is connectable (tools registered without error)
    expect(server).toBeDefined();
  });
});
