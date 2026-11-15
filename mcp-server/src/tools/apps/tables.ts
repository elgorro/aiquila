// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { fetchTablesAPI } from '../../client/tables.js';

/**
 * Nextcloud Tables Tools
 *
 * Rows are stored as column ID and raw value pairs, which a model cannot
 * follow, so these tools take column titles and return labels.
 */

const OCS_API = '/ocs/v2.php/apps/tables/api/2';

const DEFAULT_ROW_LIMIT = 50;
const MAX_ROW_LIMIT = 200;

// What PUT /columns/{id} resets when left out, without an error. A relation
// loses its target this way, so an update always sends them all back.
const COLUMN_SETTINGS = [
  'title',
  'technicalName',
  'subtype',
  'mandatory',
  'description',
  'numberPrefix',
  'numberSuffix',
  'numberDefault',
  'numberMin',
  'numberMax',
  'numberDecimals',
  'textDefault',
  'textAllowedPattern',
  'textMaxLength',
  'textUnique',
  'selectionDefault',
  'datetimeDefault',
  'usergroupMultipleItems',
  'usergroupSelectUsers',
  'usergroupSelectGroups',
  'usergroupSelectTeams',
  'customSettings',
] as const;

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface SelectionOption {
  id: number;
  label: string;
}

interface Column {
  id: number;
  title: string;
  technicalName?: string;
  type: string;
  subtype?: string;
  mandatory?: boolean;
  description?: string;
  selectionOptions?: SelectionOption[] | string | null;
  customSettings?: Record<string, unknown> | string | null;
}

interface Row {
  id: number;
  createdBy?: string | null;
  createdAt?: string | null;
  lastEditBy?: string | null;
  lastEditAt?: string | null;
  data: { columnId: number; value: unknown }[];
}

interface TableSummary {
  id: number;
  title: string;
  emoji?: string | null;
  ownership?: string;
  rowsCount?: number;
  columnsCount?: number;
  isShared?: boolean;
  views?: ViewSummary[] | null;
}

interface ViewSummary {
  id: number;
  title: string;
  filter?: unknown;
  rowsCount?: number;
}

interface SchemeChanges {
  title: { to: string };
  emoji: { to: string | null };
  description: { to: string | null };
}

/** relation column ID → target row ID → label */
type Relations = Record<string, Record<string, { id: number; label: string }>>;

/** Either a table or one of its views, which have the same endpoints. */
type Node = { tableId?: number; viewId?: number };

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json(value: unknown) {
  return { content: [{ type: 'text' as const, text: JSON.stringify(value, null, 2) }] };
}

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(msg: string) {
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function wrapError(action: string, err: unknown) {
  return error(`Error ${action}: ${err instanceof Error ? err.message : String(err)}`);
}

/** A mistake in the tool's arguments, reported as it is. */
class InputError extends Error {}

function report(action: string, err: unknown) {
  return err instanceof InputError ? error(err.message) : wrapError(action, err);
}

/** The API sends these as JSON, or as a string holding JSON, depending on the endpoint. */
function parsed<T>(value: T | string | null | undefined): T | undefined {
  if (typeof value !== 'string') return value ?? undefined;
  try {
    return JSON.parse(value) as T;
  } catch {
    return undefined;
  }
}

function selectionOptions(column: Column): SelectionOption[] {
  return parsed<SelectionOption[]>(column.selectionOptions) ?? [];
}

function isSelection(column: Column): boolean {
  return column.type === 'selection' && column.subtype !== 'check';
}

function nodePath(node: Node): string {
  if ((node.tableId === undefined) === (node.viewId === undefined)) {
    throw new InputError('Pass exactly one of tableId and viewId.');
  }
  return node.viewId !== undefined ? `/views/${node.viewId}` : `/tables/${node.tableId}`;
}

function findColumn(columns: Column[], key: string | number): Column {
  const name = String(key);
  const matches = columns.filter(
    (c) => String(c.id) === name || c.title === name || c.technicalName === name
  );
  if (matches.length === 1) return matches[0];
  if (matches.length > 1) {
    throw new InputError(
      `Several columns match "${name}" (IDs ${matches.map((c) => c.id).join(', ')}). Use the ID.`
    );
  }
  throw new InputError(
    `No column "${name}". Columns: ${columns.map((c) => c.title).join(', ') || 'none'}.`
  );
}

function optionFor(column: Column, value: unknown): SelectionOption {
  const options = selectionOptions(column);
  const option = options.find((o) => o.label === value || o.id === value);
  if (option) return option;
  throw new InputError(
    `"${String(value)}" is not an option of ${column.title}. Options: ${options.map((o) => o.label).join(', ')}.`
  );
}

/**
 * The stored form of a value. A selection needs its option ID: the server
 * accepts a label with 200 and stores null.
 */
function toStored(column: Column, value: unknown): unknown {
  if (value === null || !isSelection(column)) return value;
  if (Array.isArray(value)) return value.map((v) => optionFor(column, v).id);
  return optionFor(column, value).id;
}

function toDisplay(column: Column, value: unknown, relations: Relations): unknown {
  if (value === null || value === undefined || value === '') return value;
  if (column.type === 'relation') {
    const settings = parsed<Record<string, unknown>>(column.customSettings);
    const row = relations[column.id]?.[String(value)];
    return {
      id: Number(value),
      label: row?.label ?? null,
      target: { type: settings?.relationType, id: settings?.targetId },
    };
  }
  if (isSelection(column)) {
    const ids = parsed<unknown>(typeof value === 'string' ? value : JSON.stringify(value));
    const label = (id: unknown) =>
      selectionOptions(column).find((o) => o.id === Number(id))?.label ?? id;
    return Array.isArray(ids) ? ids.map(label) : label(ids);
  }
  return value;
}

function displayColumn(column: Column) {
  const options = isSelection(column) ? selectionOptions(column).map((o) => o.label) : undefined;
  const relation =
    column.type === 'relation' ? parsed<Record<string, unknown>>(column.customSettings) : undefined;
  return {
    id: column.id,
    title: column.title,
    alias: column.technicalName,
    type: column.subtype ? `${column.type}-${column.subtype}` : column.type,
    mandatory: column.mandatory,
    description: column.description || undefined,
    options,
    relation: relation && {
      type: relation.relationType,
      targetId: relation.targetId,
      labelColumn: relation.labelColumn,
    },
  };
}

/** A column's settings in the form PUT /columns/{id} takes them. */
function keptSettings(column: Column | undefined): Record<string, unknown> {
  const current = (column ?? {}) as unknown as Record<string, unknown>;
  const kept = Object.fromEntries(COLUMN_SETTINGS.map((key) => [key, current[key]]));
  // Read as a list, written as a string, and named differently on the way in.
  kept.usergroupDefault =
    current.usergroupDefault == null ? null : JSON.stringify(current.usergroupDefault);
  kept.usergroupShowUserStatus = current.showUserStatus;
  return kept;
}

function columnsOf(node: Node): Promise<Column[]> {
  return fetchTablesAPI<Column[]>(`${nodePath(node)}/columns`);
}

function rowData(columns: Column[], values: Record<string, unknown>): Record<string, unknown> {
  const data: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(values)) {
    const column = findColumn(columns, key);
    data[column.id] = toStored(column, value);
  }
  return data;
}

