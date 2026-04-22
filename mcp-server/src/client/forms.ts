// SPDX-License-Identifier: MIT

import { getNextcloudConfig } from '../tools/types.js';
import { logger } from '../logger.js';
import { ApiError } from './aiquila.js';

export type FormState = 0 | 1 | 2;

export type FormPermission = 'edit' | 'results' | 'results_delete' | 'submit' | 'embed';

export type QuestionType =
  | 'short'
  | 'long'
  | 'multiple'
  | 'multiple_unique'
  | 'dropdown'
  | 'date'
  | 'datetime'
  | 'time'
  | 'file'
  | 'linearscale'
  | 'color';

export type ShareType = 0 | 1 | 3;

export interface FormAccess {
  permitAllUsers: boolean;
  showToAllUsers: boolean;
}

export interface FormOption {
  id: number;
  questionId: number;
  order: number;
  text: string;
}

export interface FormQuestionExtraSettings {
  allowOtherAnswer?: boolean;
  shuffleOptions?: boolean;
  optionsLimitMax?: number;
  optionsLimitMin?: number;
  validationType?: string | null;
  validationRegex?: string;
  allowedFileTypes?: string[];
  allowedFileExtensions?: string[];
  maxAllowedFilesCount?: number;
  maxFileSize?: number;
  dateMax?: number;
  dateMin?: number;
  dateRange?: boolean;
  timeMax?: string;
  timeMin?: string;
  timeRange?: boolean;
  optionsLowest?: number;
  optionsHighest?: number;
  optionsLabelLowest?: string;
  optionsLabelHighest?: string;
}

export interface FormQuestion {
  id: number;
  formId: number;
  order: number;
  type: QuestionType;
  isRequired: boolean;
  text: string;
  name: string;
  options: FormOption[];
  extraSettings: FormQuestionExtraSettings;
}

export interface FormAnswer {
  id: number;
  submissionId: number;
  questionId: number;
  questionName?: string;
  text: string;
  fileId?: number;
}

export interface FormSubmission {
  id: number;
  formId: number;
  userId: string;
  userDisplayName: string;
  timestamp: number;
  answers: FormAnswer[];
}

export interface FormShare {
  id: number;
  formId: number;
  shareType: ShareType;
  shareWith: string;
  displayName?: string;
  permissions?: FormPermission[];
}

export interface Form {
  id: number;
  hash: string;
  title: string;
  description: string;
  ownerId: string;
  submissionMessage?: string;
  created: number;
  access: FormAccess;
  expires: number;
  isAnonymous: boolean;
  state: FormState;
  lockedBy?: string | null;
  lockedUntil?: number | null;
  submitMultiple: boolean;
  allowEditSubmissions?: boolean;
  showExpiration: boolean;
  canSubmit: boolean;
  permissions: FormPermission[];
  questions?: FormQuestion[];
  shares?: FormShare[];
  submissions?: FormSubmission[];
  submissionCount?: number;
}

interface OcsEnvelope<T> {
  ocs: {
    meta: {
      status: string;
      statuscode: number;
      message: string;
      totalitems?: string;
      itemsperpage?: string;
    };
    data: T;
  };
}

/**
 * Make an authenticated request to the Nextcloud Forms REST API v3.
 *
 * Base path: /ocs/v2.php/apps/forms/api/v3
 * Unwraps the OCS envelope and returns `ocs.data` directly.
 * Throws {@link ApiError} on HTTP errors or non-success OCS status codes.
 */
export async function fetchFormsAPI<T = unknown>(
  endpoint: string,
  options: {
    method?: string;
    body?: unknown;
    queryParams?: Record<string, string>;
  } = {}
): Promise<T> {
  const config = getNextcloudConfig();
  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  let url = `${config.url}/ocs/v2.php/apps/forms/api/v3${endpoint}`;
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
  logger.trace({ method, url, status: response.status, ms: Date.now() - t0 }, '[forms] HTTP');

  if (!response.ok) {
    const text = await response.text();
    throw new ApiError(response.status, response.statusText, text);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    return undefined as T;
  }

  const json = (await response.json()) as OcsEnvelope<T>;
  const code = json?.ocs?.meta?.statuscode;
  if (code !== undefined && code !== 200 && code !== 100) {
    throw new ApiError(code, json.ocs.meta.status ?? 'error', json.ocs.meta.message ?? '');
  }
  return json.ocs.data;
}
