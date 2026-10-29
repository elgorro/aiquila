// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, afterEach } from 'vitest';
import { LoginThrottle } from '../../auth/login-throttle.js';

afterEach(() => {
  vi.useRealTimers();
});

describe('LoginThrottle', () => {
  it('limits attempts per IP', () => {
    const t = new LoginThrottle({ perIp: 3 });
    expect([1, 2, 3, 4].map(() => t.allowIp('1.2.3.4'))).toEqual([true, true, true, false]);
    expect(t.allowIp('5.6.7.8')).toBe(true);
  });

  it('locks a username after its failures, whatever the IP', () => {
    const t = new LoginThrottle({ perUser: 3 });
    for (let i = 0; i < 2; i++) t.recordFailure('alice');
    expect(t.isUserLocked('alice')).toBe(false);
    t.recordFailure('alice');
    expect(t.isUserLocked('alice')).toBe(true);
    expect(t.isUserLocked('bob')).toBe(false);
  });

  it('counts usernames case-insensitively', () => {
    const t = new LoginThrottle({ perUser: 2 });
    t.recordFailure('Alice');
    t.recordFailure(' alice ');
    expect(t.isUserLocked('ALICE')).toBe(true);
  });

  it('a successful login clears the count', () => {
    const t = new LoginThrottle({ perUser: 2 });
    t.recordFailure('alice');
    t.recordSuccess('alice');
    t.recordFailure('alice');
    expect(t.isUserLocked('alice')).toBe(false);
  });

  it('the lock ends with the window', () => {
    vi.useFakeTimers();
    const t = new LoginThrottle({ perUser: 1, windowMs: 1000 });
    t.recordFailure('alice');
    expect(t.isUserLocked('alice')).toBe(true);
    vi.advanceTimersByTime(1001);
    expect(t.isUserLocked('alice')).toBe(false);
  });
});
