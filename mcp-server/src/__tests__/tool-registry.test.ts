// SPDX-License-Identifier: MIT

import { readFileSync } from 'node:fs';
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

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn() },
}));

import { logger } from '../logger.js';
import { runAs } from '../nc-context.js';
import {
  getFilteredToolSets,
  _resetCache,
  TOOL_REGISTRY,
  HIGH_TOOL_COUNT,
} from '../tool-registry.js';

/** Concatenated text of every logger.warn call, for message assertions. */
function warnText(): string {
  return vi
    .mocked(logger.warn)
    .mock.calls.map((call) => call.map((arg) => String(arg)).join(' '))
    .join('\n');
}

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
    vi.mocked(logger.warn).mockClear();
    vi.mocked(logger.info).mockClear();
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

    // /ocs/v2.php/cloud/apps is admin-only, so a non-admin NEXTCLOUD_USER always lands in the
    // fallback and silently gets every tool. Regression guard for #384 / #385.
    it.each([
      ['403', 'OCS request failed: 403 Forbidden'],
      ['401', 'OCS request failed: 401 Unauthorized'],
    ])('should fall back to all tools when detection is rejected with %s', async (_label, msg) => {
      mockFetchOCS.mockRejectedValue(new Error(msg));

      const result = await getFilteredToolSets();
      const allToolCount = TOOL_REGISTRY.reduce((sum, e) => sum + e.tools.length, 0);
      expect(flatToolNames(result).length).toBe(allToolCount);
    });

    it('should warn actionably when detection fails', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS request failed: 403 Forbidden'));

      await getFilteredToolSets();

      const text = warnText();
      expect(text).toContain('MCP_TOOLS');
    });

    it('should warn when the registered tool count is high', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS request failed: 403 Forbidden'));

      await getFilteredToolSets();

      const highCountWarn = vi
        .mocked(logger.warn)
        .mock.calls.find((call) => (call[0] as { threshold?: number })?.threshold);
      expect(highCountWarn).toBeDefined();
      expect(highCountWarn?.[0]).toMatchObject({
        threshold: HIGH_TOOL_COUNT,
        source: 'all (fallback)',
      });
    });

    it('should not warn about tool count for a small MCP_TOOLS whitelist', async () => {
      process.env.MCP_TOOLS = 'files,status';

      const result = await getFilteredToolSets();

      expect(flatToolNames(result).length).toBeLessThanOrEqual(HIGH_TOOL_COUNT);
      expect(logger.warn).not.toHaveBeenCalled();
    });

    // MCP_TOOLS is the documented workaround for non-admin users: it short-circuits detection
    // entirely, so a failing (or forbidden) /cloud/apps never matters.
    it('should honour MCP_TOOLS without calling detection even when it would fail', async () => {
      process.env.MCP_TOOLS = 'calendar';
      mockFetchOCS.mockRejectedValue(new Error('OCS request failed: 403 Forbidden'));

      const names = flatToolNames(await getFilteredToolSets());

      expect(mockFetchOCS).not.toHaveBeenCalled();
      expect(names).toContain('list_calendars');
      expect(names).not.toContain('deck_list_boards');
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

  describe('per-account detection', () => {
    const asUser = (userId: string) =>
      ({
        kind: 'user',
        user: { userId, loginName: userId, appPassword: 'pw', grantId: `g-${userId}` },
      }) as const;

    /** A non-admin: /cloud/apps is forbidden, capabilities and navigation are not. */
    function nonAdmin(caps: string[], nav: string[]) {
      mockFetchOCS.mockImplementation(async (path: string) => {
        if (path.startsWith('/ocs/v2.php/cloud/apps')) {
          throw new Error('OCS request failed: 403 Forbidden');
        }
        if (path === '/ocs/v2.php/cloud/capabilities') {
          return { ocs: { data: { capabilities: Object.fromEntries(caps.map((c) => [c, {}])) } } };
        }
        return { ocs: { data: nav.map((id) => ({ id })) } };
      });
    }

    it('a non-admin gets the apps their capabilities and navigation show', async () => {
      nonAdmin(['spreed'], ['notes']);
      const names = flatToolNames(await runAs(asUser('bob'), () => getFilteredToolSets()));
      expect(names).toContain('talk_list_conversations');
      expect(names).toContain('list_notes');
      expect(names).not.toContain('deck_list_boards');
    });

    it('apps a non-admin cannot see either way stay listed', async () => {
      nonAdmin([], []);
      const names = flatToolNames(await runAs(asUser('bob'), () => getFilteredToolSets()));
      expect(names).toContain('translate_text');
    });

    it('detects once per signed-in user', async () => {
      mockFetchOCS.mockResolvedValue({ ocs: { data: { apps: ['calendar'] } } });
      await runAs(asUser('alice'), () => getFilteredToolSets());
      await runAs(asUser('alice'), () => getFilteredToolSets());
      expect(mockFetchOCS).toHaveBeenCalledTimes(1);
      await runAs(asUser('bob'), () => getFilteredToolSets());
      expect(mockFetchOCS).toHaveBeenCalledTimes(2);
    });

    it('a request without a token gets every tool and asks Nextcloud nothing', async () => {
      const result = await runAs({ kind: 'anonymous' }, () => getFilteredToolSets());
      const allToolCount = TOOL_REGISTRY.reduce((sum, e) => sum + e.tools.length, 0);
      expect(flatToolNames(result).length).toBe(allToolCount);
      expect(mockFetchOCS).not.toHaveBeenCalled();
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

    it('should have one row per category in the tool upstream matrix', () => {
      const doc = readFileSync(
        new URL('../../../docs/mcp/upstream-apis.md', import.meta.url),
        'utf8'
      );
      // | `category` | `source.ts` | `app`, `app` | ...
      const rows = new Map<string, string>();
      for (const m of doc.matchAll(/^\| `([a-z_]+)` \| `[^`]+\.ts` \| ([^|]+) \|/gm)) {
        expect(rows.has(m[1]), `duplicate row ${m[1]}`).toBe(false);
        rows.set(m[1], m[2]);
      }
      expect([...rows.keys()].sort()).toEqual(TOOL_REGISTRY.map((e) => e.category).sort());

      for (const { category, appIds } of TOOL_REGISTRY) {
        const upstream = [...rows.get(category)!.matchAll(/`([a-z_*]+)`/g)].map((m) => m[1]);
        for (const appId of appIds ?? []) {
          const listed = upstream.some((u) =>
            u.endsWith('*') ? appId.startsWith(u.slice(0, -1)) : u === appId
          );
          expect(listed, `${category}: ${appId} missing from Upstream`).toBe(true);
        }
      }
    });
  });

  describe('tool annotations', () => {
    const allTools = TOOL_REGISTRY.flatMap((e) => e.tools);

    it('should register at least one tool', () => {
      expect(allTools.length).toBeGreaterThan(0);
    });

    it('should have globally unique tool names', () => {
      const names = allTools.map((t) => t.name);
      const duplicates = names.filter((n, i) => names.indexOf(n) !== i);
      expect(duplicates).toEqual([]);
    });

    // Anthropic's connector pre-submission checklist caps tool names at 64 chars.
    it('should keep every tool name within 64 characters', () => {
      const tooLong = allTools.filter((t) => t.name.length > 64).map((t) => t.name);
      expect(tooLong).toEqual([]);
    });

    it('should give every tool a distinct, non-empty title', () => {
      const bad = allTools
        .filter((t) => !t.title || t.title.trim() === '' || t.title === t.name)
        .map((t) => t.name);
      expect(bad).toEqual([]);
    });

    it('should never mark a tool both read-only and destructive', () => {
      const bad = allTools
        .filter((t) => t.annotations.readOnlyHint && t.annotations.destructiveHint)
        .map((t) => t.name);
      expect(bad).toEqual([]);
    });

    it('should mark every read-only tool as idempotent', () => {
      const bad = allTools
        .filter((t) => t.annotations.readOnlyHint && !t.annotations.idempotentHint)
        .map((t) => t.name);
      expect(bad).toEqual([]);
    });

    it('should mark obvious readers read-only and obvious deleters destructive', () => {
      const byName = new Map(allTools.map((t) => [t.name, t]));
      for (const name of ['list_files', 'get_file_info', 'system_status', 'read_file']) {
        expect(byName.get(name)?.annotations.readOnlyHint, name).toBe(true);
      }
      for (const name of ['delete', 'run_occ', 'write_file', 'empty_trash']) {
        expect(byName.get(name)?.annotations.readOnlyHint, name).toBe(false);
        expect(byName.get(name)?.annotations.destructiveHint, name).toBe(true);
      }
    });
  });
});
