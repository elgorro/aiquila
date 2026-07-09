// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';
import { isPublicRequest } from '../transports/lazy-auth.js';

const call = (name: string) => ({
  jsonrpc: '2.0',
  id: 1,
  method: 'tools/call',
  params: { name, arguments: {} },
});

describe('isPublicRequest', () => {
  it('allows the handshake and capability listings', () => {
    for (const method of ['initialize', 'notifications/initialized', 'ping', 'tools/list']) {
      expect(isPublicRequest({ jsonrpc: '2.0', id: 1, method })).toBe(true);
    }
  });

  it('allows a public tool', () => {
    expect(isPublicRequest(call('get_local_time'))).toBe(true);
  });

  it('gates a Nextcloud-backed tool', () => {
    expect(isPublicRequest(call('list_files'))).toBe(false);
  });

  it('gates unknown methods', () => {
    expect(isPublicRequest({ jsonrpc: '2.0', id: 1, method: 'resources/read' })).toBe(false);
  });

  it('allows a batch of public messages', () => {
    expect(
      isPublicRequest([{ jsonrpc: '2.0', id: 1, method: 'tools/list' }, call('get_local_time')])
    ).toBe(true);
  });

  it('gates a batch where any message is protected', () => {
    expect(
      isPublicRequest([{ jsonrpc: '2.0', id: 1, method: 'tools/list' }, call('list_files')])
    ).toBe(false);
  });

  it('gates bodyless requests (GET stream, DELETE session)', () => {
    expect(isPublicRequest(undefined)).toBe(false);
    expect(isPublicRequest([])).toBe(false);
  });

  it('gates malformed bodies', () => {
    expect(isPublicRequest(null)).toBe(false);
    expect(isPublicRequest('tools/list')).toBe(false);
    expect(isPublicRequest({ method: 'tools/call' })).toBe(false);
    expect(isPublicRequest({ method: 'tools/call', params: { name: 42 } })).toBe(false);
  });
});
