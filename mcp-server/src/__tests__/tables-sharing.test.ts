// SPDX-License-Identifier: MIT

// Tables sharing (API v1 /shares) and applications (OCS v2 /contexts),
// against the shapes Tables 2.4 sends.

import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  deleteTableContextTool,
  deleteTableShareTool,
  getTableContextTool,
  listTableSharesTool,
  saveTableContextTool,
  shareTableTool,
  updateTableShareTool,
} from '../tools/apps/tables.js';

global.fetch = vi.fn();

type Handler = (url: string, init: { method: string; body?: string }) => unknown;

function serve(handler: Handler, status = 200) {
  const calls: { url: string; method: string; body: unknown }[] = [];
  (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(
    async (url: string, init: { method: string; body?: string }) => {
      calls.push({ url, method: init.method, body: init.body && JSON.parse(init.body) });
      const answer = handler(url, init);
      const data = url.includes('/ocs/')
        ? { ocs: { meta: { status: 'ok', statuscode: status, message: '' }, data: answer } }
        : answer;
      return {
        ok: status < 400,
        status,
        statusText: status < 400 ? 'OK' : 'Internal Server Error',
        json: async () => data,
        text: async () => JSON.stringify(data),
      };
    }
  );
  return calls;
}

const share = (over: Record<string, unknown> = {}) => ({
  id: 1,
  sender: 'alice',
  receiver: 'bob',
  receiverDisplayName: 'Bob',
  receiverType: 'user',
  nodeId: 15,
  nodeType: 'table',
  permissionRead: true,
  permissionCreate: false,
  permissionUpdate: false,
  permissionDelete: false,
  permissionManage: false,
  ...over,
});

const API1 = 'https://cloud.example.com/index.php/apps/tables/api/1';
const OCS = 'https://cloud.example.com/ocs/v2.php/apps/tables/api/2';

describe('Tables sharing', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'pw';
  });

  it('shares a table read-only by default', async () => {
    const calls = serve(() => share());
    const result = await shareTableTool.handler({
      tableId: 15,
      receiver: 'bob',
      receiverType: 'user',
    });
    expect(calls[0]).toEqual({
      url: `${API1}/shares`,
      method: 'POST',
      body: {
        nodeId: 15,
        nodeType: 'table',
        receiver: 'bob',
        receiverType: 'user',
        permissionRead: true,
        permissionCreate: false,
        permissionUpdate: false,
        permissionDelete: false,
        permissionManage: false,
      },
    });
    expect(result.content[0].text).toBe('Shared: [share 1] table 15 → user Bob — read');
  });

  it('shares a view with a team, with the permissions given', async () => {
    const calls = serve(() =>
      share({ nodeType: 'view', nodeId: 3, receiverType: 'circle', permissionUpdate: true })
    );
    await shareTableTool.handler({
      viewId: 3,
      receiver: 'team-id',
      receiverType: 'circle',
      permissions: { read: true, update: true },
    });
    expect(calls[0].body).toMatchObject({
      nodeType: 'view',
      nodeId: 3,
      receiverType: 'circle',
      permissionRead: true,
      permissionUpdate: true,
      permissionDelete: false,
    });
  });

  it('shares a table with a federated user by Federated Cloud ID', async () => {
    const calls = serve(() => share({ receiverType: 'remote', receiver: 'bob@other.example' }));
    const result = await shareTableTool.handler({
      tableId: 15,
      receiver: 'bob@other.example',
      receiverType: 'remote',
    });
    expect(calls[0].body).toMatchObject({ receiverType: 'remote', receiver: 'bob@other.example' });
    expect(result.content[0].text).toContain('remote');
  });

  it('accepts a cloud ID whose user part holds an @', async () => {
    serve(() => share({ receiverType: 'remote' }));
    const result = await shareTableTool.handler({
      tableId: 15,
      receiver: 'bob@mail.example@other.example',
      receiverType: 'remote',
    });
    expect(result).not.toHaveProperty('isError');
  });

  it('refuses a remote receiver that is not a cloud ID, and remote applications', async () => {
    const calls = serve(() => share());
    const plain = await shareTableTool.handler({
      tableId: 15,
      receiver: 'bob',
      receiverType: 'remote',
    });
    expect(plain.isError).toBe(true);
    const context = await shareTableTool.handler({
      contextId: 1,
      receiver: 'bob@other.example',
      receiverType: 'remote',
    });
    expect(context.isError).toBe(true);
    expect(calls).toHaveLength(0);
  });

  it('says a failed remote share may have been kept', async () => {
    serve(() => ({ message: 'Could not send federated share to remote instance' }), 500);
    const result = await shareTableTool.handler({
      tableId: 15,
      receiver: 'bob@other.example',
      receiverType: 'remote',
    });
    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('Could not send federated share');
    expect(result.content[0].text).toContain('list_table_shares');
  });

  it('shares an application with its display mode and no permissions', async () => {
    const calls = serve(() => share({ nodeType: 'context', nodeId: 1 }));
    const result = await shareTableTool.handler({
      contextId: 1,
      receiver: 'bob',
      receiverType: 'user',
      displayMode: 1,
    });
    expect(calls[0].body).toEqual({
      nodeId: 1,
      nodeType: 'context',
      receiver: 'bob',
      receiverType: 'user',
      displayMode: 1,
    });
    expect(result.content[0].text).toBe('Shared: [share 1] context 1 → user Bob');
  });

  it('wants exactly one of tableId, viewId and contextId, and displayMode for applications only', async () => {
    const calls = serve(() => share());
    expect((await shareTableTool.handler({ receiver: 'bob', receiverType: 'user' })).isError).toBe(
      true
    );
    expect(
      (
        await shareTableTool.handler({
          tableId: 1,
          viewId: 2,
          receiver: 'bob',
          receiverType: 'user',
        })
      ).isError
    ).toBe(true);
    expect(
      (
        await shareTableTool.handler({
          tableId: 1,
          receiver: 'bob',
          receiverType: 'user',
          displayMode: 0,
        })
      ).isError
    ).toBe(true);
    expect(calls).toHaveLength(0);
  });

  it('lists the shares of a table and of a view', async () => {
    const calls = serve(() => [
      share(),
      share({ id: 2, receiverType: 'group', receiver: 'staff', receiverDisplayName: '' }),
    ]);
    const result = await listTableSharesTool.handler({ tableId: 15 });
    expect(calls[0].url).toBe(`${API1}/tables/15/shares`);
    expect(result.content[0].text).toContain('[share 2] table 15 → group staff — read');
    serve(() => []);
    expect((await listTableSharesTool.handler({ viewId: 3 })).content[0].text).toBe(
      'Not shared with anyone.'
    );
  });

  it('changes permissions one request each, with the names the API takes', async () => {
    const calls = serve(() => share({ permissionUpdate: true }));
    const result = await updateTableShareTool.handler({
      shareId: 1,
      permissions: { update: true, create: false },
    });
    expect(calls.map((c) => [c.url, c.method, c.body])).toEqual([
      [`${API1}/shares/1`, 'PUT', { permissionType: 'update', permissionValue: true }],
      [`${API1}/shares/1`, 'PUT', { permissionType: 'create', permissionValue: false }],
    ]);
    expect(result.content[0].text).toContain('read, update');
  });

  it('needs a permission to change', async () => {
    const calls = serve(() => share());
    expect((await updateTableShareTool.handler({ shareId: 1, permissions: {} })).isError).toBe(
      true
    );
    expect(calls).toHaveLength(0);
  });

  it('deletes a share', async () => {
    const calls = serve(() => share());
    await deleteTableShareTool.handler({ shareId: 4 });
    expect(calls[0]).toMatchObject({ url: `${API1}/shares/4`, method: 'DELETE' });
  });
});

