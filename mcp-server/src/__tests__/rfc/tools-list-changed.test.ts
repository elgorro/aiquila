// SPDX-License-Identifier: MIT

// tools.listChanged and notifications/tools/list_changed.
// MCP 2026-07-28, Tools › Capabilities: listChanged "indicates whether the
// server will emit notifications when the list of available tools changes".
// MCP 2026-07-28, Subscriptions: list_changed goes to the subscriptions/listen
// streams that opted in with toolsListChanged: true.

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  sseMessages as messages,
  LEGACY,
  type Stack,
} from './harness.js';
import { _expireCache } from '../../tool-registry.js';

const listen = (id: number) =>
  modernBody(id, 'subscriptions/listen', { notifications: { toolsListChanged: true } });
const isChange = (m: any) => m.method === 'notifications/tools/list_changed';
const isAck = (m: any) => m.method === 'notifications/subscriptions/acknowledged';

async function toolNames(s: Stack, token: string): Promise<string[]> {
  const body = await rpcResult(await mcp(s, modernBody(9, 'tools/list'), { token }));
  return body.result.tools.map((t: { name: string }) => t.name);
}

describe('MCP 2026-07-28 Tools: list_changed for subscriptions/listen', () => {
  let s: Stack;
  let alice: string;
  let bob: string;
  beforeAll(async () => {
    // App detection on (no MCP_TOOLS), so each user's list follows their apps.
    s = await startStack({ MCP_TOOLS: undefined });
    s.nc.enabledApps.set('alice', ['notes']);
    s.nc.enabledApps.set('bob', ['notes']);
    const clientId = (await register(s)).client_id;
    alice = (await obtainTokens(s, clientId)).access_token;
    bob = (await obtainTokens(s, clientId, { username: 'bob' })).access_token;
  });
  afterAll(() => s.close());

  it('Capabilities: a 2026-07-28 server/discover advertises tools.listChanged', async () => {
    const body = await rpcResult(await mcp(s, modernBody(1, 'server/discover'), { token: alice }));
    expect(body.result.capabilities.tools.listChanged).toBe(true);
  });

  it('Capabilities: the stateless 2025-era leg, which cannot notify, advertises false', async () => {
    const res = await fetch(`${s.base}/mcp`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json, text/event-stream',
        authorization: `Bearer ${alice}`,
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion: LEGACY,
          capabilities: {},
          clientInfo: { name: 'rfc-test', version: '1.0' },
        },
      }),
    });
    const body = await rpcResult(res);
    expect(body.result.capabilities.tools?.listChanged).toBe(false);
  });

  it('Subscriptions: the ack honours toolsListChanged', async () => {
    const res = await mcp(s, listen(2), { token: alice });
    const stream = messages(res);
    const ack = await stream.waitFor(isAck);
    expect(ack?.params?.notifications?.toolsListChanged).toBe(true);
    await stream.cancel();
  });

  it("a change to one user's tools notifies that user's stream, not another user's", async () => {
    expect(await toolNames(s, alice)).not.toContain('deck_list_boards');

    const aliceStream = messages(await mcp(s, listen(3), { token: alice }));
    const bobStream = messages(await mcp(s, listen(4), { token: bob }));
    expect(await aliceStream.waitFor(isAck)).toBeDefined();
    expect(await bobStream.waitFor(isAck)).toBeDefined();

    // Deck is enabled for alice; her cached detection runs out.
    s.nc.enabledApps.set('alice', ['notes', 'deck']);
    _expireCache();
    const names = await toolNames(s, alice);
    expect(names.some((n) => n.startsWith('deck_'))).toBe(true);

    expect(await aliceStream.waitFor(isChange)).toMatchObject({
      jsonrpc: '2.0',
      method: 'notifications/tools/list_changed',
    });
    // bob's list did not change: his re-detection finds the same apps.
    await toolNames(s, bob);
    expect(await bobStream.waitFor(isChange, 300)).toBeUndefined();

    await aliceStream.cancel();
    await bobStream.cancel();
  });

  it('a re-detection that finds the same tools sends nothing', async () => {
    const stream = messages(await mcp(s, listen(5), { token: alice }));
    expect(await stream.waitFor(isAck)).toBeDefined();
    _expireCache();
    await toolNames(s, alice);
    expect(await stream.waitFor(isChange, 300)).toBeUndefined();
    await stream.cancel();
  });
});
