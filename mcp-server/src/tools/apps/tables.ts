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
];
