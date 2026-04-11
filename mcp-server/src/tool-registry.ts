// SPDX-License-Identifier: MIT

import { fetchOCS } from './client/ocs.js';
import { logger } from './logger.js';

// System tools (always available)
import { fileSystemTools } from './tools/system/files.js';
import { statusTools } from './tools/system/status.js';
import { appsTools } from './tools/system/apps.js';
import { securityTools } from './tools/system/security.js';
import { occTools } from './tools/system/occ.js';
import { tagsTools } from './tools/system/tags.js';
import { searchTools } from './tools/system/search.js';

// App-specific tools
import { tasksTools } from './tools/apps/tasks.js';
import { calendarTools } from './tools/apps/calendar.js';
import { cookbookTools } from './tools/apps/cookbook.js';
import { deckTools } from './tools/apps/deck.js';
import { notesTools } from './tools/apps/notes.js';
import { aiquilaTools } from './tools/apps/aiquila.js';
import { usersTools } from './tools/apps/users.js';
import { groupsTools } from './tools/apps/groups.js';
import { circlesTools } from './tools/apps/circles.js';
import { photosTools } from './tools/apps/photos.js';
import { sharesTools } from './tools/apps/shares.js';
import { contactsTools } from './tools/apps/contacts.js';
import { mailTools } from './tools/apps/mail.js';
import { bookmarksTools } from './tools/apps/bookmarks.js';
import { mapsTools } from './tools/apps/maps.js';
import { assistantTools } from './tools/apps/assistant.js';
import { translateTools } from './tools/apps/translate.js';
import { talkTools } from './tools/apps/talk.js';
import { userStatusTools } from './tools/apps/user-status.js';
import { absenceTools } from './tools/apps/absence.js';
import { notificationsTools } from './tools/apps/notifications.js';
import { trashTools } from './tools/apps/trash.js';
import { versionsTools } from './tools/apps/versions.js';
import { projectsTools } from './tools/apps/projects.js';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type ToolArray = Array<{
  name: string;
  description: string;
  inputSchema: any;
  handler: (...args: any[]) => any;
}>;

interface ToolSetEntry {
  /** Category name, usable in MCP_TOOLS */
  category: string;
  /** Nextcloud app IDs that must be enabled, or null for always-available */
  appIds: string[] | null;
  /** The tool array */
  tools: ToolArray;
}

/**
 * Single source of truth for tool-to-Nextcloud-app mapping.
 *
 * Entries with `appIds: null` are core tools — always registered.
 * Entries with `appIds` are only registered when at least one of
 * the listed Nextcloud apps is enabled (or when MCP_TOOLS includes them).
 */
export const TOOL_REGISTRY: ToolSetEntry[] = [
  // Core — always available
  { category: 'files', appIds: null, tools: fileSystemTools },
  { category: 'status', appIds: null, tools: statusTools },
  { category: 'apps', appIds: null, tools: appsTools },
  { category: 'tags', appIds: null, tools: tagsTools },
  { category: 'search', appIds: null, tools: searchTools },
  { category: 'users', appIds: null, tools: usersTools },
  { category: 'groups', appIds: null, tools: groupsTools },
  { category: 'shares', appIds: null, tools: sharesTools },
  { category: 'absence', appIds: null, tools: absenceTools },
  { category: 'trash', appIds: null, tools: trashTools },
  { category: 'versions', appIds: null, tools: versionsTools },

  // Requires AIquila Nextcloud app
  { category: 'aiquila', appIds: ['aiquila'], tools: aiquilaTools },
  { category: 'security', appIds: ['aiquila'], tools: securityTools },
  { category: 'occ', appIds: ['aiquila'], tools: occTools },
  { category: 'projects', appIds: ['aiquila'], tools: projectsTools },

  // Requires specific Nextcloud apps
  { category: 'calendar', appIds: ['calendar'], tools: calendarTools },
  { category: 'tasks', appIds: ['tasks'], tools: tasksTools },
  { category: 'contacts', appIds: ['contacts'], tools: contactsTools },
  { category: 'notes', appIds: ['notes'], tools: notesTools },
  { category: 'mail', appIds: ['mail'], tools: mailTools },
  { category: 'deck', appIds: ['deck'], tools: deckTools },
  { category: 'cookbook', appIds: ['cookbook'], tools: cookbookTools },
  { category: 'maps', appIds: ['maps'], tools: mapsTools },
  { category: 'photos', appIds: ['photos'], tools: photosTools },
  { category: 'talk', appIds: ['spreed'], tools: talkTools },
  { category: 'circles', appIds: ['circles'], tools: circlesTools },
  { category: 'bookmarks', appIds: ['bookmarks'], tools: bookmarksTools },
  { category: 'assistant', appIds: ['assistant'], tools: assistantTools },
  { category: 'translate', appIds: ['text_translate', 'translate'], tools: translateTools },
  { category: 'user_status', appIds: ['user_status'], tools: userStatusTools },
  { category: 'notifications', appIds: ['notifications'], tools: notificationsTools },
];

