import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export interface DeckOwner {
  primaryKey: string;
  uid: string;
  displayname: string;
}

export interface DeckLabel {
  id: number;
  title: string;
  color: string;
  boardId: number;
}

export interface DeckAcl {
  id: number;
  participant: DeckOwner;
  type: number;
  boardId: number;
  permissionEdit: boolean;
  permissionShare: boolean;
  permissionManage: boolean;
}

export interface DeckBoard {
  id: number;
  title: string;
  owner: DeckOwner;
  color: string;
  archived: boolean;
  labels: DeckLabel[];
  acl: DeckAcl[];
  shared: number;
  deletedAt: number;
  lastModified: number;
}

export interface DeckAssignedUser {
  id: number;
  participant: DeckOwner;
  type: number;
}

export interface DeckCard {
  id: number;
  title: string;
  description: string;
  stackId: number;
  type: string;
  order: number;
  archived: boolean;
  done: boolean | null;
  duedate: string | null;
  labels: DeckLabel[];
  assignedUsers: DeckAssignedUser[];
  owner: DeckOwner;
  createdAt: number;
  lastModified: number;
  deletedAt: number;
}

export interface DeckStack {
  id: number;
  title: string;
  boardId: number;
  order: number;
  cards: DeckCard[];
  deletedAt: number;
  lastModified: number;
}

/**
 * Make an authenticated request to the Nextcloud Deck REST API v1.0.
 *
 * Base path: /index.php/apps/deck/api/v1.0
 */
export async function fetchDeckAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/deck/api/v1.0${endpoint}`;
  if (options.queryParams) {
    const params = new URLSearchParams(options.queryParams);
    url += `?${params.toString()}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[deck] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (
    response.status === 200 &&
    response.headers.get('content-type')?.includes('application/json')
  ) {
    return (await response.json()) as T;
  }

  return undefined as T;
}
