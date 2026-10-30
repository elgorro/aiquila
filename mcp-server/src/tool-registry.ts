// SPDX-License-Identifier: MIT

import { fetchOCS } from './client/ocs.js';
import { logger } from './logger.js';
import { currentIdentity } from './nc-context.js';
import type { Tool } from './tools/types.js';

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
import { newsTools } from './tools/apps/news.js';
import { aiquilaTools } from './tools/apps/aiquila.js';
import { coworkTools } from './tools/apps/cowork.js';
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
import { activityTools } from './tools/apps/activity.js';
import { announcementTools } from './tools/apps/announcements.js';
import { registrationTools } from './tools/apps/registration.js';
import { termsOfServiceTools } from './tools/apps/terms-of-service.js';
import { trashTools } from './tools/apps/trash.js';
import { versionsTools } from './tools/apps/versions.js';
import { projectsTools } from './tools/apps/projects.js';
import { pollsTools } from './tools/apps/polls.js';
import { formsTools } from './tools/apps/forms.js';
import { textTools } from './tools/apps/text.js';
import { recommendationsTools } from './tools/apps/recommendations.js';
import { socialSharingTools } from './tools/apps/social-sharing.js';
import { socialTools } from './tools/apps/social.js';
import { passmanTools } from './tools/apps/passman.js';

type ToolArray = Tool[];

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
  { category: 'cowork', appIds: ['aiquila'], tools: coworkTools },

  // Requires specific Nextcloud apps
  { category: 'calendar', appIds: ['calendar'], tools: calendarTools },
  { category: 'tasks', appIds: ['tasks'], tools: tasksTools },
  { category: 'contacts', appIds: ['contacts'], tools: contactsTools },
  { category: 'notes', appIds: ['notes'], tools: notesTools },
  { category: 'news', appIds: ['news'], tools: newsTools },
  { category: 'mail', appIds: ['mail'], tools: mailTools },
  { category: 'deck', appIds: ['deck'], tools: deckTools },
  { category: 'passman', appIds: ['passman'], tools: passmanTools },
  { category: 'cookbook', appIds: ['cookbook'], tools: cookbookTools },
  { category: 'maps', appIds: ['maps'], tools: mapsTools },
  { category: 'photos', appIds: ['photos'], tools: photosTools },
  { category: 'talk', appIds: ['spreed'], tools: talkTools },
  { category: 'circles', appIds: ['circles'], tools: circlesTools },
  { category: 'bookmarks', appIds: ['bookmarks'], tools: bookmarksTools },
  { category: 'polls', appIds: ['polls'], tools: pollsTools },
  { category: 'forms', appIds: ['forms'], tools: formsTools },
  { category: 'text', appIds: ['text'], tools: textTools },
  { category: 'assistant', appIds: ['assistant'], tools: assistantTools },
  { category: 'translate', appIds: ['text_translate', 'translate'], tools: translateTools },
  { category: 'user_status', appIds: ['user_status'], tools: userStatusTools },
  { category: 'notifications', appIds: ['notifications'], tools: notificationsTools },
  { category: 'activity', appIds: ['activity'], tools: activityTools },
  { category: 'announcements', appIds: ['announcementcenter'], tools: announcementTools },
  { category: 'registration', appIds: ['registration'], tools: registrationTools },
  {
    category: 'terms_of_service',
    appIds: ['terms_of_service'],
    tools: termsOfServiceTools,
  },
  { category: 'recommendations', appIds: ['recommendations'], tools: recommendationsTools },
  { category: 'social', appIds: ['social'], tools: socialTools },
  {
    category: 'social_sharing',
    appIds: [
      'socialsharing_email',
      'socialsharing_twitter',
      'socialsharing_facebook',
      'socialsharing_telegram',
      'socialsharing_whatsapp',
      'socialsharing_bluesky',
      'socialsharing_diaspora',
    ],
    tools: socialSharingTools,
  },
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
 * Apps that show a regular user neither a capability nor a navigation entry,
 * so their presence cannot be detected without admin rights. For non-admins
 * their tools stay listed, and a call reports the app missing if it is.
 */
const UNDETECTABLE_FOR_USERS: ReadonlySet<string> = new Set([
  'text',
  'translate',
  'text_translate',
  'registration',
  'terms_of_service',
  'assistant',
  'socialsharing_email',
  'socialsharing_twitter',
  'socialsharing_facebook',
  'socialsharing_telegram',
  'socialsharing_whatsapp',
  'socialsharing_bluesky',
  'socialsharing_diaspora',
]);

/**
 * The apps enabled for the account of the request being served. The app list
 * (/cloud/apps) needs admin rights; for anyone else, the apps that publish a
 * capability or a navigation entry to them, plus UNDETECTABLE_FOR_USERS.
 * Returns null on failure (triggers fallback to all tools).
 */
