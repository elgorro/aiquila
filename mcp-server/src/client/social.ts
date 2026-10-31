// SPDX-License-Identifier: MIT

import { basicAuth, getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

/**
 * Nextcloud Social — Mastodon client API.
 *
 * Pinned against Social v0.24.1, the latest release, and checked against
 * AlohaSocial/social `main` (0.26.280). The app is pre-1.0 and its API is still
 * moving; re-check the routes before bumping the pinned version.
 *
 * Every status and notification id is a stream nid — the published second
 * times 10^9 plus a random suffix — so it is far past
 * `Number.MAX_SAFE_INTEGER`. Social sends them as decimal strings, and they
 * have to stay strings on the way back in: a JSON number would round to a
 * different post.
 *
 * Social registers every API route as a `FrontpageRoute`, so the Mastodon
 * paths are served under `/index.php/apps/social/` rather than at the domain
 * root; third-party Mastodon clients reach them through root `/api` and
 * `/oauth` proxy rules. These tools use the app path directly, and
 * SOCIAL_API_BASE below is the single place that has to change if it moves.
 *
 * Authentication is Basic auth with a Nextcloud app password, not an OAuth
 * bearer token. Social resolves the caller from the bearer token *or* from the
 * Nextcloud session when the request passes the CSRF check, and Nextcloud
 * treats an `OCS-APIRequest` header as passing it — so the same headers every
 * other AIquila client sends are enough. Social's own OAuth only supports the
 * authorization-code grant, which needs a human at a consent page.
 */
const SOCIAL_API_BASE = '/index.php/apps/social';

/** Mastodon Account entity, as Social exports it (`Person::exportAsLocal()`). */
export interface SocialAccount {
  id: string;
  username: string;
  /** `user` locally, `user@host` for a remote actor. */
  acct: string;
  display_name: string;
  locked: boolean;
  bot: boolean;
  created_at: string;
  note: string;
  url: string;
  avatar: string;
  followers_count: number;
  following_count: number;
  statuses_count: number;
  last_status_at: string | null;
}

/** Mastodon MediaAttachment entity. */
export interface SocialMediaAttachment {
  id: string;
  type: string;
  url: string;
  preview_url: string | null;
  remote_url: string | null;
  description: string | null;
}

export interface SocialPollOption {
  title: string;
  votes_count: number | null;
}

/** Mastodon Poll entity, present on a status only when it carries one. */
export interface SocialPoll {
  id: string;
  expires_at: string | null;
  expired: boolean;
  multiple: boolean;
  votes_count: number;
  voters_count: number | null;
  options: SocialPollOption[];
  voted: boolean;
}

/** Mastodon Status entity, as Social exports it (`Stream::exportAsLocal()`). */
export interface SocialStatus {
  /** The nid as a decimal string; the one every `{nid}` route takes. */
  id: string;
  /** The same nid, also a decimal string (`0` for a status that has none). */
  nid: string | 0;
  uri: string;
  url: string;
  created_at: string;
  edited_at: string | null;
  /** HTML. */
  content: string;
  spoiler_text: string;
  sensitive: boolean;
  visibility: string;
  language: string | null;
  in_reply_to_id: string | null;
  replies_count: number;
  reblogs_count: number;
  favourites_count: number;
  favourited: boolean;
  reblogged: boolean;
  bookmarked: boolean;
  pinned: boolean;
  account?: SocialAccount;
  media_attachments: SocialMediaAttachment[];
  poll: SocialPoll | null;
  reblog: SocialStatus | null;
}

/** `GET /api/v1/statuses/{nid}/context` */
export interface SocialStatusContext {
  ancestors: SocialStatus[];
  descendants: SocialStatus[];
}

/** One entry of `GET /api/v1/statuses/{nid}/history` (Mastodon StatusEdit). */
export interface SocialStatusEdit {
  created_at: string;
  content: string;
  spoiler_text?: string;
  sensitive?: boolean;
}

/** One group of `GET /api/v2/notifications`. */
export interface SocialNotificationGroup {
  group_key: string;
  notifications_count: number;
  type: string;
  most_recent_notification_id: string;
  latest_page_notification_at: string;
  sample_account_ids: string[];
  status_id?: string;
}

/** The whole v2 notifications page: groups plus the entities they refer to. */
export interface SocialNotificationsPage {
  notification_groups: SocialNotificationGroup[];
  accounts: SocialAccount[];
  statuses: SocialStatus[];
}

/** `GET /api/v2/search` */
export interface SocialSearchResults {
  accounts: SocialAccount[];
  statuses: SocialStatus[];
  hashtags: { name: string; url: string }[];
}

/** One entry of `GET /api/v1/lists`. */
export interface SocialList {
  id: string | number;
  title: string;
}

/**
 * Make an authenticated request to the Nextcloud Social Mastodon API.
 *
 * Base path: /index.php/apps/social
 *
 * Returns plain JSON (no OCS envelope), like the News and Notes APIs.
 */
export async function fetchSocialAPI<T = unknown>(
  endpoint: string,
  options: SocialRequestOptions = {}
): Promise<T> {
  return (await request<T>(endpoint, options)).data;
}

/** One page of a paged list, with the cursor for the next (older) page. */
export interface SocialPage<T> {
  items: T[];
  /** The `max_id` that reads the next page, or undefined on the last one. */
  next?: string;
}

/**
 * Read one page of a paged list. Social pages with a `Link` header, as
 * Mastodon does, and its cursor is not always a status id — favourites and
 * bookmarks are cut on the id of the mark, follower lists on the follow — so
 * the next page has to be read from the header rather than from the items.
 */
export async function fetchSocialAPIPage<T = unknown>(
  endpoint: string,
  options: SocialRequestOptions = {}
): Promise<SocialPage<T>> {
  const { data, headers } = await request<T[]>(endpoint, options);
  return { items: data ?? [], next: nextMaxId(headers.get('link')) };
}

/** The `max_id` of the `rel="next"` entry of a Link header (RFC 8288). */
export function nextMaxId(link: string | null): string | undefined {
  if (!link) return undefined;
  for (const part of link.split(',')) {
    const match = part.match(/<([^>]*)>\s*;(.*)/);
    if (!match || !/\brel="?next"?/.test(match[2])) continue;
    try {
      return new URL(match[1], 'http://localhost').searchParams.get('max_id') ?? undefined;
    } catch {
      return undefined;
    }
  }
  return undefined;
}

interface SocialRequestOptions {
  method?: string;
  body?: unknown;
  queryParams?: Record<string, string | number | boolean | undefined>;
}

async function request<T>(
  endpoint: string,
  options: SocialRequestOptions
): Promise<{ data: T; headers: Headers }> {
  const config = getNextcloudConfig();
  const auth = basicAuth(config);

  let url = `${config.url}${SOCIAL_API_BASE}${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(options.queryParams)) {
      if (value !== undefined) params.append(key, String(value));
    }
    const qs = params.toString();
    if (qs) url += `?${qs}`;
  }

  const headers: Record<string, string> = {
    Authorization: `Basic ${auth}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };

  let body: string | undefined;
  if (options.body !== undefined) {
    body = JSON.stringify(options.body);
    headers['Content-Type'] = 'application/json';
  }

  const method = options.method ?? 'GET';
  const t0 = Date.now();
  const response = await fetch(url, { method, headers, body });
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[social] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (response.headers.get('content-type')?.includes('application/json')) {
    return { data: (await response.json()) as T, headers: response.headers };
  }

  return { data: undefined as T, headers: response.headers };
}