/** A view filter with titles and labels turned into what the server stores. */
function storedFilter(columns: Column[], filter: FilterRule[][]) {
  return filter.map((group) =>
    group.map(({ column: key, operator, value }) => {
      const column = findColumn(columns, key);
      return {
        columnId: column.id,
        operator,
        value: isSelection(column) ? `@selection-id-${optionFor(column, value).id}` : value,
      };
    })
  );
}

function storedOptions(labels: string[], existing: SelectionOption[] = []): string {
  let nextId = Math.max(0, ...existing.map((o) => o.id)) + 1;
  return JSON.stringify(
    labels.map(
      (label) => existing.find((o) => o.label === label) ?? { id: nextId++, label: String(label) }
    )
  );
}

// ---------------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------------

export const listTablesTool = {
  name: 'list_tables',
  title: 'List Tables',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List the Nextcloud Tables the user can access, with their views and row counts.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const data = await fetchOCS<TableSummary[]>(`${OCS_API}/tables`);
      const tables = data.ocs.data ?? [];
      if (tables.length === 0) return text('No tables found.');
      return json(
        tables.map((t) => ({
          id: t.id,
          title: t.title,
          emoji: t.emoji ?? undefined,
          owner: t.ownership,
          rows: t.rowsCount,
          columns: t.columnsCount,
          shared: t.isShared,
          views: t.views?.map((v) => ({ id: v.id, title: v.title })),
        }))
      );
    } catch (err) {
      return wrapError('listing tables', err);
    }
  },
};

export const getTableTool = {
  name: 'get_table',
  title: 'Get Table',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get the columns of a table or view, with selection options and relation targets. For a table, also its views. Read this before writing rows.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table ID'),
    viewId: z.number().optional().describe('View ID, instead of tableId'),
  }),
  handler: async (args: { tableId?: number; viewId?: number }) => {
    try {
      const path = nodePath(args);
      const [node, columns, views] = await Promise.all([
        fetchTablesAPI<TableSummary>(path),
        columnsOf(args),
        args.tableId !== undefined
          ? fetchTablesAPI<ViewSummary[]>(`${path}/views`)
          : Promise.resolve(undefined),
      ]);
      return json({
        id: node.id,
        title: node.title,
        rows: node.rowsCount,
        columns: columns.map(displayColumn),
        views: views?.map((v) => ({
          id: v.id,
          title: v.title,
          filter: parsed(v.filter as string),
        })),
      });
    } catch (err) {
      return report('getting table', err);
    }
  },
};

