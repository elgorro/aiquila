// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const mockGetFilteredToolSets = vi.fn();
let changeListener: ((key: string) => void) | undefined;
vi.mock('../../tool-registry.js', () => ({
  USER_CACHE_TTL_MS: 600_000,
  getFilteredToolSets: () => mockGetFilteredToolSets(),
  onToolSetChange: (l: (key: string) => void) => {
    changeListener = l;
    return () => (changeListener = undefined);
  },
  toolSetKey: (i: { kind: string; user?: { userId: string } } | undefined) =>
    i?.kind === 'user' ? `user:${i.user!.userId}` : 'env',
}));

import { currentIdentity, runAs, type RequestIdentity } from '../../nc-context.js';
import { IdentityScopedEventBus } from '../../transports/tool-list-changes.js';

const asUser = (userId: string): RequestIdentity => ({
  kind: 'user',
  user: { userId, loginName: userId, appPassword: 'pw', grantId: `g-${userId}` },
});

describe('IdentityScopedEventBus', () => {
  let bus: IdentityScopedEventBus;
  beforeEach(() => {
    vi.useFakeTimers();
    mockGetFilteredToolSets.mockReset().mockResolvedValue([]);
    bus = new IdentityScopedEventBus(1000);
  });
  afterEach(() => {
    bus.close();
    vi.useRealTimers();
  });

  it('delivers a tool-list change only to the streams of that account', () => {
    const alice = vi.fn();
    const bob = vi.fn();
    runAs(asUser('alice'), () => bus.subscribe(alice));
    runAs(asUser('bob'), () => bus.subscribe(bob));

    changeListener!('user:alice');
    expect(alice).toHaveBeenCalledWith({ kind: 'tools_list_changed' });
    expect(bob).not.toHaveBeenCalled();
  });

  it('publish() reaches every stream', () => {
    const alice = vi.fn();
    const env = vi.fn();
    runAs(asUser('alice'), () => bus.subscribe(alice));
    bus.subscribe(env);
    bus.publish({ kind: 'tools_list_changed' });
    expect(alice).toHaveBeenCalledTimes(1);
    expect(env).toHaveBeenCalledTimes(1);
  });

  it('re-detects the tool list as the user while a stream is open, and stops after', async () => {
    let seen: RequestIdentity | undefined;
    mockGetFilteredToolSets.mockImplementation(async () => {
      seen = currentIdentity();
      return [];
    });
    const unsubscribe = runAs(asUser('alice'), () => bus.subscribe(vi.fn()));

    await vi.advanceTimersByTimeAsync(1000);
    expect(mockGetFilteredToolSets).toHaveBeenCalledTimes(1);
    expect(seen).toEqual(asUser('alice'));

    unsubscribe();
    expect(bus.listenerCount('user:alice')).toBe(0);
    await vi.advanceTimersByTimeAsync(5000);
    expect(mockGetFilteredToolSets).toHaveBeenCalledTimes(1);
  });

  it('keeps one refresh per user however many streams are open', async () => {
    const a = runAs(asUser('alice'), () => bus.subscribe(vi.fn()));
    runAs(asUser('alice'), () => bus.subscribe(vi.fn()));
    await vi.advanceTimersByTimeAsync(1000);
    expect(mockGetFilteredToolSets).toHaveBeenCalledTimes(1);
    a();
    a(); // idempotent
    expect(bus.listenerCount('user:alice')).toBe(1);
  });

  it('does not refresh for the environment account, whose list never changes', async () => {
    bus.subscribe(vi.fn());
    await vi.advanceTimersByTimeAsync(5000);
    expect(mockGetFilteredToolSets).not.toHaveBeenCalled();
  });

  it('a failed refresh is swallowed', async () => {
    mockGetFilteredToolSets.mockRejectedValue(new Error('down'));
    runAs(asUser('alice'), () => bus.subscribe(vi.fn()));
    await vi.advanceTimersByTimeAsync(1000);
    expect(mockGetFilteredToolSets).toHaveBeenCalledTimes(1);
  });
});