const ALL_CATEGORIES = new Set(TOOL_REGISTRY.map((e) => e.category));

/**
 * Parse MCP_TOOLS env var.
 * Comma-separated list of category names and/or individual tool names.
 * Returns null when unset (= use app auto-detection).
 */
function parseMcpTools(): { categories: Set<string>; toolNames: Set<string> } | null {
  const raw = process.env.MCP_TOOLS;
  if (!raw) return null;

  const categories = new Set<string>();
  const toolNames = new Set<string>();

  for (const token of raw.split(',')) {
    const trimmed = token.trim();
    if (!trimmed) continue;
    if (ALL_CATEGORIES.has(trimmed)) {
      categories.add(trimmed);
    } else {
      toolNames.add(trimmed);
    }
  }

  return { categories, toolNames };
}

/**
 * Query Nextcloud for enabled apps.
 * Returns null on failure (triggers fallback to all tools).
 */
async function detectEnabledApps(): Promise<Set<string> | null> {
  try {
    const result = await fetchOCS<{ apps: string[] }>('/ocs/v2.php/cloud/apps', {
      queryParams: { filter: 'enabled' },
    });
    const apps = new Set(result.ocs.data.apps);
    logger.info({ count: apps.size }, '[startup] Detected enabled Nextcloud apps');
    return apps;
  } catch (err) {
    logger.warn(
      { err: err instanceof Error ? err.message : String(err) },
      '[startup] Could not detect enabled apps — registering all tools'
    );
    return null;
  }
}

function filterByExplicitList(filter: {
  categories: Set<string>;
  toolNames: Set<string>;
}): ToolArray[] {
  const result: ToolArray[] = [];

  for (const entry of TOOL_REGISTRY) {
    if (filter.categories.has(entry.category)) {
      result.push(entry.tools);
      continue;
    }

    // Check for individual tool names in this set
    if (filter.toolNames.size > 0) {
      const matched = entry.tools.filter((t) => filter.toolNames.has(t.name));
      if (matched.length > 0) {
        result.push(matched);
      }
    }
  }

  return result;
}

function filterByEnabledApps(enabledApps: Set<string> | null): ToolArray[] {
  // Detection failed — register everything
  if (enabledApps === null) {
    return TOOL_REGISTRY.map((e) => e.tools);
  }

  return TOOL_REGISTRY.filter(
    (entry) => entry.appIds === null || entry.appIds.some((id) => enabledApps.has(id))
  ).map((e) => e.tools);
}

// Cached result — cleared only on process restart
let cachedToolSets: ToolArray[] | null = null;

/**
 * Returns filtered tool arrays based on MCP_TOOLS env var or auto-detection.
 * Result is cached after first call.
 */
export async function getFilteredToolSets(): Promise<ToolArray[]> {
  if (cachedToolSets) return cachedToolSets;

  const mcpToolsFilter = parseMcpTools();

  if (mcpToolsFilter) {
    cachedToolSets = filterByExplicitList(mcpToolsFilter);
    const toolCount = cachedToolSets.reduce((sum, ts) => sum + ts.length, 0);
    logger.info({ tools: toolCount, source: 'MCP_TOOLS' }, '[startup] Tool filtering applied');
  } else {
    const enabledApps = await detectEnabledApps();
    cachedToolSets = filterByEnabledApps(enabledApps);
    const toolCount = cachedToolSets.reduce((sum, ts) => sum + ts.length, 0);
    logger.info(
      { tools: toolCount, source: enabledApps ? 'app-detection' : 'all (fallback)' },
      '[startup] Tool filtering applied'
    );
  }

  return cachedToolSets;
}

/** Reset cached state — for testing only. */
export function _resetCache(): void {
  cachedToolSets = null;
}