export const saveTableTool = {
  name: 'save_table',
  title: 'Save Table',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Create a table, or change one when tableId is given.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table to change. Omit to create one'),
    title: z.string().optional().describe('Title (required to create)'),
    emoji: z.string().optional().describe('Emoji shown with the title'),
    description: z.string().optional().describe('Description'),
    archived: z.boolean().optional().describe('Archive the table (existing tables)'),
  }),
  handler: async (args: {
    tableId?: number;
    title?: string;
    emoji?: string;
    description?: string;
    archived?: boolean;
  }) => {
    const { tableId, ...fields } = args;
    if (tableId === undefined && !args.title) return error('title is required to create a table.');
    try {
      const data = await fetchOCS<TableSummary>(
        tableId === undefined ? `${OCS_API}/tables` : `${OCS_API}/tables/${tableId}`,
        { method: tableId === undefined ? 'POST' : 'PUT', jsonBody: fields }
      );
      const table = data.ocs.data;
      return text(
        `Table ${tableId === undefined ? 'created' : 'updated'}: [${table.id}] ${table.title}`
      );
    } catch (err) {
      return wrapError('saving table', err);
    }
  },
};

export const deleteTableTool = {
  name: 'delete_table',
  title: 'Delete Table',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a table with all its rows, columns and views. This cannot be undone.',
  inputSchema: z.object({ tableId: z.number().describe('Table ID') }),
  handler: async (args: { tableId: number }) => {
    try {
      await fetchOCS(`${OCS_API}/tables/${args.tableId}`, { method: 'DELETE' });
      return text(`Table ${args.tableId} deleted.`);
    } catch (err) {
      return wrapError('deleting table', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Structure import and export
// ---------------------------------------------------------------------------

export const exportTableStructureTool = {
  name: 'export_table_structure',
  title: 'Export Table Structure',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Export the structure of a table (columns and views, no rows) as JSON for import_table_structure.',
  inputSchema: z.object({ tableId: z.number().describe('Table ID') }),
  handler: async (args: { tableId: number }) => {
    try {
      const data = await fetchOCS(`${OCS_API}/tables/scheme/${args.tableId}`);
      return json(data.ocs.data);
    } catch (err) {
      return wrapError('exporting table structure', err);
    }
  },
};

export const importTableStructureTool = {
  name: 'import_table_structure',
  title: 'Import Table Structure',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a table from a structure exported with export_table_structure, or apply one to an existing table. Applying removes columns and views the structure does not have, along with their data, so use preview first.',
  inputSchema: z.object({
    structure: z.record(z.string(), z.unknown()).describe('The exported structure'),
    tableId: z.number().optional().describe('Apply to this table. Omit to create a new one'),
    preview: z
      .boolean()
      .optional()
      .describe('With tableId: report the changes without making them'),
  }),
  handler: async (args: {
    structure: Record<string, unknown>;
    tableId?: number;
    preview?: boolean;
  }) => {
    if (args.preview && args.tableId === undefined) return error('preview needs a tableId.');
    try {
      if (args.tableId === undefined) {
        const data = await fetchOCS<TableSummary>(`${OCS_API}/tables/scheme`, {
          method: 'POST',
          // An export has null for an empty emoji or description, an import needs strings.
          jsonBody: {
            ...args.structure,
            emoji: args.structure.emoji ?? '',
            description: args.structure.description ?? '',
          },
        });
        return text(`Table created from structure: ${data.ocs.data.id}`);
      }

      // The import endpoint takes the computed changes, not an export.
      const changes = await fetchOCS<SchemeChanges>(
        `${OCS_API}/tables/${args.tableId}/scheme/preview-changes`,
        { method: 'POST', jsonBody: { updateScheme: args.structure } }
      );
      if (args.preview) return json(changes.ocs.data);

      const { title, emoji, description, ...rest } = changes.ocs.data;
      await fetchOCS(`${OCS_API}/tables/${args.tableId}/scheme/import`, {
        method: 'POST',
        jsonBody: {
          ...rest,
          title: title.to,
          emoji: emoji.to ?? '',
          description: description.to ?? '',
        },
      });
      return text(`Table structure applied to table ${args.tableId}.`);
    } catch (err) {
      return wrapError('importing table structure', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------

export const saveTableColumnTool = {
  name: 'save_table_column',
  title: 'Save Table Column',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add a column to a table, or change one when columnId is given. A relation column links to the rows of another table or view.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table to add the column to'),
    columnId: z.number().optional().describe('Column to change, instead of tableId'),
    title: z.string().optional().describe('Column title (required to create)'),
    type: z
      .enum(['text', 'number', 'datetime', 'selection', 'usergroup', 'relation'])
      .optional()
      .describe('Column type (required to create)'),
    subtype: z
      .string()
      .optional()
      .describe(
        "Variant: text 'line', 'long', 'rich' or 'link'; number 'stars' or 'progress'; datetime 'date' or 'time'; selection 'multi' or 'check'"
      ),
    mandatory: z.boolean().optional().describe('Rows must have a value (default false)'),
    description: z.string().optional().describe('Column description'),
    options: z.array(z.string()).optional().describe('Labels of a selection column'),
    relation: z
      .object({
        type: z.enum(['table', 'view']).describe('What the column links to'),
        targetId: z.number().describe('ID of that table or view'),
        labelColumn: z
          .union([z.number(), z.string()])
          .describe('Column of the target that names a row, by title or ID'),
      })
      .optional()
      .describe('Target of a relation column'),
    settings: z
      .record(z.string(), z.unknown())
      .optional()
      .describe('Other Tables column parameters, e.g. numberMin, textMaxLength'),
  }),
  handler: async (args: {
    tableId?: number;
    columnId?: number;
    title?: string;
    type?: string;
    subtype?: string;
    mandatory?: boolean;
    description?: string;
    options?: string[];
    relation?: { type: 'table' | 'view'; targetId: number; labelColumn: number | string };
    settings?: Record<string, unknown>;
  }) => {
    const { tableId, columnId } = args;
    if ((tableId === undefined) === (columnId === undefined)) {
      return error('Pass exactly one of tableId and columnId.');
    }
    if (tableId !== undefined && (!args.title || !args.type)) {
      return error('title and type are required to create a column.');
    }

    try {
      const body: Record<string, unknown> = { ...args.settings };
      if (args.title !== undefined) body.title = args.title;
      if (args.subtype !== undefined) body.subtype = args.subtype;
      if (args.mandatory !== undefined) body.mandatory = args.mandatory;
      if (args.description !== undefined) body.description = args.description;

      const current =
        columnId !== undefined ? await fetchTablesAPI<Column>(`/columns/${columnId}`) : undefined;
      if (args.options) {
        body.selectionOptions = storedOptions(
          args.options,
          current ? selectionOptions(current) : []
        );
      }
      if (args.relation) {
        const { type, targetId, labelColumn } = args.relation;
        const target = await columnsOf(
          type === 'table' ? { tableId: targetId } : { viewId: targetId }
        );
        body.customSettings = {
          relationType: type,
          targetId,
          labelColumn: findColumn(target, labelColumn).id,
        };
      }

      let column: Column;
      if (tableId !== undefined) {
        body.type = args.type;
        body.mandatory ??= false;
        if (args.type === 'text') body.subtype ??= 'line';
        column = await fetchTablesAPI<Column>(`/tables/${tableId}/columns`, {
          method: 'POST',
          body,
        });
      } else {
        column = await fetchTablesAPI<Column>(`/columns/${columnId}`, {
          method: 'PUT',
          body: { ...keptSettings(current), ...body },
        });
      }
      return json(displayColumn(column));
    } catch (err) {
      return report('saving column', err);
    }
  },
};

export const deleteTableColumnTool = {
  name: 'delete_table_column',
  title: 'Delete Table Column',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a column and its value in every row. This cannot be undone.',
  inputSchema: z.object({ columnId: z.number().describe('Column ID') }),
  handler: async (args: { columnId: number }) => {
    try {
      await fetchTablesAPI(`/columns/${args.columnId}`, { method: 'DELETE' });
      return text(`Column ${args.columnId} deleted.`);
    } catch (err) {
      return wrapError('deleting column', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

interface FilterRule {
  column: string | number;
  operator: string;
  value: string | number | boolean;
}

export const saveTableViewTool = {
  name: 'save_table_view',
  title: 'Save Table View',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a view of a table, or change one when viewId is given. A view picks columns and filters rows, and list_table_rows can read it.',
  inputSchema: z.object({
    tableId: z.number().describe('Table the view belongs to'),
    viewId: z.number().optional().describe('View to change. Omit to create one'),
    title: z.string().optional().describe('View title (required to create)'),
    description: z.string().optional().describe('View description'),
    columns: z
      .array(z.union([z.string(), z.number()]))
      .optional()
      .describe('Columns to show, in order, by title or ID'),
    filter: z
      .array(
        z.array(
          z.object({
            column: z.union([z.string(), z.number()]).describe('Column title or ID'),
            operator: z
              .enum([
                'contains',
                'does-not-contain',
                'begins-with',
                'ends-with',
                'is-equal',
                'is-not-equal',
                'is-greater-than',
                'is-greater-than-or-equal',
                'is-lower-than',
                'is-lower-than-or-equal',
                'is-empty',
              ])
              .describe('Comparison'),
            value: z
              .union([z.string(), z.number(), z.boolean()])
              .describe('Value, a label for selection columns'),
          })
        )
      )
      .optional()
      .describe('Rows to show: all rules of an inner list must match, any one list is enough'),
  }),
  handler: async (args: {
    tableId: number;
    viewId?: number;
    title?: string;
    description?: string;
    columns?: (string | number)[];
    filter?: FilterRule[][];
  }) => {
    if (args.viewId === undefined && !args.title) {
      return error('title is required to create a view.');
    }
    try {
      const data: Record<string, unknown> = {};
      if (args.columns || args.filter) {
        const columns = await columnsOf({ tableId: args.tableId });
        if (args.columns) {
          data.columnSettings = args.columns.map((key, order) => ({
            columnId: findColumn(columns, key).id,
            order,
          }));
        }
        if (args.filter) data.filter = storedFilter(columns, args.filter);
      }
      if (args.viewId !== undefined) {
        if (args.title !== undefined) data.title = args.title;
        if (args.description !== undefined) data.description = args.description;
      }

      let id = args.viewId;
      if (id === undefined) {
        const created = await fetchTablesAPI<ViewSummary>(`/tables/${args.tableId}/views`, {
          method: 'POST',
          body: { title: args.title },
        });
        id = created.id;
        if (args.description !== undefined) data.description = args.description;
      }
      if (Object.keys(data).length > 0) {
        await fetchTablesAPI(`/views/${id}`, { method: 'PUT', body: { data } });
      }
      return text(`View ${args.viewId === undefined ? 'created' : 'updated'}: ${id}`);
    } catch (err) {
      return report('saving view', err);
    }
  },
};

export const deleteTableViewTool = {
  name: 'delete_table_view',
  title: 'Delete Table View',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a view. The rows of the table are kept.',
  inputSchema: z.object({ viewId: z.number().describe('View ID') }),
  handler: async (args: { viewId: number }) => {
    try {
      await fetchTablesAPI(`/views/${args.viewId}`, { method: 'DELETE' });
      return text(`View ${args.viewId} deleted.`);
    } catch (err) {
      return wrapError('deleting view', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Rows
// ---------------------------------------------------------------------------

export const listTableRowsTool = {
  name: 'list_table_rows',
  title: 'List Table Rows',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List rows of a table or view, a page at a time. Values are keyed by column title, selections are labels, and a relation is {id, label, target}. To filter rows, read a view that has the filter (save_table_view).',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table ID'),
    viewId: z.number().optional().describe('View ID, instead of tableId'),
    columns: z
      .array(z.union([z.string(), z.number()]))
      .optional()
      .describe('Only these columns, by title or ID'),
    limit: z
      .number()
      .optional()
      .describe(`Rows per page (default ${DEFAULT_ROW_LIMIT}, max ${MAX_ROW_LIMIT})`),
    offset: z.number().optional().describe('Rows to skip (default 0)'),
  }),
  handler: async (args: {
    tableId?: number;
    viewId?: number;
    columns?: (string | number)[];
    limit?: number;
    offset?: number;
  }) => {
    const limit = Math.min(args.limit ?? DEFAULT_ROW_LIMIT, MAX_ROW_LIMIT);
    const offset = args.offset ?? 0;
    try {
      const path = nodePath(args);
      const [columns, relations, rows] = await Promise.all([
        columnsOf(args),
        fetchTablesAPI<Relations>(`${path}/relations`),
        fetchTablesAPI<Row[]>(`${path}/rows`, {
          queryParams: { limit: String(limit), offset: String(offset) },
        }),
      ]);
      const shown = (args.columns ?? columns.map((c) => c.id)).map((k) => findColumn(columns, k));

      return json({
        offset,
        limit,
        nextOffset: rows.length >= limit ? offset + limit : undefined,
        rows: rows.map((row) => ({
          id: row.id,
          createdBy: row.createdBy ?? undefined,
          lastEditAt: row.lastEditAt ?? undefined,
          values: Object.fromEntries(
            shown.map((column) => [
              column.title,
              toDisplay(
                column,
                row.data.find((d) => d.columnId === column.id)?.value ?? null,
                relations
              ),
            ])
          ),
        })),
      });
    } catch (err) {
      return report('listing rows', err);
    }
  },
};

export const saveTableRowTool = {
  name: 'save_table_row',
  title: 'Save Table Row',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add a row to a table or view, or change one when rowId is given. Keys of values are column titles. Use labels for selection columns, and a row ID or label for relation columns.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table ID'),
    viewId: z.number().optional().describe('View ID, instead of tableId'),
    rowId: z.number().optional().describe('Row to change. Omit to add one'),
    values: z.record(z.string(), z.unknown()).describe('Column title or ID, and the value'),
  }),
  handler: async (args: {
    tableId?: number;
    viewId?: number;
    rowId?: number;
    values: Record<string, unknown>;
  }) => {
    try {
      const path = nodePath(args);
      const data = rowData(await columnsOf(args), args.values);
      const row =
        args.rowId === undefined
          ? await fetchTablesAPI<Row>(`${path}/rows`, { method: 'POST', body: { data } })
          : await fetchTablesAPI<Row>(`/rows/${args.rowId}`, {
              method: 'PUT',
              body: { data, viewId: args.viewId },
            });
      return text(`Row ${args.rowId === undefined ? 'added' : 'updated'}: ${row.id}`);
    } catch (err) {
      return report('saving row', err);
    }
  },
};

export const deleteTableRowTool = {
  name: 'delete_table_row',
  title: 'Delete Table Row',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a row. This cannot be undone.',
  inputSchema: z.object({
    rowId: z.number().describe('Row ID'),
    viewId: z
      .number()
      .optional()
      .describe('The view the row is read through, without table access'),
  }),
  handler: async (args: { rowId: number; viewId?: number }) => {
    try {
      await fetchTablesAPI(
        args.viewId !== undefined
          ? `/views/${args.viewId}/rows/${args.rowId}`
          : `/rows/${args.rowId}`,
        { method: 'DELETE' }
      );
      return text(`Row ${args.rowId} deleted.`);
    } catch (err) {
      return wrapError('deleting row', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Sharing
// ---------------------------------------------------------------------------

// Tables API v1 share object (ShareService::formatForOutput).
interface TableShare {
  id: number;
  sender?: string;
  receiver: string;
  receiverDisplayName?: string;
  receiverType: string;
  nodeId: number;
  nodeType: string;
  permissionRead?: boolean;
  permissionCreate?: boolean;
  permissionUpdate?: boolean;
  permissionDelete?: boolean;
  permissionManage?: boolean;
}

// The names PUT /shares/{id} takes as permissionType (ShareService::applyPermissions),
// and the share fields they set.
const SHARE_PERMISSIONS = {
  read: 'permissionRead',
  create: 'permissionCreate',
  update: 'permissionUpdate',
  delete: 'permissionDelete',
  manage: 'permissionManage',
} as const;
type SharePermission = keyof typeof SHARE_PERMISSIONS;
type SharePermissions = Partial<Record<SharePermission, boolean>>;

const sharePermissionsSchema = z
  .object({
    read: z.boolean().optional().describe('See the rows'),
    create: z.boolean().optional().describe('Add rows'),
    update: z.boolean().optional().describe('Change rows'),
    delete: z.boolean().optional().describe('Delete rows'),
    manage: z.boolean().optional().describe('Change columns, views and shares'),
  })
  .describe('What the receiver may do. Leaving a permission out means no');

const RECEIVER_TYPES = ['user', 'group', 'circle', 'remote'] as const;

function sharedNode(args: { tableId?: number; viewId?: number; contextId?: number }): {
  nodeType: 'table' | 'view' | 'context';
  nodeId: number;
} {
  const given = [args.tableId, args.viewId, args.contextId].filter((v) => v !== undefined);
  if (given.length !== 1)
    throw new InputError('Pass exactly one of tableId, viewId and contextId.');
  if (args.tableId !== undefined) return { nodeType: 'table', nodeId: args.tableId };
  if (args.viewId !== undefined) return { nodeType: 'view', nodeId: args.viewId };
  return { nodeType: 'context', nodeId: args.contextId! };
}

function formatShare(share: TableShare): string {
  const allowed = (Object.keys(SHARE_PERMISSIONS) as SharePermission[]).filter(
    (p) => share[SHARE_PERMISSIONS[p]]
  );
  const who = share.receiverDisplayName || share.receiver;
  const perms = share.nodeType === 'context' ? '' : ` — ${allowed.join(', ') || 'no permissions'}`;
  return `[share ${share.id}] ${share.nodeType} ${share.nodeId} → ${share.receiverType} ${who}${perms}`;
}

export const listTableSharesTool = {
  name: 'list_table_shares',
  title: 'List Table Shares',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List who a table or view is shared with, and their permissions. Needs manage permission. The shares of an application (context) are in get_table_context.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table ID'),
    viewId: z.number().optional().describe('View ID'),
  }),
  handler: async (args: Node) => {
    try {
      const shares = await fetchTablesAPI<TableShare[]>(`${nodePath(args)}/shares`);
      if (shares.length === 0) return text('Not shared with anyone.');
      return text(`Shares (${shares.length}):\n${shares.map(formatShare).join('\n')}`);
    } catch (err) {
      return report('listing shares', err);
    }
  },
};

export const shareTableTool = {
  name: 'share_table',
  title: 'Share Table',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: true,
  },
  description:
    'Share a table, a view or an application (context) with a user, group, team or federated user. ' +
    "receiverType 'remote' shares a table or view with an account on another Nextcloud, by its Federated Cloud ID (user@cloud.example.com); Tables must allow federation on both sides. " +
    'An application share gives access to the application with the permissions set on its tables and views, so permissions do not apply to it.',
  inputSchema: z.object({
    tableId: z.number().optional().describe('Table to share'),
    viewId: z.number().optional().describe('View to share'),
    contextId: z.number().optional().describe('Application (context) to share'),
    receiver: z
      .string()
      .min(1)
      .describe('User ID, group ID, team (circle) ID, or Federated Cloud ID for remote'),
    receiverType: z.enum(RECEIVER_TYPES).describe("'circle' is a team"),
    permissions: sharePermissionsSchema
      .optional()
      .describe('For tables and views. Default: read only'),
    displayMode: z
      .number()
      .int()
      .min(0)
      .max(2)
      .optional()
      .describe(
        'Applications only: show it in the navigation bar 0 for nobody, 1 for the receivers, 2 for everyone (default)'
      ),
  }),
  handler: async (args: {
    tableId?: number;
    viewId?: number;
    contextId?: number;
    receiver: string;
    receiverType: (typeof RECEIVER_TYPES)[number];
    permissions?: SharePermissions;
    displayMode?: number;
  }) => {
    try {
      const { nodeType, nodeId } = sharedNode(args);
      if (args.receiverType === 'remote') {
        if (nodeType === 'context') {
          throw new InputError('Applications cannot be shared with a remote account.');
        }
        // A Federated Cloud ID is <user>@<host>, and the user part may hold an @ itself.
        if (!/^\S+@[^@\s]+$/.test(args.receiver)) {
          throw new InputError(
            'A remote receiver is a Federated Cloud ID, e.g. user@cloud.example.com.'
          );
        }
      }
      if (nodeType !== 'context' && args.displayMode !== undefined) {
        throw new InputError('displayMode applies to applications only.');
      }
      const perms = args.permissions ?? { read: true };
      const body: Record<string, unknown> = {
        nodeId,
        nodeType,
        receiver: args.receiver,
        receiverType: args.receiverType,
      };
      if (nodeType === 'context') {
        if (args.displayMode !== undefined) body.displayMode = args.displayMode;
      } else {
        for (const [p, field] of Object.entries(SHARE_PERMISSIONS)) {
          body[field] = perms[p as SharePermission] === true;
        }
      }
      try {
        const share = await fetchTablesAPI<TableShare>('/shares', { method: 'POST', body });
        return text(`Shared: ${formatShare(share)}`);
      } catch (err) {
        // Tables stores a remote share before telling the remote, and keeps it
        // when that fails.
        if (args.receiverType === 'remote') {
          return wrapError(
            'sharing',
            new Error(
              `${err instanceof Error ? err.message : String(err)}. Tables may have kept the share although the remote was not told: check list_table_shares and remove it with delete_table_share.`
            )
          );
        }
        throw err;
      }
    } catch (err) {
      return report('sharing', err);
    }
  },
};

export const updateTableShareTool = {
  name: 'update_table_share',
  title: 'Update Table Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    "Change the permissions of a table or view share. Only the permissions passed change. Application shares have none of their own. A remote share's receiver is told of the change.",
  inputSchema: z.object({
    shareId: z.number().describe('Share ID from list_table_shares'),
    permissions: sharePermissionsSchema,
  }),
  handler: async (args: { shareId: number; permissions: SharePermissions }) => {
    const changes = Object.entries(args.permissions).filter(([, v]) => v !== undefined);
    if (changes.length === 0) return error('Pass at least one permission to change.');
    try {
      // The API changes one permission per request.
      let share: TableShare | undefined;
      for (const [permissionType, permissionValue] of changes) {
        share = await fetchTablesAPI<TableShare>(`/shares/${args.shareId}`, {
          method: 'PUT',
          body: { permissionType, permissionValue },
        });
      }
      return text(`Share updated: ${formatShare(share!)}`);
    } catch (err) {
      return wrapError('updating share', err);
    }
  },
};

export const deleteTableShareTool = {
  name: 'delete_table_share',
  title: 'Delete Table Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: true,
  },
  description: 'Stop sharing a table, view or application with that receiver.',
  inputSchema: z.object({ shareId: z.number().describe('Share ID') }),
  handler: async (args: { shareId: number }) => {
    try {
      await fetchTablesAPI(`/shares/${args.shareId}`, { method: 'DELETE' });
      return text(`Share ${args.shareId} deleted.`);
    } catch (err) {
      return wrapError('deleting share', err);
    }
  },
};

// ---------------------------------------------------------------------------
// Applications (contexts)
// ---------------------------------------------------------------------------

// Node types and permission bits of the Tables API (AppInfo\Application).
const NODE_TYPE = { table: 0, view: 1 } as const;
const PERMISSION_BITS: Record<SharePermission, number> = {
  read: 1,
  create: 2,
  update: 4,
  delete: 8,
  manage: 16,
};

// GET /contexts/{id} sends nodes keyed by relation id, in snake_case; the
// create and update responses send a list in camelCase. Both are read.
interface ContextNode {
  id?: number;
  node_id?: number;
  node_type?: number;
  nodeId?: number;
  nodeType?: number;
  permissions?: number;
}

interface ContextShare {
  share_id: number;
  receiver: string;
  receiver_type: string;
  receiver_display_name?: string;
  display_mode?: number;
}

const DISPLAY_MODES = ['hidden', 'shown to the receivers', 'shown to everyone'];

interface Context {
  id: number;
  name: string;
  iconName: string;
  description: string;
  owner: string;
  ownerType: number;
  nodes?: Record<string, ContextNode> | ContextNode[];
  sharing?: Record<string, ContextShare> | ContextShare[];
  pages?: unknown;
}

function permissionBits(p: SharePermissions | undefined): number {
  const perms = p ?? { read: true };
  return (Object.keys(PERMISSION_BITS) as SharePermission[]).reduce(
    (bits, k) => (perms[k] ? bits | PERMISSION_BITS[k] : bits),
    0
  );
}

function permissionNames(bits: number | undefined): string {
  const names = (Object.keys(PERMISSION_BITS) as SharePermission[]).filter(
    (k) => ((bits ?? 0) & PERMISSION_BITS[k]) !== 0
  );
  return names.join(', ') || 'no permissions';
}

function formatContext(c: Context, detail = false): string {
  const lines = [`[${c.id}] ${c.name} (icon: ${c.iconName}, owner: ${c.owner})`];
  if (c.description) lines.push(`  ${c.description}`);
  if (detail) {
    const nodes = Object.values(c.nodes ?? {});
    for (const n of nodes) {
      const kind = (n.node_type ?? n.nodeType) === NODE_TYPE.view ? 'view' : 'table';
      lines.push(`  - ${kind} ${n.node_id ?? n.nodeId}: ${permissionNames(n.permissions)}`);
    }
    for (const sh of Object.values(c.sharing ?? {})) {
      const who = sh.receiver_display_name || sh.receiver;
      const nav = DISPLAY_MODES[sh.display_mode ?? 2] ?? `display mode ${sh.display_mode}`;
      lines.push(
        `  shared: [share ${sh.share_id}] → ${sh.receiver_type} ${who}, navigation ${nav}`
      );
    }
  }
  return lines.join('\n');
}

const contextNodesSchema = z
  .array(
    z.object({
      tableId: z.number().optional(),
      viewId: z.number().optional(),
      permissions: sharePermissionsSchema
        .optional()
        .describe('What users of the application may do here. Default: read only'),
    })
  )
  .describe('The tables and views the application shows, one of tableId and viewId each');

export const listTableContextsTool = {
  name: 'list_table_contexts',
  title: 'List Table Applications',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the Tables applications (contexts) you own or that are shared with you. An application bundles tables and views behind its own navigation entry.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const data = await fetchOCS<Context[]>(`${OCS_API}/contexts`);
      const contexts = data.ocs.data;
      if (contexts.length === 0) return text('No applications found.');
      return text(
        `Applications (${contexts.length}):\n${contexts.map((c) => formatContext(c)).join('\n')}`
      );
    } catch (err) {
      return wrapError('listing applications', err);
    }
  },
};

export const getTableContextTool = {
  name: 'get_table_context',
  title: 'Get Table Application',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get a Tables application (context): its tables and views with their permissions, and who it is shared with.',
  inputSchema: z.object({ contextId: z.number().describe('Application ID') }),
  handler: async (args: { contextId: number }) => {
    try {
      const data = await fetchOCS<Context>(`${OCS_API}/contexts/${args.contextId}`);
      return text(formatContext(data.ocs.data, true));
    } catch (err) {
      return wrapError('getting application', err);
    }
  },
};

export const saveTableContextTool = {
  name: 'save_table_context',
  title: 'Save Table Application',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a Tables application (context), or change one when contextId is given. Passing nodes replaces its tables and views. Share it with share_table and contextId.',
  inputSchema: z.object({
    contextId: z.number().optional().describe('Application to change. Omit to create one'),
    name: z.string().optional().describe('Name (required to create)'),
    iconName: z
      .string()
      .optional()
      .describe("Material Design icon name bundled with Tables, e.g. 'briefcase'. Default 'apps'"),
    description: z.string().optional(),
    nodes: contextNodesSchema.optional(),
  }),
  handler: async (args: {
    contextId?: number;
    name?: string;
    iconName?: string;
    description?: string;
    nodes?: { tableId?: number; viewId?: number; permissions?: SharePermissions }[];
  }) => {
    if (args.contextId === undefined && !args.name) {
      return error('name is required to create an application.');
    }
    try {
      const nodes = args.nodes?.map((n, order) => {
        if ((n.tableId === undefined) === (n.viewId === undefined)) {
          throw new InputError('Each node needs exactly one of tableId and viewId.');
        }
        return {
          id: n.viewId ?? n.tableId!,
          type: n.viewId !== undefined ? NODE_TYPE.view : NODE_TYPE.table,
          permissions: permissionBits(n.permissions),
          order,
        };
      });
      const create = args.contextId === undefined;
      const body: Record<string, unknown> = {};
      if (args.name !== undefined) body.name = args.name;
      if (args.iconName !== undefined || create) body.iconName = args.iconName ?? 'apps';
      if (args.description !== undefined) body.description = args.description;
      if (nodes !== undefined) body.nodes = nodes;
      const data = await fetchOCS<Context>(
        create ? `${OCS_API}/contexts` : `${OCS_API}/contexts/${args.contextId}`,
        { method: create ? 'POST' : 'PUT', jsonBody: body }
      );
      return text(
        `Application ${create ? 'created' : 'updated'}: ${formatContext(data.ocs.data, true)}`
      );
    } catch (err) {
      return report('saving application', err);
    }
  },
};

export const deleteTableContextTool = {
  name: 'delete_table_context',
  title: 'Delete Table Application',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a Tables application (context). Its tables and views are kept.',
  inputSchema: z.object({ contextId: z.number().describe('Application ID') }),
  handler: async (args: { contextId: number }) => {
    try {
      await fetchOCS(`${OCS_API}/contexts/${args.contextId}`, { method: 'DELETE' });
      return text(`Application ${args.contextId} deleted.`);
    } catch (err) {
      return wrapError('deleting application', err);
    }
  },
};

export const tablesTools = [
  listTablesTool,
  getTableTool,
  saveTableTool,
  deleteTableTool,
  exportTableStructureTool,
  importTableStructureTool,
  saveTableColumnTool,
  deleteTableColumnTool,
  saveTableViewTool,
  deleteTableViewTool,
  listTableRowsTool,
  saveTableRowTool,
  deleteTableRowTool,
  listTableSharesTool,
  shareTableTool,
  updateTableShareTool,
  deleteTableShareTool,
  listTableContextsTool,
  getTableContextTool,
  saveTableContextTool,
  deleteTableContextTool,
];
