// SPDX-License-Identifier: MIT

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

import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createServer } from '../server.js';
import { _resetCache, getFilteredToolSets } from '../tool-registry.js';

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
    const tools = (server as any)._registeredTools as Record<string, unknown>;
    const names = Object.keys(tools);
    expect(names.length).toBeGreaterThan(0);
    for (const name of names) {
      expect(name).toBeTruthy();
      expect(typeof name).toBe('string');
    }
  });

  it('should register exactly the tools from getFilteredToolSets', async () => {
    const server = await createServer();
    const registeredCount = Object.keys((server as any)._registeredTools).length;
    _resetCache();
    const toolSets = await getFilteredToolSets();
    const expectedCount = toolSets.reduce((sum, ts) => sum + ts.length, 0);
    expect(registeredCount).toBe(expectedCount);
  });

  it('should connect to a transport', async () => {
    const server = await createServer();
    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

    await server.connect(serverTransport);
    await clientTransport.close();
    await server.close();
  });
});
