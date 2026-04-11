// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Mock webdav (required by file tools)
vi.mock('webdav', () => ({
  createClient: vi.fn(() => ({})),
}));

// Mock fetch for CalDAV/OCS
global.fetch = vi.fn();

const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
  fetchStatus: vi.fn(),
}));

vi.mock('../client/mail.js', () => ({
  fetchMailAPI: vi.fn(),
}));

vi.mock('../client/bookmarks.js', () => ({
  fetchBookmarksAPI: vi.fn(),
}));

import { getFilteredToolSets, _resetCache, TOOL_REGISTRY } from '../tool-registry.js';

function flatToolNames(toolSets: Array<Array<{ name: string }>>): string[] {
  return toolSets.flatMap((ts) => ts.map((t) => t.name));
}

// Core categories that are always registered
const CORE_CATEGORIES = TOOL_REGISTRY.filter((e) => e.appIds === null).map((e) => e.category);

describe('tool-registry', () => {
  beforeEach(() => {
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
    delete process.env.MCP_TOOLS;
    _resetCache();
    mockFetchOCS.mockReset();
  });

  afterEach(() => {
    delete process.env.MCP_TOOLS;
  });

  describe('MCP_TOOLS whitelist', () => {
    it('should register only calendar + core tools when MCP_TOOLS=calendar', async () => {
      process.env.MCP_TOOLS = 'calendar';
      const result = await getFilteredToolSets();
      const names = flatToolNames(result);

      expect(names).toContain('list_calendars');
      expect(names).toContain('create_event');
      expect(names).not.toContain('list_notes');
      expect(names).not.toContain('list_tasks');
      // MCP_TOOLS only includes what's listed — no implicit core
      expect(result.length).toBe(1);
    });

    it('should register individual tool names', async () => {
      process.env.MCP_TOOLS = 'list_notes,system_status';
      const result = await getFilteredToolSets();
      const names = flatToolNames(result);

      expect(names).toContain('list_notes');
      expect(names).toContain('system_status');
      expect(names).not.toContain('delete_note');
      expect(names).not.toContain('list_calendars');
      expect(names.length).toBe(2);
    });

    it('should support mixed categories and individual tool names', async () => {
      process.env.MCP_TOOLS = 'calendar,list_notes';
      const result = await getFilteredToolSets();
      const names = flatToolNames(result);

      // All 6 calendar tools
      expect(names).toContain('list_calendars');
      expect(names).toContain('delete_event');
      // Only the one named note tool
      expect(names).toContain('list_notes');
      expect(names).not.toContain('delete_note');
    });

    it('should handle empty MCP_TOOLS as unset', async () => {
      process.env.MCP_TOOLS = '';
      // Empty string → parseMcpTools returns null → falls through to auto-detection
      // Auto-detection will fail (mock not set up) → fallback to all tools
      const result = await getFilteredToolSets();
      const names = flatToolNames(result);
      const allToolCount = TOOL_REGISTRY.reduce((sum, e) => sum + e.tools.length, 0);
      expect(names.length).toBe(allToolCount);
    });

    it('should not call fetchOCS when MCP_TOOLS is set', async () => {
      process.env.MCP_TOOLS = 'calendar';
      await getFilteredToolSets();
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });
  });

  describe('auto-detection', () => {
    it('should register core + detected app tools', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { data: { apps: ['calendar', 'notes'] } },
      });

      const result = await getFilteredToolSets();
      const names = flatToolNames(result);

      // Core tools should be present
      expect(names).toContain('list_files');
      expect(names).toContain('system_status');
      // Detected app tools
      expect(names).toContain('list_calendars');
      expect(names).toContain('list_notes');
      // Non-detected app tools should be absent
      expect(names).not.toContain('deck_list_boards');
      expect(names).not.toContain('list_mail_accounts');
    });

    it('should register all tools when detection fails', async () => {
      mockFetchOCS.mockRejectedValue(new Error('Connection refused'));

      const result = await getFilteredToolSets();
      const names = flatToolNames(result);
      const allToolCount = TOOL_REGISTRY.reduce((sum, e) => sum + e.tools.length, 0);
      expect(names.length).toBe(allToolCount);
    });

    it('should detect spreed app for talk tools', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { data: { apps: ['spreed'] } },
      });

      const result = await getFilteredToolSets();
      const names = flatToolNames(result);
      expect(names).toContain('talk_list_conversations');
    });

    it('should detect text_translate for translate tools', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { data: { apps: ['text_translate'] } },
      });

      const result = await getFilteredToolSets();
      const names = flatToolNames(result);
      expect(names).toContain('translate_text');
    });
  });

  describe('caching', () => {
    it('should cache results and not re-fetch', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { data: { apps: ['calendar'] } },
      });

      const first = await getFilteredToolSets();
      const second = await getFilteredToolSets();

      expect(first).toBe(second);
      expect(mockFetchOCS).toHaveBeenCalledTimes(1);
    });

    it('should re-fetch after cache reset', async () => {
      mockFetchOCS.mockResolvedValue({
        ocs: { data: { apps: ['calendar'] } },
      });

      await getFilteredToolSets();
      _resetCache();
      await getFilteredToolSets();

      expect(mockFetchOCS).toHaveBeenCalledTimes(2);
    });
  });

  describe('registry structure', () => {
    it('should have unique category names', () => {
      const categories = TOOL_REGISTRY.map((e) => e.category);
      expect(new Set(categories).size).toBe(categories.length);
    });

    it('should have core categories with null appIds', () => {
      for (const cat of CORE_CATEGORIES) {
        const entry = TOOL_REGISTRY.find((e) => e.category === cat);
        expect(entry?.appIds).toBeNull();
      }
    });
  });
});
