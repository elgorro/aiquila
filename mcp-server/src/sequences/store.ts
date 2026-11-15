// SPDX-License-Identifier: MIT

/**
 * The sequences a caller has: the built-ins, plus the organization's and
 * their own from the AIquila Nextcloud app.
 *
 * `GET /api/sequences/effective` answers as the signed-in user, with an
 * `ETag`. Each account's answer is kept for STORE_TTL_MS, then revalidated
 * with `If-None-Match`; a `304 Not Modified` keeps it (RFC 9110 §13.1.2,
 * §15.4.5). When the tag changes, the account's tool list changed, and the
 * listeners registered with `onSequencesChange` hear of it, so 2026-07-28
 * `subscriptions/listen` streams get `notifications/tools/list_changed`.
 *
 * Without the AIquila app, or if Nextcloud cannot be reached, a caller has
 * the built-ins only (a stale answer is kept while Nextcloud is down).
 */

import { getNextcloudConfig, basicAuth } from '../tools/types.js';
import { logger } from '../logger.js';
import { BUILTIN_SEQUENCES } from './builtin.js';
import { checkTemplates } from './template.js';
import { LIMITS, SequenceDefinitionSchema, type Sequence } from './definition.js';

export const STORE_TTL_MS = 30_000;
const MAX_CACHED = 500;

export interface SequencePolicy {
  allowPersonal: boolean;
  maxPerUser: number;
  maxSteps: number;
  maxPinned: number;
}

export const DEFAULT_POLICY: SequencePolicy = {
  allowPersonal: true,
  maxPerUser: 50,
  maxSteps: LIMITS.steps,
  maxPinned: 10,
};

/** The body of `GET /api/sequences/effective`. */
interface EffectiveResponse {
  sequences?: {
    scope?: string;
    slug?: string;
    title?: string;
    description?: string | null;
    definition?: { inputs?: unknown; steps?: unknown };
    pinned?: boolean;
  }[];
  disabledBuiltins?: string[];
  policy?: Partial<SequencePolicy>;
}

export interface EffectiveSequences {
  sequences: Sequence[];
  policy: SequencePolicy;
}

interface Entry {
  etag: string | null;
  data: EffectiveResponse | null;
  checkedAt: number;
}

const cache = new Map<string, Entry>();
const listeners = new Set<(key: string) => void>();