describe('Tables applications (contexts)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'pw';
  });

  const context = {
    id: 1,
    name: 'CRM',
    iconName: 'briefcase',
    description: 'Customers',
    owner: 'alice',
    ownerType: 0,
  };

  it('creates one with the default icon, its nodes as id, type, permission bits and order', async () => {
    const calls = serve(() => ({
      ...context,
      iconName: 'apps',
      nodes: [
        { id: 7, contextId: 1, nodeId: 15, nodeType: 0, permissions: 5 },
        { id: 8, contextId: 1, nodeId: 3, nodeType: 1, permissions: 1 },
      ],
    }));
    const result = await saveTableContextTool.handler({
      name: 'CRM',
      nodes: [{ tableId: 15, permissions: { read: true, update: true } }, { viewId: 3 }],
    });
    expect(calls[0]).toEqual({
      url: `${OCS}/contexts`,
      method: 'POST',
      body: {
        name: 'CRM',
        iconName: 'apps',
        nodes: [
          { id: 15, type: 0, permissions: 5, order: 0 },
          { id: 3, type: 1, permissions: 1, order: 1 },
        ],
      },
    });
    // The create response lists nodes in camelCase.
    expect(result.content[0].text).toContain('- table 15: read, update');
    expect(result.content[0].text).toContain('- view 3: read');
  });

  it('changes only what is passed', async () => {
    const calls = serve(() => context);
    await saveTableContextTool.handler({ contextId: 1, name: 'CRM 2' });
    expect(calls[0]).toEqual({ url: `${OCS}/contexts/1`, method: 'PUT', body: { name: 'CRM 2' } });
  });

  it('needs a name to create one, and one of tableId and viewId per node', async () => {
    const calls = serve(() => context);
    expect((await saveTableContextTool.handler({})).isError).toBe(true);
    expect(
      (await saveTableContextTool.handler({ name: 'x', nodes: [{ tableId: 1, viewId: 2 }] }))
        .isError
    ).toBe(true);
    expect(calls).toHaveLength(0);
  });

  it('shows the nodes and shares GET sends, keyed and in snake_case', async () => {
    serve(() => ({
      ...context,
      nodes: { '1': { id: 1, node_id: 15, node_type: 0, permissions: 31 } },
      sharing: {
        '5': {
          share_id: 5,
          receiver: 'bob',
          receiver_type: 'user',
          receiver_display_name: 'Bob',
          display_mode: 1,
        },
      },
      pages: {},
    }));
    const text = (await getTableContextTool.handler({ contextId: 1 })).content[0].text;
    expect(text).toContain('[1] CRM (icon: briefcase, owner: alice)');
    expect(text).toContain('- table 15: read, create, update, delete, manage');
    expect(text).toContain('shared: [share 5] → user Bob, navigation shown to the receivers');
  });

  it('deletes one', async () => {
    const calls = serve(() => context);
    await deleteTableContextTool.handler({ contextId: 1 });
    expect(calls[0]).toMatchObject({ url: `${OCS}/contexts/1`, method: 'DELETE' });
  });
});
