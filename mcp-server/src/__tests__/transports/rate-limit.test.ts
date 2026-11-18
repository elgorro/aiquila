// SPDX-License-Identifier: MIT

import { describe, it, expect, afterEach } from 'vitest';
import {
  countMessages,
  countToolCalls,
  createRateLimiter,
  limitFromEnv,
} from '../../transports/rate-limit.js';

describe('createRateLimiter', () => {
  it('allows up to the limit per key and window, then reports the wait', () => {
    let t = 0;
    const l = createRateLimiter(2, 60_000, () => t);
    expect(l.hit('a', 1).allowed).toBe(true);
    expect(l.hit('a', 1).allowed).toBe(true);
    t = 15_500;
    expect(l.hit('a', 1)).toEqual({ allowed: false, retryAfter: 45 });
    expect(l.hit('b', 1).allowed).toBe(true);
  });

  it('starts a new window once the old one has passed', () => {
    let t = 0;
    const l = createRateLimiter(1, 60_000, () => t);
    expect(l.hit('a', 1).allowed).toBe(true);
    expect(l.hit('a', 1).allowed).toBe(false);
    t = 60_000;
    expect(l.hit('a', 1).allowed).toBe(true);
  });

  it('counts a weight as several hits and refuses one that does not fit', () => {
    const l = createRateLimiter(3, 60_000, () => 0);
    expect(l.hit('a', 2).allowed).toBe(true);
    expect(l.hit('a', 2).allowed).toBe(false);
    expect(l.hit('a', 1).allowed).toBe(true);
  });

  it('never reports less than one second', () => {
    let t = 0;
    const l = createRateLimiter(1, 60_000, () => t);
    l.hit('a', 1);
    t = 59_999;
    expect(l.hit('a', 1)).toEqual({ allowed: false, retryAfter: 1 });
  });
});

describe('message counting', () => {
  const callMsg = { jsonrpc: '2.0', id: 1, method: 'tools/call' };
  it('counts tools/call in a single message and in a batch', () => {
    expect(countToolCalls(callMsg)).toBe(1);
    expect(countToolCalls({ method: 'tools/list' })).toBe(0);
    expect(countToolCalls({ method: 'resources/read' })).toBe(1);
    expect(countToolCalls({ method: 'resources/list' })).toBe(0);
    expect(countToolCalls([callMsg, { method: 'ping' }, callMsg])).toBe(2);
    expect(countToolCalls(undefined)).toBe(0);
  });
  it('counts every message, and at least one', () => {
    expect(countMessages([callMsg, callMsg])).toBe(2);
    expect(countMessages(callMsg)).toBe(1);
    expect(countMessages([])).toBe(1);
  });
});

describe('limitFromEnv', () => {
  afterEach(() => {
    delete process.env.TEST_LIMIT;
  });
  it('uses the fallback when unset or invalid', () => {
    expect(limitFromEnv('TEST_LIMIT', 10)).toBe(10);
    process.env.TEST_LIMIT = 'lots';
    expect(limitFromEnv('TEST_LIMIT', 10)).toBe(10);
  });
  it('reads a number, and 0 disables', () => {
    process.env.TEST_LIMIT = ' 42 ';
    expect(limitFromEnv('TEST_LIMIT', 10)).toBe(42);
    process.env.TEST_LIMIT = '0';
    expect(limitFromEnv('TEST_LIMIT', 10)).toBeNull();
  });
});
