// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export interface PollOwner {
  userId: string;
  displayName: string;
  emailAddress?: string;
  type?: string;
}

export interface PollConfiguration {
  title: string;
  description?: string;
  access?: string;
  allowComment?: boolean;
  allowMaybe?: boolean;
  allowProposals?: string | boolean;
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
  deleted?: boolean;
  expired?: boolean;
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
  isSubscribed?: boolean;
}

export interface Poll {
  id: number;
  type: string;
  configuration: PollConfiguration;
  descriptionSafe?: string;
  owner: PollOwner;
  status?: PollStatus;
  currentUserStatus?: PollCurrentUserStatus;
}

export interface PollOption {
  id: number;
  pollId: number;
  pollOptionText?: string;
  text?: string;
  timestamp?: number;
  duration?: number;
  order?: number;
  confirmed?: number;
  no?: number;
  yes?: number;
  maybe?: number;
  realNo?: number;
  realYes?: number;
  realMaybe?: number;
  votes?: Record<string, number>;
}

export interface PollVote {
  id: number;
  pollId: number;
  optionId?: number;
  userId?: string;
  voteAnswer?: string;
  optionText?: string;
}

export interface PollComment {
  id: number;
  pollId: number;
  userId?: string;
  user?: PollOwner;
  comment?: string;
  timestamp?: number;
  dt?: string;
  deleted?: number;
}

export interface PollShare {
  id: number;
  token: string;
  type: string;
  pollId: number;
  userId?: string;
  emailAddress?: string;
  label?: string;
  URL?: string;
  locked?: boolean;
  invitationSent?: boolean;
  user?: PollOwner;
}

/**
 * Make an authenticated request to the Nextcloud Polls REST API v1.0.
 *
 * Base path: /index.php/apps/polls/api/v1.0
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
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/index.php/apps/polls/api/v1.0${endpoint}`;
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
    return (await response.json()) as T;
  }

  return undefined as T;
}
