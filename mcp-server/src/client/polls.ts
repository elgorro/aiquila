// SPDX-License-Identifier: MIT

import { basicAuth, getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

/** User in the Polls OpenAPI spec (owner, voter, commenter, share recipient). */
export interface PollUser {
  id?: string;
  userId: string;
  displayName: string;
  emailAddress?: string;
  type?: string;
}

/** Kept for callers that still import the old name. */
export type PollOwner = PollUser;

export interface PollConfiguration {
  title: string;
  description?: string;
  access?: string;
  allowComment?: boolean;
  allowMaybe?: boolean;
  /** 'disallow' | 'allow' | 'review' */
  allowProposals?: string;
  anonymous?: boolean;
  autoReminder?: boolean;
  expire?: number;
  hideBookedUp?: boolean;
  proposalsExpire?: number;
  showResults?: string;
  useNo?: boolean;
  maxVotesPerOption?: number;
  maxVotesPerUser?: number;
}

export interface PollStatus {
  lastInteraction?: number;
  created?: number;
  isArchived?: boolean;
  isExpired?: boolean;
  countParticipants?: number;
}

export interface PollCurrentUserStatus {
  userRole?: string;
  isLocked?: boolean;
  isLoggedIn?: boolean;
  isOwner?: boolean;
  userId?: string;
  yesVotes?: number;
  countVotes?: number;
  orphanedVotes?: number;
}

export interface Poll {
  id: number;
  type: string;
  votingVariant?: string;
  configuration: PollConfiguration;
  descriptionSafe?: string;
  owner: PollUser;
  status?: PollStatus;
  currentUserStatus?: PollCurrentUserStatus;
}

export interface PollOption {
  id: number;
  pollId: number;
  text?: string;
  timestamp?: number;
  duration?: number;
  order?: number;
  confirmed?: number;
  votes?: { yes: number; no: number; maybe: number; count: number; currentUser?: string | null };
}

export interface PollVote {
  id: number;
  pollId: number;
  optionId?: number;
  optionText?: string;
  /** 'yes' | 'no' | 'maybe' */
  answer?: string;
  user?: PollUser;
}

export interface PollComment {
  id: number;
  pollId: number;
  comment?: string;
  timestamp?: number;
  confidential?: number | boolean;
  user?: PollUser;
}

export interface PollShare {
  id: number;
  token: string;
  type: string;
  pollId: number;
  label?: string;
  URL?: string;
  locked?: boolean;
  invitationSent?: boolean;
  user?: PollUser;
}

/**
 * Make an authenticated request to the Nextcloud Polls API v1.0 and return the
 * OCS `data` payload.
 *
 * Base path: /ocs/v2.php/apps/polls/api/v1.0. The API is served as OCS routes
 * (#[ApiRoute]); /index.php/apps/polls/api/v1.0 only answers CORS preflights.
 */
export async function fetchPollsAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = basicAuth(config);

  let url = `${config.url}/ocs/v2.php/apps/polls/api/v1.0${endpoint}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[polls] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('application/json')) {
    const json = (await response.json()) as { ocs?: { data?: unknown } };
    return (json.ocs ? json.ocs.data : json) as T;
  }

  return undefined as T;
}
