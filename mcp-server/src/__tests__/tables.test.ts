// SPDX-License-Identifier: MIT

import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  deleteTableRowTool,
  getTableTool,
  importTableStructureTool,
  listTableRowsTool,
  listTablesTool,
  saveTableColumnTool,
  saveTableRowTool,
  saveTableTool,
  saveTableViewTool,
} from '../tools/apps/tables.js';

global.fetch = vi.fn();

const STATUS = {
  id: 2,
  title: 'Status',
  technicalName: 'column_2',
  type: 'selection',
  subtype: '',
  selectionOptions: [
    { id: 1, label: 'open' },
    { id: 2, label: 'done' },
  ],
};
const TAGS = {
  id: 3,
  title: 'Tags',
  type: 'selection',
  subtype: 'multi',
  selectionOptions: [
    { id: 1, label: 'red' },
    { id: 2, label: 'blue' },
  ],
};
const CUSTOMER = {
  id: 4,
  title: 'Customer',
  type: 'relation',
  subtype: '',
  customSettings: { relationType: 'table', targetId: 9, labelColumn: 7 },
};
const NOTE = { id: 5, title: 'Note', type: 'text', subtype: 'line' };
const COLUMNS = [STATUS, TAGS, CUSTOMER, NOTE];

type Handler = (url: string, init: { method: string; body?: string }) => unknown;

function serve(handler: Handler) {
  const calls: { url: string; method: string; body: unknown }[] = [];
  (global.fetch as ReturnType<typeof vi.fn>).mockImplementation(
    async (url: string, init: { method: string; body?: string }) => {
      calls.push({ url, method: init.method, body: init.body && JSON.parse(init.body) });
      const answer = handler(url, init);
      const data = url.includes('/ocs/')
        ? { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data: answer } }
        : answer;
      return {
        ok: true,
        status: 200,
        json: async () => data,
        text: async () => JSON.stringify(data),
      };
    }
  );
  return calls;
}

const writes = (calls: { method: string }[]) => calls.filter((c) => c.method !== 'GET');

