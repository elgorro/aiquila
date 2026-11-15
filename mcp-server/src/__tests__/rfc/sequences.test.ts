// SPDX-License-Identifier: MIT

// Sequences in tools/list and tools/call, against MCP 2026-07-28:
// - Tools › Capabilities: the tool set "MAY vary by the authorization
//   presented on the request" but "MUST NOT vary per-connection", and servers
//   SHOULD return tools in a deterministic order.
// - Tools › Tool Names: 1–128 characters of [A-Za-z0-9_.-], unique.
// - Caching: ttlMs and cacheScope MUST be on a tools/list result; a list
//   that depends on the caller is "private".
// - Subscriptions: list_changed goes to streams that opted in.
// - Tools › Error Handling: an unknown tool is -32602.
// The sequences themselves come from the app with an ETag (RFC 9110 §8.8.3),
// revalidated with If-None-Match (§13.1.2), answered 304 (§15.4.5).

import { describe, it, expect, beforeAll, afterAll, beforeEach } from 'vitest';
import {
  startStack,
  register,
  obtainTokens,
  mcp,
  modernBody,
  rpcResult,
  sseMessages,
  type Stack,
} from './harness.js';
import { _expireSequenceCache } from '../../sequences/store.js';
import { BUILTIN_SEQUENCES } from '../../sequences/builtin.js';

const definition = { steps: [{ id: 'time', tool: 'get_local_time' }] };

function effective(slugs: string[], pinned = true) {
  return {
    sequences: slugs.map((slug) => ({ scope: 'user', slug, title: slug, definition, pinned })),
    disabledBuiltins: [],
    policy: { allowPersonal: true, maxPerUser: 50, maxSteps: 20, maxPinned: 10 },
  };
}

async function list(s: Stack, token?: string) {
  const body = await rpcResult(await mcp(s, modernBody(1, 'tools/list'), { token }));
  return body.result;
}

const names = (result: { tools: { name: string }[] }) => result.tools.map((t) => t.name);

describe('MCP 2026-07-28 Tools: sequences in tools/list and tools/call', () => {
  let s: Stack;
  let alice: string;
  let bob: string;
  beforeAll(async () => {
    s = await startStack({ MCP_TOOLS: 'status,sequences' });
    s.nc.sequences.set('alice', { etag: '"a1"', body: effective(['alpha']) });
    s.nc.sequences.set('bob', { etag: '"b1"', body: effective(['beta']) });
    const clientId = (await register(s)).client_id;
    alice = (await obtainTokens(s, clientId)).access_token;
    bob = (await obtainTokens(s, clientId, { username: 'bob' })).access_token;
  });
  afterAll(() => s.close());
  beforeEach(() => _expireSequenceCache());

  it('Capabilities: two bearer tokens get different lists; each gets only its own', async () => {
    const a = names(await list(s, alice));
    const b = names(await list(s, bob));
    expect(a).toContain('seq_my_alpha');
    expect(a).not.toContain('seq_my_beta');
    expect(b).toContain('seq_my_beta');
    expect(b).not.toContain('seq_my_alpha');
  });

  it('Capabilities: one token gets the same list in the same order every time', async () => {
    const first = names(await list(s, alice));
    _expireSequenceCache();
    expect(names(await list(s, alice))).toEqual(first);
  });

  it('Capabilities: an anonymous list has the built-ins and the two fixed tools only', async () => {
    const before = s.nc.requests.length;
    const anon = names(await list(s));
    expect(anon).toContain('list_sequences');
    expect(anon).toContain('run_sequence');
    expect(anon.filter((n) => n.startsWith('seq_')).sort()).toEqual(
      BUILTIN_SEQUENCES.filter((q) => q.pinned)
        .map((q) => `seq_${q.slug}`)
        .sort()
    );
    // Nothing reached Nextcloud on its behalf.
    expect(s.nc.requests.slice(before).some((r) => r.url.includes('/sequences/'))).toBe(false);
  });

  it('Tool Names: every name is 1–128 characters of [A-Za-z0-9_.-], unique', async () => {
    const all = names(await list(s, alice));
    for (const n of all) expect(n).toMatch(/^[A-Za-z0-9_.-]{1,128}$/);
    expect(new Set(all).size).toBe(all.length);
  });

  it('Caching: the list carries ttlMs and cacheScope "private"', async () => {
    const result = await list(s, alice);
    expect(Number.isInteger(result.ttlMs)).toBe(true);
    expect(result.ttlMs).toBeGreaterThanOrEqual(0);
    expect(result.cacheScope).toBe('private');
  });

  it('RFC 9110 §13.1.2: the server revalidates with If-None-Match and keeps a 304', async () => {
    await list(s, alice);
    const fetches = s.nc.requests.filter(
      (r) =>
        r.url.endsWith('/sequences/effective') && s.nc.userOf(s.nc.requests.indexOf(r)) === 'alice'
    );
    expect(fetches.at(-1)?.headers['if-none-match']).toBe('"a1"');
    expect(names(await list(s, alice))).toContain('seq_my_alpha');
  });

  it('Subscriptions: a changed sequence ETag sends list_changed to that user only', async () => {
    const listen = (id: number) =>
      modernBody(id, 'subscriptions/listen', { notifications: { toolsListChanged: true } });
    const isChange = (m: any) => m.method === 'notifications/tools/list_changed';
    const aliceStream = sseMessages(await mcp(s, listen(2), { token: alice }));
    const bobStream = sseMessages(await mcp(s, listen(3), { token: bob }));
    await aliceStream.waitFor((m) => m.method === 'notifications/subscriptions/acknowledged');
    await bobStream.waitFor((m) => m.method === 'notifications/subscriptions/acknowledged');

    s.nc.sequences.set('alice', { etag: '"a2"', body: effective(['alpha', 'gamma']) });
    _expireSequenceCache();
    expect(names(await list(s, alice))).toContain('seq_my_gamma');
    expect(await aliceStream.waitFor(isChange)).toBeDefined();
    await list(s, bob);
    expect(await bobStream.waitFor(isChange, 300)).toBeUndefined();

    await aliceStream.cancel();
    await bobStream.cancel();
  });

  it('tools/call runs a sequence as the caller and reports each step', async () => {
    const body = await rpcResult(
      await mcp(s, modernBody(4, 'tools/call', { name: 'seq_my_alpha', arguments: {} }), {
        token: alice,
      })
    );
    expect(body.result.isError).toBeFalsy();
    expect(body.result.structuredContent).toMatchObject({
      sequence: 'seq_my_alpha',
      status: 'completed',
      steps: [{ id: 'time', tool: 'get_local_time', status: 'ok' }],
    });
  });

  it("run_sequence cannot reach another user's sequence", async () => {
    const body = await rpcResult(
      await mcp(
        s,
        modernBody(5, 'tools/call', {
          name: 'run_sequence',
          arguments: { sequence: 'seq_my_beta' },
        }),
        {
          token: alice,
        }
      )
    );
    expect(body.result.isError).toBe(true);
  });

  it('Error Handling: an unknown tool is -32602', async () => {
    const body = await rpcResult(
      await mcp(s, modernBody(6, 'tools/call', { name: 'seq_my_beta', arguments: {} }), {
        token: alice,
      })
    );
    expect(body.error?.code).toBe(-32602);
  });
});
