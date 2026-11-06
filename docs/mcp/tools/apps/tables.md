# Nextcloud Tables Tools

Integration with [Nextcloud Tables](https://apps.nextcloud.com/apps/tables). Read and write structured data, including relations between tables, and move a table's structure between tables or servers.

## Prerequisites

- Nextcloud Tables app installed and enabled (app ID: `tables`)
- Share or owner rights on the table for anything that changes it

## API

Uses both Tables APIs: OCS v2 at `/ocs/v2.php/apps/tables/api/2/` for tables and structure, and REST v1 at `/index.php/apps/tables/api/1/` for rows, views and columns, which OCS v2 does not fully cover. Both are described in the [Tables OpenAPI spec](https://github.com/nextcloud/tables/blob/main/openapi.json).

## How values work

Tables stores rows as column IDs and raw values. These tools work in names instead:

- Keys are column titles. A column ID or alias works too, and is needed if two columns share a title.
- Selection values are labels. Pass a list for a multi-selection.
- A relation is written as a row ID or label of the target, and read as `{ id, label, target: { type, id } }`. Pass `target` to `list_table_rows` with `id` to follow it.

Run `get_table` first to see the columns, options and relation targets. An unknown column or option is an error, and nothing is written.

## Available Tools

### Tables

#### `list_tables`
List the tables the user can access, with their views and row counts.

#### `get_table`
Get the columns of a table or view, and for a table also its views.

**Parameters:**
- `tableId` (number) or `viewId` (number) — exactly one.

#### `save_table`
Create a table, or change one when `tableId` is given.

**Parameters:**
- `tableId` (number, optional)
- `title` (string) — required to create.
- `emoji`, `description` (string, optional)
- `archived` (boolean, optional) — existing tables only.

#### `delete_table`
Delete a table with all its rows, columns and views.

### Rows

#### `list_table_rows`
List rows a page at a time.

**Parameters:**
- `tableId` (number) or `viewId` (number) — exactly one.
- `columns` (array, optional) — only these columns, by title or ID.
- `limit` (number, optional) — default 50, maximum 200.
- `offset` (number, optional) — the `nextOffset` of the previous page.

The API has no row filter of its own. To filter, read a view that has the filter.

**Example:** "Show the open orders and who each is for."

#### `save_table_row`
Add a row, or change one when `rowId` is given. Only the columns passed are changed.

**Parameters:**
- `tableId` (number) or `viewId` (number) — exactly one.
- `rowId` (number, optional)
- `values` (object, required) — column title and value.

#### `delete_table_row`
Delete a row.

**Parameters:**
- `rowId` (number, required)
- `viewId` (number, optional) — the view the user reaches the row through, if they have no access to the table.

### Columns

#### `save_table_column`
Add a column to a table, or change one when `columnId` is given. Only the settings passed are changed.

**Parameters:**
- `tableId` or `columnId` (number) — exactly one.
- `title` (string) and `type` (enum) — required to create. Types are `text`, `number`, `datetime`, `selection`, `usergroup` and `relation`.
- `subtype` (string, optional) — text: `line` (default), `long`, `rich`, `link`. Number: `stars`, `progress`. Datetime: `date`, `time`. Selection: `multi`, `check`.
- `mandatory` (boolean, optional)
- `description` (string, optional)
- `options` (array, optional) — the labels of a selection. On an existing column, pass the full list: labels that stay keep their meaning in existing rows.
- `relation` (object, optional) — `type` (`table` or `view`), `targetId`, and `labelColumn`, the target column that names a row.
- `settings` (object, optional) — any other Tables column parameter, such as `numberMin` or `textMaxLength`.

**Example:** "Add a Customer column to Orders that links to the Customers table by name."

#### `delete_table_column`
Delete a column and its value in every row.

### Views

#### `save_table_view`
Create a view, or change one when `viewId` is given.

**Parameters:**
- `tableId` (number, required)
- `viewId` (number, optional)
- `title` (string) — required to create.
- `description` (string, optional)
- `columns` (array, optional) — columns to show, in order.
- `filter` (array, optional) — lists of rules with `column`, `operator` and `value`. All rules of a list must match, and any one list is enough.

#### `delete_table_view`
Delete a view. The rows stay in the table.

### Structure

#### `export_table_structure`
Export a table's columns and views as JSON, without rows.

#### `import_table_structure`
Create a table from an exported structure, or apply one to an existing table.

**Parameters:**
- `structure` (object, required) — the output of `export_table_structure`.
- `tableId` (number, optional) — apply to this table. Omit to create a new one.
- `preview` (boolean, optional) — with `tableId`, report the changes and make none.

Applying a structure makes the table match it: columns and views it does not have are removed along with their data. Preview first.