/** Called with an account's key when its sequences changed. Returns an unsubscribe function. */
export function onSequencesChange(listener: (key: string) => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function clampInt(v: unknown, min: number, max: number, fallback: number): number {
  return typeof v === 'number' && Number.isInteger(v) ? Math.min(max, Math.max(min, v)) : fallback;
}

function policyOf(raw: Partial<SequencePolicy> | undefined): SequencePolicy {
  return {
    allowPersonal:
      typeof raw?.allowPersonal === 'boolean' ? raw.allowPersonal : DEFAULT_POLICY.allowPersonal,
    maxPerUser: clampInt(raw?.maxPerUser, 0, 1000, DEFAULT_POLICY.maxPerUser),
    maxSteps: clampInt(raw?.maxSteps, 1, LIMITS.steps, DEFAULT_POLICY.maxSteps),
    maxPinned: clampInt(raw?.maxPinned, 0, 1000, DEFAULT_POLICY.maxPinned),
  };
}

const memo = new WeakMap<EffectiveResponse, EffectiveSequences>();
let builtinsOnly: EffectiveSequences | undefined;

/** Turns the app's answer into sequences, dropping any row that does not validate. */
export function effectiveFrom(data: EffectiveResponse | null): EffectiveSequences {
  if (data === null) return (builtinsOnly ??= buildEffective(null));
  let out = memo.get(data);
  if (!out) memo.set(data, (out = buildEffective(data)));
  return out;
}

function buildEffective(data: EffectiveResponse | null): EffectiveSequences {
  const policy = policyOf(data?.policy);
  const disabled = new Set(data?.disabledBuiltins ?? []);
  const builtins = BUILTIN_SEQUENCES.filter((s) => !disabled.has(s.slug));
  const taken = new Set(BUILTIN_SEQUENCES.map((s) => `seq_${s.slug}`));

  const org: Sequence[] = [];
  const personal: Sequence[] = [];
  for (const row of data?.sequences ?? []) {
    const source =
      row.scope === 'instance' ? 'organization' : row.scope === 'user' ? 'personal' : null;
    if (!source) continue;
    const parsed = SequenceDefinitionSchema.safeParse({
      slug: row.slug,
      title: row.title,
      description: row.description ?? '',
      inputs: row.definition?.inputs ?? [],
      steps: row.definition?.steps,
      pinned: row.pinned === true,
    });
    const problems = parsed.success ? checkTemplates(parsed.data) : [];
    if (!parsed.success || problems.length > 0) {
      logger.warn(
        {
          slug: row.slug,
          source,
          problem: parsed.success ? problems[0] : parsed.error.issues[0]?.message,
        },
        '[seq] Ignoring an invalid sequence'
      );
      continue;
    }
    const seq: Sequence = { ...parsed.data, source };
    const name = source === 'personal' ? `seq_my_${seq.slug}` : `seq_${seq.slug}`;
    if (taken.has(name) || (source === 'organization' && /^my[_-]/.test(seq.slug))) {
      logger.warn({ slug: seq.slug, source }, '[seq] Ignoring a sequence whose name is taken');
      continue;
    }
    taken.add(name);
    (source === 'organization' ? org : personal).push(seq);
  }

  const bySlug = (a: Sequence, b: Sequence) => (a.slug < b.slug ? -1 : a.slug > b.slug ? 1 : 0);
  org.sort(bySlug);
  personal.sort(bySlug);
  const mine = policy.allowPersonal ? personal.slice(0, policy.maxPerUser) : [];
  let pinned = 0;
  for (const s of mine) {
    if (s.pinned && ++pinned > policy.maxPinned) s.pinned = false;
  }
  return { sequences: [...builtins, ...org, ...mine], policy };
}

/** Fetches the app's answer, conditionally on `etag`. null body on 304. */
async function fetchEffective(
  etag: string | null
): Promise<{ status: number; etag: string | null; data: EffectiveResponse | null }> {
  const config = getNextcloudConfig();
  const headers: Record<string, string> = {
    Authorization: `Basic ${basicAuth(config)}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };
  if (etag) headers['If-None-Match'] = etag;
  const res = await fetch(`${config.url}/index.php/apps/aiquila/api/sequences/effective`, {
    headers,
    signal: AbortSignal.timeout(10_000),
  });
  if (res.status === 304) return { status: 304, etag, data: null };
  if (!res.ok) {
    await res.body?.cancel();
    return { status: res.status, etag: null, data: null };
  }
  return {
    status: res.status,
    etag: res.headers.get('etag'),
    data: (await res.json()) as EffectiveResponse,
  };
}

/**
 * The effective sequences of the account `key` names (see `toolSetKey`).
 * `appAvailable` is false when the account has no AIquila app: built-ins only.
 */
export async function getEffectiveSequences(
  key: string,
  appAvailable: boolean
): Promise<EffectiveSequences> {
  if (!appAvailable || key === 'anonymous') return effectiveFrom(null);

  const now = Date.now();
  const cached = cache.get(key);
  if (cached && now - cached.checkedAt < STORE_TTL_MS) return effectiveFrom(cached.data);

  let next: Entry;
  try {
    const res = await fetchEffective(cached?.etag ?? null);
    if (res.status === 304 && cached) {
      next = { ...cached, checkedAt: now };
    } else if (res.status === 200) {
      next = { etag: res.etag, data: res.data, checkedAt: now };
    } else if (res.status === 404) {
      // The app is not installed (or predates sequences): built-ins only.
      next = { etag: null, data: null, checkedAt: now };
    } else {
      throw new Error(`HTTP ${res.status}`);
    }
  } catch (err) {
    logger.debug(
      { err: err instanceof Error ? err.message : String(err) },
      '[seq] Could not load sequences from Nextcloud'
    );
    next = { etag: cached?.etag ?? null, data: cached?.data ?? null, checkedAt: now };
  }

  cache.delete(key);
  if (cache.size >= MAX_CACHED) {
    const oldest = cache.keys().next().value;
    if (oldest !== undefined) cache.delete(oldest);
  }
  cache.set(key, next);

  if (cached && cached.etag !== next.etag) {
    logger.info({ key: key.split(':')[0] }, '[seq] Sequences changed');
    for (const listener of listeners) {
      try {
        listener(key);
      } catch (err) {
        logger.warn({ err: err instanceof Error ? err.message : String(err) }, '[seq] Listener');
      }
    }
  }
  return effectiveFrom(next.data);
}

/** Forget every cached answer — for testing only. */
export function _resetSequenceCache(): void {
  cache.clear();
}

/** Make every cached answer due for revalidation — for testing only. */
export function _expireSequenceCache(): void {
  for (const e of cache.values()) e.checkedAt = 0;
}
