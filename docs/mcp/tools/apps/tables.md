# Nextcloud Tables Tools

Integration with [Nextcloud Tables](https://apps.nextcloud.com/apps/tables). Read and write structured data, including relations between tables, move a table's structure between tables or servers, share tables and views, and build applications from them.

## Prerequisites

- Nextcloud Tables app installed and enabled (app ID: `tables`)
- Share or owner rights on the table for anything that changes it

## API

Uses both Tables APIs: OCS v2 at `/ocs/v2.php/apps/tables/api/2/` for tables, structure and applications, and REST v1 at `/index.php/apps/tables/api/1/` for rows, views, columns and shares, which OCS v2 does not fully cover. Both are described in the [Tables OpenAPI spec](https://github.com/nextcloud/tables/blob/main/openapi.json).

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

### Sharing

A table or view can be shared with a user, a group, a team, or an account on another Nextcloud (federation). Each share carries its own permissions: `read`, `create`, `update` and `delete` for rows, and `manage` for columns, views and shares. Listing and changing shares needs `manage` on the table.

#### `list_table_shares`
List who a table or view is shared with.

**Parameters:**
- `tableId` or `viewId` (number) — exactly one.

#### `share_table`
Share a table, a view or an application.

**Parameters:**
- `tableId`, `viewId` or `contextId` (number) — exactly one.
- `receiver` (string, required) — a user ID, group ID, team ID, or Federated Cloud ID (`user@cloud.example.com`).
- `receiverType` (string, required) — `user`, `group`, `circle` (a team) or `remote` (federated).
- `permissions` (object, optional) — `read`, `create`, `update`, `delete`, `manage` (booleans). A permission left out is not granted. Default: `read` only. Not used for applications, whose users get the permissions set on each of its tables and views.
- `displayMode` (number, optional) — applications only: show it in the navigation bar for nobody (`0`), the receivers (`1`) or everyone (`2`, default).

A `remote` share works for tables and views, not applications. Tables must allow federated sharing (its `federationEnabled` setting, on by default) on both servers. Tables saves the share before it tells the other server, and keeps it when that fails, so after an error check `list_table_shares` and remove it.

**Example:** "Share the Inventory table with the Warehouse team so they can add and change rows."

#### `update_table_share`
Change the permissions of a table or view share. Only the permissions passed change. A remote receiver is told of the change.

**Parameters:**
- `shareId` (number, required)
- `permissions` (object, required) — as for `share_table`.

#### `delete_table_share`
Stop sharing with that receiver.

**Parameters:**
- `shareId` (number, required)

### Applications

An application (Tables calls it a context) bundles tables and views under its own name, icon and navigation entry. Each table or view in it carries the permissions its users get there.

#### `list_table_contexts`
List the applications you own or that are shared with you.

#### `get_table_context`
Get an application with its tables and views, their permissions, and who it is shared with.

**Parameters:**
- `contextId` (number, required)

#### `save_table_context`
Create an application, or change one when `contextId` is given.

**Parameters:**
- `contextId` (number, optional) — the application to change. Omit to create one.
- `name` (string) — required to create.
- `iconName` (string, optional) — a Material Design icon bundled with Tables, such as `briefcase` or `folder`. Default `apps`. An unknown name is refused.
- `description` (string, optional)
- `nodes` (array, optional) — the tables and views it shows, each `{ tableId }` or `{ viewId }` with optional `permissions` (default `read`). Passing `nodes` replaces the current list. The order is the order shown.

Share it with `share_table` and `contextId`.

**Example:** "Make a CRM application from the Customers table and the Open deals view, read-only for deals."

#### `delete_table_context`
Delete an application. Its tables and views are kept.

**Parameters:**
- `contextId` (number, required)