async function detectEnabledApps(): Promise<Set<string> | null> {
  try {
    const result = await fetchOCS<{ apps: string[] }>('/ocs/v2.php/cloud/apps', {
      queryParams: { filter: 'enabled' },
    });
    const apps = new Set(result.ocs.data.apps);
    logger.info({ count: apps.size }, '[tools] Detected enabled Nextcloud apps');
    return apps;
  } catch {
    // Not an admin: fall through to what the account itself can see.
  }
  try {
    const [caps, nav] = await Promise.all([
      fetchOCS<{ capabilities: Record<string, unknown> }>('/ocs/v2.php/cloud/capabilities'),
      fetchOCS<{ id: string }[]>('/ocs/v2.php/core/navigation/apps'),
    ]);
    const apps = new Set([
      ...Object.keys(caps.ocs.data.capabilities ?? {}),
      ...nav.ocs.data.map((a) => a.id),
      ...UNDETECTABLE_FOR_USERS,
    ]);
    logger.info({ count: apps.size }, '[tools] Detected apps visible to the account');
    return apps;
  } catch (err) {
    logger.warn(
      { err: err instanceof Error ? err.message : String(err) },
      '[tools] Could not detect enabled apps — registering ALL tools as a fallback. ' +
        'Set MCP_TOOLS to an explicit category/tool whitelist to register only the tools you need.'
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

// Cached results, keyed by the account they were detected for. The
// environment account's never expires; a signed-in user's is refreshed after
// USER_CACHE_TTL_MS, so an app enabled later shows up without a restart.
const USER_CACHE_TTL_MS = 10 * 60 * 1000;
const MAX_CACHED_USERS = 500;
const cachedToolSets = new Map<string, { toolSets: ToolArray[]; expiresAt: number }>();

/**
 * Tool count above which we warn. Not a protocol limit — no MCP client documents one —
 * but very large tool lists are worth flagging, and reaching this number usually means
 * app-detection fell back to registering everything.
 */
export const HIGH_TOOL_COUNT = 100;

function countTools(toolSets: ToolArray[]): number {
  return toolSets.reduce((sum, ts) => sum + ts.length, 0);
}

/**
 * Returns filtered tool arrays based on MCP_TOOLS env var or auto-detection.
 *
 * Detection runs as the account of the request being served: under OAuth,
 * per signed-in user (cached per user); a request without a token gets every
 * tool, since nothing can be asked of Nextcloud on its behalf.
 */
export async function getFilteredToolSets(): Promise<ToolArray[]> {
  const mcpToolsFilter = parseMcpTools();
  const identity = currentIdentity();
  const cacheKey = mcpToolsFilter
    ? 'mcp_tools'
    : identity?.kind === 'user'
      ? `user:${identity.user.userId}`
      : identity?.kind === 'anonymous'
        ? 'anonymous'
        : 'env';
  const cached = cachedToolSets.get(cacheKey);
  if (cached && cached.expiresAt > Date.now()) return cached.toolSets;

  let toolSets: ToolArray[];
  let source: string;
  if (mcpToolsFilter) {
    toolSets = filterByExplicitList(mcpToolsFilter);
    source = 'MCP_TOOLS';
  } else if (identity?.kind === 'anonymous') {
    toolSets = filterByEnabledApps(null);
    source = 'all (not signed in)';
  } else {
    const enabledApps = await detectEnabledApps();
    toolSets = filterByEnabledApps(enabledApps);
    source = enabledApps ? 'app-detection' : 'all (fallback)';
  }

  const isUser = cacheKey.startsWith('user:');
  if (isUser && cachedToolSets.size >= MAX_CACHED_USERS) {
    // Maps iterate in insertion order: drop the oldest user's entry.
    const oldest = [...cachedToolSets.keys()].find((k) => k.startsWith('user:'));
    if (oldest) cachedToolSets.delete(oldest);
  }
  cachedToolSets.set(cacheKey, {
    toolSets,
    expiresAt: isUser ? Date.now() + USER_CACHE_TTL_MS : Number.POSITIVE_INFINITY,
  });

  const toolCount = countTools(toolSets);
  logger.info({ tools: toolCount, source }, '[tools] Tool filtering applied');

  if (toolCount > HIGH_TOOL_COUNT && source !== 'all (not signed in)') {
    logger.warn(
      { tools: toolCount, threshold: HIGH_TOOL_COUNT, source },
      `[tools] Registering ${toolCount} tools. Set MCP_TOOLS to a category/tool whitelist to ` +
        'narrow this down — some clients degrade on very large tool lists.'
    );
  }

  return toolSets;
}

/** Reset cached state — for testing only. */
export function _resetCache(): void {
  cachedToolSets.clear();
}
