// SPDX-License-Identifier: MIT

/**
 * Rate limit for the MCP endpoint.
 *
 * MCP 2026-07-28, Tools › Security Considerations: servers MUST "rate limit
 * tool invocations". Two buckets, each a fixed window of one minute:
 *
 * - **Tool calls**, keyed by the signed-in Nextcloud user (or by client
 *   address when OAuth is off). Every `tools/call` message counts, including
 *   each one in a legacy JSON-RPC batch, since each becomes a request to
 *   Nextcloud. So does every `resources/read`, for the same reason. Other
 *   methods do not count.
 * - **Anonymous requests** (lazy auth), keyed by client address. Every message
 *   counts. Distributed clients reach the server from several addresses, so
 *   signed-in users are never keyed by address.
 *
 * The refusal is an HTTP 429 with `Retry-After` in delay-seconds (RFC 6585 §4,
 * RFC 9110 §10.2.3), sent before the message reaches the SDK, like the
 * lazy-auth gate. RFC 6585 §4: a 429 MUST NOT be stored by a cache. The MCP
 * spec defines no error code for this; the body is a JSON-RPC error with no
 * `id`, as Streamable HTTP allows for a 403, with a code from the
 * implementation-defined server-error range.
 *
 * Counters are in memory, per process.
 */

import type { NextFunction, Request, Response } from 'express';
import { logger } from '../logger.js';

export const RATE_LIMIT_ERROR_CODE = -32029;
const WINDOW_MS = 60_000;

export const DEFAULT_TOOL_CALL_LIMIT = 300;
export const DEFAULT_ANONYMOUS_LIMIT = 60;

interface Window {
  count: number;
  resetAt: number;
}

export interface RateLimiter {
  /** Records `weight` hits for `key`; returns seconds to wait if over the limit. */
  hit(key: string, weight: number): { allowed: true } | { allowed: false; retryAfter: number };
}

export function createRateLimiter(
  limit: number,
  windowMs = WINDOW_MS,
  now: () => number = Date.now
): RateLimiter {
  const windows = new Map<string, Window>();
  let nextSweep = now() + windowMs;

  return {
    hit(key, weight) {
      const t = now();
      // Drop expired windows once per window so the map cannot grow without bound.
      if (t >= nextSweep) {
        for (const [k, w] of windows) if (w.resetAt <= t) windows.delete(k);
        nextSweep = t + windowMs;
      }
      let w = windows.get(key);
      if (!w || w.resetAt <= t) {
        w = { count: 0, resetAt: t + windowMs };
        windows.set(key, w);
      }
      if (w.count + weight > limit) {
        return { allowed: false, retryAfter: Math.max(1, Math.ceil((w.resetAt - t) / 1000)) };
      }
      w.count += weight;
      return { allowed: true };
    },
  };
}

/**
 * Reads a per-minute limit from the environment. Unset or invalid → `fallback`;
 * `0` disables the limit (returns null).
 */
export function limitFromEnv(name: string, fallback: number): number | null {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') return fallback;
  if (!/^\d+$/.test(raw.trim())) {
    logger.warn({ [name]: raw }, `[startup] Ignoring invalid ${name}; using ${fallback}`);
    return fallback;
  }
  const n = Number(raw.trim());
  return n === 0 ? null : n;
}

function messages(body: unknown): unknown[] {
  return Array.isArray(body) ? body : [body];
}

/** The methods that reach Nextcloud on the caller's behalf. */
const COUNTED_METHODS: ReadonlySet<unknown> = new Set(['tools/call', 'resources/read']);

/**
 * Number of `tools/call` and `resources/read` messages in a JSON-RPC body (a
 * single message or a batch).
 */
export function countToolCalls(body: unknown): number {
  return messages(body).filter(
    (m) => !!m && typeof m === 'object' && COUNTED_METHODS.has((m as { method?: unknown }).method)
  ).length;
}

/** Number of JSON-RPC messages in a body; at least 1, so an empty or odd body still counts. */
export function countMessages(body: unknown): number {
  return Math.max(1, messages(body).length);
}

/** Address of the client as Express sees it (honours MCP_TRUST_PROXY). */
export function clientAddress(req: Request): string {
  return req.ip ?? req.socket?.remoteAddress ?? 'unknown';
}

/**
 * Applies `limiter` with the key and weight the callbacks give. Returns true
 * if the request may proceed; otherwise it has answered 429. A weight of 0
 * passes the request through uncounted.
 */
export type RateLimitCheck = (req: Request, res: Response) => boolean;

export function rateLimitCheck(
  limiter: RateLimiter | null,
  key: (req: Request) => string,
  weight: (req: Request) => number,
  what: string
): RateLimitCheck {
  return (req, res) => {
    if (!limiter) return true;
    const w = weight(req);
    if (w === 0) return true;
    const result = limiter.hit(key(req), w);
    if (result.allowed) return true;
    logger.warn({ what, retryAfter: result.retryAfter }, '[mcp] Rate limit exceeded');
    res
      .status(429)
      .set('Retry-After', String(result.retryAfter))
      .set('Cache-Control', 'no-store')
      .json({
        jsonrpc: '2.0',
        error: {
          code: RATE_LIMIT_ERROR_CODE,
          message: `Rate limit exceeded for ${what}; retry after ${result.retryAfter} s`,
        },
      });
    return false;
  };
}

/** Express middleware form of {@link rateLimitCheck}. */
export function rateLimit(check: RateLimitCheck) {
  return (req: Request, res: Response, next: NextFunction) => {
    if (check(req, res)) next();
  };
}