describe('Tables tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it('lists tables', async () => {
    serve(() => [{ id: 1, title: 'Orders', rowsCount: 3, views: [{ id: 2, title: 'Open' }] }]);

    const result = await listTablesTool.handler();

    expect(JSON.parse(result.content[0].text)[0]).toMatchObject({
      id: 1,
      rows: 3,
      views: [{ id: 2, title: 'Open' }],
    });
  });

  it('shows selection options and relation targets', async () => {
    serve((url) => {
      if (url.endsWith('/columns')) return COLUMNS;
      if (url.endsWith('/views')) return [];
      return { id: 1, title: 'Orders', rowsCount: 0 };
    });

    const result = await getTableTool.handler({ tableId: 1 });

    const { columns } = JSON.parse(result.content[0].text);
    expect(columns[0].options).toEqual(['open', 'done']);
    expect(columns[1].type).toBe('selection-multi');
    expect(columns[2].relation).toEqual({ type: 'table', targetId: 9, labelColumn: 7 });
  });

  it('needs exactly one of tableId and viewId', async () => {
    const calls = serve(() => []);

    const result = await listTableRowsTool.handler({ tableId: 1, viewId: 2 });

    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('exactly one');
    expect(calls).toEqual([]);
  });

  describe('rows', () => {
    const rows = [
      {
        id: 10,
        createdBy: 'alice',
        data: [
          { columnId: 2, value: '1' },
          { columnId: 3, value: '[1,2]' },
          { columnId: 4, value: '6' },
          { columnId: 5, value: 'hello' },
        ],
      },
    ];
    const serveRows = () =>
      serve((url) => {
        if (url.endsWith('/columns')) return COLUMNS;
        if (url.endsWith('/relations')) return { 4: { 6: { id: 6, label: 'Acme' } } };
        return rows;
      });

    it('returns titles, labels, and followable relations', async () => {
      serveRows();

      const result = await listTableRowsTool.handler({ tableId: 1 });

      expect(JSON.parse(result.content[0].text).rows[0].values).toEqual({
        Status: 'open',
        Tags: ['red', 'blue'],
        Customer: { id: 6, label: 'Acme', target: { type: 'table', id: 9 } },
        Note: 'hello',
      });
    });

    it('selects columns and pages', async () => {
      const calls = serveRows();

      const result = await listTableRowsTool.handler({
        viewId: 5,
        columns: ['Note'],
        limit: 1,
        offset: 2,
      });

      const output = JSON.parse(result.content[0].text);
      expect(output.rows[0].values).toEqual({ Note: 'hello' });
      expect(output.nextOffset).toBe(3);
      expect(calls.find((c) => c.url.includes('/rows'))?.url).toContain(
        '/views/5/rows?limit=1&offset=2'
      );
    });

    it('caps the page size', async () => {
      const calls = serveRows();

      const result = await listTableRowsTool.handler({ tableId: 1, limit: 5000 });

      expect(JSON.parse(result.content[0].text).limit).toBe(200);
      expect(calls.find((c) => c.url.includes('/rows'))?.url).toContain('limit=200');
    });

    it('maps titles to IDs and labels to option IDs', async () => {
      const calls = serve((url, init) => (init.method === 'GET' ? COLUMNS : { id: 11 }));

      await saveTableRowTool.handler({
        tableId: 1,
        values: { Status: 'done', Tags: ['blue'], Customer: 'Acme', 5: 'by id' },
      });

      expect(writes(calls)[0].body).toEqual({
        data: { 2: 2, 3: [2], 4: 'Acme', 5: 'by id' },
      });
    });

    it('refuses an unknown selection label, which the server would store as null', async () => {
      const calls = serve(() => COLUMNS);

      const result = await saveTableRowTool.handler({ tableId: 1, values: { Status: 'nope' } });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Options: open, done');
      expect(writes(calls)).toEqual([]);
    });

    it('refuses an unknown column', async () => {
      const calls = serve(() => COLUMNS);

      const result = await saveTableRowTool.handler({ tableId: 1, values: { Nope: 1 } });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No column "Nope"');
      expect(writes(calls)).toEqual([]);
    });

    it('refuses a title two columns share', async () => {
      const calls = serve(() => [NOTE, { ...NOTE, id: 6 }]);

      const result = await saveTableRowTool.handler({ tableId: 1, values: { Note: 'x' } });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('IDs 5, 6');
      expect(writes(calls)).toEqual([]);
    });

    it('updates a row', async () => {
      const calls = serve((url, init) => (init.method === 'GET' ? COLUMNS : { id: 10 }));

      await saveTableRowTool.handler({ tableId: 1, rowId: 10, values: { Note: 'x' } });

      expect(writes(calls)[0]).toMatchObject({ method: 'PUT' });
      expect(writes(calls)[0].url).toMatch(/\/rows\/10$/);
    });

    it('deletes through a view when given one', async () => {
      const calls = serve(() => ({}));

      await deleteTableRowTool.handler({ rowId: 10, viewId: 5 });

      expect(calls[0].url).toMatch(/\/views\/5\/rows\/10$/);
    });

    it('reports the reason the server gives', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 400,
        statusText: 'Bad request',
        text: async () => '{"message":"Relation value does not exist"}',
      });

      const result = await deleteTableRowTool.handler({ rowId: 1 });

      expect(result.content[0].text).toContain('Relation value does not exist');
    });
  });

  describe('columns', () => {
    it('defaults a text column to a line, and numbers selection options', async () => {
      const calls = serve(() => ({ id: 1, title: 'x', type: 'text' }));

      await saveTableColumnTool.handler({ tableId: 1, title: 'A', type: 'text' });
      await saveTableColumnTool.handler({
        tableId: 1,
        title: 'B',
        type: 'selection',
        options: ['a', 'b'],
      });

      expect(calls[0].body).toMatchObject({ subtype: 'line', mandatory: false });
      expect(calls[1].body).toMatchObject({
        selectionOptions: '[{"id":1,"label":"a"},{"id":2,"label":"b"}]',
      });
    });

    it('resolves the relation label column by title', async () => {
      const calls = serve((url, init) =>
        init.method === 'GET' ? [{ id: 7, title: 'Name', type: 'text' }] : CUSTOMER
      );

      await saveTableColumnTool.handler({
        tableId: 1,
        title: 'Customer',
        type: 'relation',
        relation: { type: 'table', targetId: 9, labelColumn: 'Name' },
      });

      expect(calls[0].url).toMatch(/\/tables\/9\/columns$/);
      expect(writes(calls)[0].body).toMatchObject({
        customSettings: { relationType: 'table', targetId: 9, labelColumn: 7 },
      });
    });

    // PUT /columns/{id} resets whatever it is not sent, with a 200.
    it('sends the current settings back on update, so a rename keeps a relation', async () => {
      const current = {
        ...CUSTOMER,
        technicalName: 'column_4',
        numberMin: 0,
        description: 'kept',
        usergroupDefault: [{ id: 'a' }],
        showUserStatus: true,
      };
      const calls = serve((url, init) => (init.method === 'GET' ? current : current));

      await saveTableColumnTool.handler({ columnId: 4, title: 'Client' });

      expect(writes(calls)[0].body).toMatchObject({
        title: 'Client',
        technicalName: 'column_4',
        numberMin: 0,
        description: 'kept',
        customSettings: CUSTOMER.customSettings,
        usergroupDefault: '[{"id":"a"}]',
        usergroupShowUserStatus: true,
      });
    });

    it('keeps the IDs of existing options, which rows refer to', async () => {
      const calls = serve(() => STATUS);

      await saveTableColumnTool.handler({ columnId: 2, options: ['done', 'held'] });

      const stored = JSON.parse(
        (writes(calls)[0].body as { selectionOptions: string }).selectionOptions
      );
      expect(stored).toEqual([
        { id: 2, label: 'done' },
        { id: 3, label: 'held' },
      ]);
    });

    it('needs a title and type to create', async () => {
      const result = await saveTableColumnTool.handler({ tableId: 1, title: 'A' });

      expect(result.isError).toBe(true);
    });
  });

  describe('views', () => {
    it('stores filter columns as IDs and selection values as option references', async () => {
      const calls = serve((url, init) => {
        if (init.method === 'GET') return COLUMNS;
        return { id: 8 };
      });

      const result = await saveTableViewTool.handler({
        tableId: 1,
        title: 'Open',
        columns: ['Note', 'Status'],
        filter: [[{ column: 'Status', operator: 'is-equal', value: 'open' }]],
      });

      expect(writes(calls)[1].body).toEqual({
        data: {
          columnSettings: [
            { columnId: 5, order: 0 },
            { columnId: 2, order: 1 },
          ],
          filter: [[{ columnId: 2, operator: 'is-equal', value: '@selection-id-1' }]],
        },
      });
      expect(result.content[0].text).toContain('View created: 8');
    });

    it('creates nothing when a filter names an unknown column', async () => {
      const calls = serve(() => COLUMNS);

      const result = await saveTableViewTool.handler({
        tableId: 1,
        title: 'V',
        filter: [[{ column: 'Nope', operator: 'is-equal', value: 'x' }]],
      });

      expect(result.isError).toBe(true);
      expect(writes(calls)).toEqual([]);
    });
  });

  describe('structure', () => {
    it('fills in the null emoji and description of an export', async () => {
      const calls = serve(() => ({ id: 3 }));

      await importTableStructureTool.handler({
        structure: { title: 'Copy', emoji: null, description: null, columns: [] },
      });

      expect(calls[0].url).toMatch(/\/tables\/scheme$/);
      expect(calls[0].body).toMatchObject({ emoji: '', description: '' });
    });

    const changes = {
      title: { from: 'a', to: 'b' },
      emoji: { from: null, to: null },
      description: { from: '', to: '' },
      columns: { addColumns: {}, modifyColumns: {}, removeColumns: {} },
      views: {},
    };

    it('previews without changing anything', async () => {
      const calls = serve(() => changes);

      await importTableStructureTool.handler({ tableId: 1, structure: {}, preview: true });

      expect(calls).toHaveLength(1);
      expect(calls[0].url).toContain('/scheme/preview-changes');
      expect(calls[0].body).toEqual({ updateScheme: {} });
    });

    it('applies the computed changes, which is what the import endpoint takes', async () => {
      const calls = serve(() => changes);

      await importTableStructureTool.handler({ tableId: 1, structure: {} });

      expect(calls[1].url).toMatch(/\/tables\/1\/scheme\/import$/);
      expect(calls[1].body).toMatchObject({
        title: 'b',
        emoji: '',
        columns: changes.columns,
      });
    });

    it('needs a table to preview', async () => {
      const result = await importTableStructureTool.handler({ structure: {}, preview: true });

      expect(result.isError).toBe(true);
    });
  });

  it('needs a title to create a table', async () => {
    const result = await saveTableTool.handler({});

    expect(result.isError).toBe(true);
  });
});
