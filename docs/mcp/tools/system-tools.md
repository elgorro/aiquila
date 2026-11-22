# System Tools

System tools cover files, tags, search, instance status, app management and integrity checks.
They are always registered, except the categories marked **AIquila app** below, which are only
registered when the AIquila Nextcloud app is installed and enabled.

Every tool acts as one Nextcloud account: under OAuth, the signed-in user; for stdio and
unauthenticated HTTP, `NEXTCLOUD_USER`. Nextcloud enforces that account's permissions, so a tool
marked **Admin** fails with `403` for anyone else.

| Category | Tools | Calls | Needs |
|---|---|---|---|
| [Files](#file-tools) | `list_files`, `read_file`, `write_file`, `create_folder`, `delete`, `move_file`, `copy_file`, `bulk_file_operations` | WebDAV | — |
| [Files](#file-tools) | `get_file_content`, `analyze_image` | AIquila app `/api/files/content`, else WebDAV | — |
| [Files](#file-tools) | `get_file_info`, `search_files`, `create_archive`, `extract_archive`, `list_archive` | AIquila app `/api/files/*` | AIquila app |
| [Tags](#tag-tools) | `get_file_tags`, `set_file_tags`, `list_system_tags`, `create_system_tag`, `assign_system_tag`, `remove_system_tag` | WebDAV (`systemtags`) | — |
| [Search](#search-tools) | `unified_search`, `list_search_providers` | OCS Unified Search | — |
| [Status](#status-tools) | `system_status`, `get_local_time` | `status.php`, OCS capabilities | — |
| [Status](#status-tools) | `run_setup_checks` | OCC through the AIquila app | AIquila app, Admin |
| [Apps](#app-management-tools) | `list_apps`, `get_app_info`, `enable_app`, `disable_app` | OCS Provisioning API | Admin |
| [Apps](#app-management-tools) | `install_app`, `uninstall_app` | OCC through the AIquila app | AIquila app, Admin |
| [Security](#integrity-tools) | `check_core_integrity`, `check_app_integrity` | OCC through the AIquila app | AIquila app, Admin |
| [OCC](#run_occ) | `run_occ` | OCC through the AIquila app | AIquila app, Admin |

## How OCC-backed tools run

The tools that run OCC commands do so on the Nextcloud server. They `POST` the command and its
arguments to the AIquila app's `/api/occ` endpoint, which runs `php occ <command> --no-ansi
--no-interaction <args>` and returns `success`, `stdout`, `stderr` and `exitCode`. There is
nothing to copy and run by hand.

`/api/occ` is admin-only, and it accepts only invocations in the app's own OCC allowlist (see
[The allowlist](#the-allowlist)). Its default covers every command the dedicated tools run. A
refused or failed call comes back as a tool error:

| Status | Message |
|---|---|
| `401` | Nextcloud no longer accepts the account's credentials; sign in again |
| `403` | The account is not an admin, or the app's allowlist refused the invocation (the reason is included) |
| `404` | The AIquila app is not installed or not enabled |
| `408` | The command ran past its timeout |

A command that ran but exited non-zero is also reported as an error, with its output.

## File tools

Paths are relative to the account's home folder and start with `/`, e.g. `/Documents/file.txt`.
Use forward slashes.

### list_files

List the entries of a folder.

**Parameters:**
- `path` (string, optional): folder to list. Default `/`

**Returns:** a JSON array, one object per entry, without the folder itself:
- `filename`: full path, e.g. `/Documents/plan.md`
- `basename`: last path segment
- `type`: `file` or `directory`
- `size`: bytes
- `lastmod`: `getlastmodified` as Nextcloud sends it (an HTTP date), or `null`
- `etag`: entity tag without quotes, or `null`
- `mime`: media type, files only

```json
[
  {
    "filename": "/Documents/plan.md",
    "basename": "plan.md",
    "lastmod": "Mon, 09 Nov 2026 10:30:00 GMT",
    "size": 2048,
    "type": "file",
    "etag": "5f2c7e0d1a9b3",
    "mime": "text/markdown"
  }
]
```

---

### read_file

Read a file over WebDAV.

**Parameters:**
- `path` (string, required): file to read

**Returns:**
- text files: the file body as text
- images: a header line `File: <name> (<mimeType>, <size> bytes)` and an MCP image block, up
  to [`MCP_MAX_EMBEDDED_FILE_SIZE`](#binary-files)
- other binary files (PDF, office documents, archives, audio): the header line and the file
  itself as an [embedded resource](#binary-files)

---

### write_file

Create a file or replace its contents.

**Parameters:**
- `path` (string, required): file to write
- `content` (string, required): the text to store, or the file's bytes in base64 when
  `encoding` is `base64`. Limited to `MCP_MAX_FILE_SIZE` characters (default 1 GB). The limit
  counts characters, not decoded bytes, so the largest file that fits as base64 is about
  three quarters of it
- `encoding` (string, optional): `text` (default) stores `content` as it is; `base64` decodes
  it first, to write a binary file such as a PDF, an image or an office document

Base64 is the standard alphabet with `=` padding and no line breaks; anything else is refused
and nothing is written. The caller has to produce the whole file as base64 in the tool call,
which suits small files. A model writes it out character by character, so a file of more than
a few tens of kilobytes is slow and costly to send this way.

**Returns:** `File written successfully to <path>`, followed by the decoded size for base64 content.

The parent folder must exist; Nextcloud answers `409 Conflict` otherwise (RFC 4918 §9.7.1).
Create it first with [`create_folder`](#create_folder). An existing file is overwritten, and
Nextcloud keeps the previous content as a [version](apps/versions.md).

---

### create_folder

Create one folder.

**Parameters:**
- `path` (string, required): folder to create

**Returns:** `Folder created successfully at <path>`.

This is a single `MKCOL` (RFC 4918 §9.3.1): it fails with `409` when the parent folder is missing
and with `405` when the folder already exists. Create nested folders one level at a time.

[**Structured result**](../README.md#structured-results): `{path}`.

---

### delete

Delete a file or folder, including everything inside a folder.

**Parameters:**
- `path` (string, required): file or folder to delete

**Returns:** `Deleted successfully: <path>`.

With the Deleted files app (`files_trashbin`) enabled, which it is by default, the item moves to
the trash bin and can be restored with the [trash tools](apps/trash.md).

---

### move_file

Move or rename a file or folder.

**Parameters:**
- `source` (string, required): current path
- `destination` (string, required): new path
- `overwrite` (boolean, optional): replace an existing destination. Default `false`; an existing
  destination then fails with `412 Precondition Failed` (RFC 4918 §9.9.4)

**Returns:** `Moved successfully: <source> → <destination>`.

---

### copy_file

Copy a file or folder (folders recursively).

**Parameters:**
- `source` (string, required): path to copy
- `destination` (string, required): path of the copy
- `overwrite` (boolean, optional): replace an existing destination. Default `false`

**Returns:** `Copied successfully: <source> → <destination>`.

---

### bulk_file_operations

Run up to 100 moves, copies and deletes in order, in one call.

**Parameters:**
- `operations` (array, required, 1–100 items), each with:
  - `action`: `move`, `copy` or `delete`
  - `source` (string): path to act on
  - `destination` (string): target path; required for `move` and `copy`

**Returns:** `Bulk operations: <succeeded>/<total> succeeded`, then one `✓` or `✗` line per
operation with the reason for each failure. A failed operation does not stop the ones after it.
The result is an error when any operation failed.

Moves and copies here overwrite an existing destination. Use `move_file` or `copy_file` with
`overwrite: false` where that matters.

---

### get_file_info

Metadata for a file or folder, from the AIquila app (`GET /api/files/info`).

**Parameters:**
- `path` (string, required)

**Returns:** JSON with `id` (the file ID [`assign_system_tag`](#assign_system_tag) takes),
`name`, `path`, `type` (`file` or `folder`), `size`, `mimeType`, `mtime` (Unix seconds), `etag`,
`permissions` (Nextcloud's permission bitmask) and `isEncrypted`. `path` is Nextcloud's internal
path, `/<user>/files/…`, not the path the other tools take.

[**Structured result**](../README.md#structured-results): the same fields; `path` falls back to the requested path if Nextcloud reports none.

---

### search_files

Find files whose name contains a string, optionally of one media type
(`GET /api/files/search`).

**Parameters:**
- `query` (string, required): text to look for in file names
- `mime` (string, optional): media type prefix, e.g. `image/` or `application/pdf`
- `path` (string, optional): folder to search under. Default `/`

**Returns:** JSON with `query`, `mimeFilter`, `count` and `results`, each result carrying the
same fields as `get_file_info`.

---

### get_file_content

Read a file of any type (`GET /api/files/content`). Where the AIquila app is not installed, or
answers `404` for the path, the file is read over WebDAV instead. Files over
[`MCP_MAX_EMBEDDED_FILE_SIZE`](#binary-files) are refused either way. The app also refuses files
over 20 MB itself, so a limit set higher takes effect only where the app is not installed.

**Parameters:**
- `path` (string, required)

**Returns:** a header line `File: <name> (<mimeType>, <size> bytes)`, then:
- text files: the text
- images: an MCP image block, so the model can look at it
- other binary files: the file itself as an [embedded resource](#binary-files)

#### Binary files

`read_file`, `get_file_content` and [`mail_get_attachment`](apps/mail.md#mail_get_attachment)
return a file that is neither text nor an image as an MCP
[embedded resource](https://modelcontextprotocol.io/specification/2026-07-28/server/tools#embedded-resources):
a content block of type `resource` whose `blob` is the base64 of the file, with its media type
and a `nextcloud:///<path>` URI. The bytes are not converted or summarised. A text block ahead of
it names the file, its type and its size.

What happens next is up to the client: one that handles embedded resources can show the file to
the model or save it where the model can open it, and one that does not shows only the text
block.

The same URI answers `resources/read`: see [Resources](../README.md#resources).

`MCP_MAX_EMBEDDED_FILE_SIZE` (bytes, default `20971520` = 20 MB) caps the files sent this way,
and images sent as image blocks. A larger binary file or image is described, not sent, and
`read_file` does not download it: it asks
for the size and type first, and where the type does not say whether the file is text, reads
only its first 8 KB to find out. The value is a positive integer: anything else,
`0` included, is ignored for the default, with a warning in the log. Base64 makes the message a third larger than the
file, so lower the limit if a proxy in front of the server restricts response sizes.

---

### analyze_image

Hand one or more images to the model together with a question about them. The images are read
like [`get_file_content`](#get_file_content) reads them, so its per-file limit applies.

**Parameters:**
- `path` (string, optional): one image
- `paths` (string[], optional): up to 20 images; takes precedence over `path`
- `prompt` (string, required): what to ask about the images

**Returns:** a text block with the file names and the prompt, followed by one MCP image block per
file. A path that is not an image fails the whole call.

---

### create_archive

Create a zip archive on the server from files and folders (`POST /api/files/zip`). The MCP
file-size limit does not apply; the uncompressed total is capped at 512 MB.

**Parameters:**
- `sources` (string[], required, at least one): files and folders to include
- `destination` (string, required): path of the `.zip` to create
- `overwrite` (boolean, optional): replace an existing archive. Default `false` (`409` otherwise)

**Returns:** JSON with `archive` (its path), `entries` and `size`.

---

### extract_archive

Extract a zip archive on the server into a folder (`POST /api/files/unzip`). Entries that would
escape the destination are refused; the uncompressed total is capped at 512 MB.

**Parameters:**
- `archive` (string, required): path of the `.zip`
- `destination` (string, required): folder to extract into
- `overwrite` (boolean, optional): replace existing entries. Default `false` (`409` otherwise)

**Returns:** JSON with `destination` and `extracted` (number of entries written).

---

### list_archive

List a zip archive without extracting it (`GET /api/files/zip/list`).

**Parameters:**
- `archive` (string, required): path of the `.zip`

**Returns:** JSON with `archive`, `count` and `entries`, each with `name`, `size`,
`compressedSize` and `isDirectory`.

## Tag tools

Nextcloud has two kinds of tag. **Personal tags** (`oc:tags`) are private to the account and set
by name on a path. **System tags** are shared across the instance, have numeric IDs and are
attached by file ID.

### get_file_tags

**Parameters:** `path` (string, required)

**Returns:** `Tags for <path>: a, b` or `No tags found for <path>`. Personal tags only.

---

### set_file_tags

Replace the personal tags on a file or folder.

**Parameters:**
- `path` (string, required)
- `tags` (string[], required): the complete new set; `[]` clears them

**Returns:** `Tags set on <path>: …` or `All tags cleared from <path>`.

---

### list_system_tags

**Parameters:** none

**Returns:** one line per tag with `ID`, `Name`, `Visible`, `Assignable` and `CanAssign` (whether
this account may assign it).

---

### create_system_tag

**Parameters:**
- `name` (string, required)
- `userVisible` (boolean, optional): default `true`
- `userAssignable` (boolean, optional): default `true`

**Returns:** `System tag created: "<name>" (ID: <id>)`. Only admins can create a tag that is
not visible or not assignable.

---

### assign_system_tag

**Parameters:**
- `fileId` (number, required): from [`get_file_info`](#get_file_info)
- `tagId` (number, required): from [`list_system_tags`](#list_system_tags)

**Returns:** `System tag <tagId> assigned to file <fileId>`.

---

### remove_system_tag

**Parameters:** `fileId` (number, required), `tagId` (number, required)

**Returns:** `System tag <tagId> removed from file <fileId>`.

## Search tools

### unified_search

Search Nextcloud's Unified Search providers (files, mail, calendar, contacts, notes, …) through
`/ocs/v2.php/search/providers/<id>/search`.

**Parameters:**
- `query` (string, required)
- `provider` (string, optional): one provider ID from
  [`list_search_providers`](#list_search_providers)
- `limit` (number, optional): results per provider. Default 5
- `cursor` (string, optional): the `cursor` from a previous result, to fetch the next page.
  Only used together with `provider`

**Returns:** JSON. With `provider`: `provider`, `name`, `isPaginated`, `cursor` and `entries`.
Without it, up to six providers are searched in parallel (`files`, `mail`, `calendar` and
`notes` first, if installed, then the rest by their order) and the result is an array with one
object per provider that found something. Each entry has `title`, `subline` and `resourceUrl`.

---

### list_search_providers

**Parameters:** none

**Returns:** JSON array of providers with `id`, `name`, `appId` and `order`.

## Status tools

### system_status

Version and capabilities of the instance.

**Parameters:** none

**Returns:** JSON with two keys:
- `status`: the instance's `status.php` (`installed`, `maintenance`, `needsDbUpgrade`,
  `version`, `versionstring`, `edition`, `productname`, `extendedSupport`)
- `capabilities`: the OCS capabilities (`/ocs/v2.php/cloud/capabilities`) as the account sees
  them, i.e. what each installed app advertises

```json
{
  "status": {
    "installed": true,
    "maintenance": false,
    "needsDbUpgrade": false,
    "version": "35.0.2.1",
    "versionstring": "35.0.2",
    "productname": "Nextcloud"
  },
  "capabilities": { "version": { "major": 35 }, "capabilities": { "…": {} } }
}
```

---

### run_setup_checks

Runs `occ setupchecks`: the same security, performance and configuration checks as
**Administration settings → Overview**.

**Parameters:** none

**Returns:** `Setup checks completed successfully.` or `Setup checks found issues (exit code:
<n>).`, followed by the command's output. Non-zero exit (any warning or error in the checks) is
reported as a tool error, so read the output rather than treating the error as a failure to run.

Needs the AIquila app and an admin account.

---

### get_local_time

The MCP server's clock, for resolving "today" or "at 3pm" before calendar and task calls. It
makes no Nextcloud request.

**Parameters:** none

**Returns:** JSON with `localTime` (formatted for `MCP_LOCALE`, else the system locale),
`utcTime` (ISO 8601), `timezone` (IANA name, e.g. `Europe/Berlin`) and `utcOffset`
(e.g. `+01:00`).

---

### run_occ

Run an allowlisted OCC command and return its output. Secrets and server paths in
the output are redacted.

**Parameters:**
- `command` (string, required): The OCC command, e.g. `app:list`. Must match an allowlist entry exactly; abbreviations such as `maint:mode` are refused
- `args` (string[], optional): Arguments, e.g. `["--output=json"]`
- `timeout` (number, optional): Seconds, default 120, max 600

**Example Usage:**
```
Ask Claude: "Which apps are installed but not shipped with Nextcloud?"
Ask Claude: "Is maintenance mode on?"
```

#### The allowlist

Tool arguments come from the model, so text in an email, file or web page the model
reads can steer them. The allowlist therefore limits arguments as well as commands.
An entry is a command followed by the exact arguments it permits:

| Entry | Allows |
|---|---|
| `cmd` | `cmd` with no arguments |
| `cmd a b` | exactly `cmd a b` |
| `cmd a *` | `cmd a` followed by any arguments |
| `cmd *` | `cmd` with any arguments |

The default allows only commands that read state:

| Entry | What it does |
|---|---|
| `status *`, `setupchecks *`, `update:check` | Version, setup and update checks |
| `app:list *` | List apps |
| `integrity:check-core *`, `integrity:check-app *` | Verify code signatures |
| `user:list *`, `user:info *`, `group:list *` | List accounts and groups |
| `maintenance:mode` | Show whether maintenance mode is on (`--on` / `--off` are refused) |
| `theming:config` | Show the theming settings (setting a key is refused) |

Commands that change state are opt-in. Some of them, and what they can do:

| Entry | Effect |
|---|---|
| `maintenance:mode --on`, `maintenance:mode --off` | Takes the instance offline for every user, or back online |
| `theming:config *` | Changes the name, URL and slogan on the login page and in emails |
| `notification:generate *` | Sends any user a notification with any link |
| `trashbin:cleanup *` | `--all-users` permanently empties every user's trash bin |
| `files:scan *`, `files:cleanup *` | Whole-instance file cache jobs |
| `maintenance:mimetype:update-db`, `db:add-missing-indices`, `db:add-missing-columns`, `db:add-missing-primary-keys` | Database maintenance |
| `config:app:get *` | Reads any app's config, including values apps store as secrets |

The list is enforced twice, and an invocation must pass both:

1. **MCP server** — `MCP_OCC_ALLOWLIST`, comma-separated entries. It replaces the
   default, so repeat the default entries you want to keep:

   ```bash
   MCP_OCC_ALLOWLIST='status *,app:list *,maintenance:mode,maintenance:mode --on,maintenance:mode --off'
   ```

   This check applies to `run_occ` only. The dedicated tools (`run_setup_checks`,
   `install_app`, the integrity checks, the `aiquila_*` tools) run fixed commands and are
   checked by Nextcloud alone.

2. **Nextcloud** — the AIquila app's `occ_allowlist` app config value guards
   `POST /api/occ` with the same syntax. Its default is the list above plus the commands
   the dedicated tools run (`app:install *`, `app:remove *`, `aiquila:configure *`,
   `aiquila:test *`). Setting it replaces that default, so keep those entries if you use
   `install_app`, `uninstall_app` or the `aiquila_*` tools:

   ```bash
   occ config:app:set aiquila occ_allowlist --value='status *,app:list *,setupchecks *,integrity:check-core *,integrity:check-app *,user:list *,user:info *,group:list *,update:check,maintenance:mode,theming:config,app:install *,app:remove *,aiquila:configure *,aiquila:test *,maintenance:mode --on,maintenance:mode --off'
   ```

   An invocation it refuses returns `403` with the reason.

`run_occ` needs the account it acts as to be a Nextcloud admin: under OAuth, the signed-in
user; for stdio, `NEXTCLOUD_USER`.

## App management tools

All six need an admin account.

### list_apps

List app IDs (`GET /ocs/v2.php/cloud/apps`).

**Parameters:**
- `filter` (string, optional): `all` (default), `enabled` or `disabled`

**Returns:** `Nextcloud apps (<filter>):` followed by one app ID per line. `all` includes apps
that are present but disabled.

---

### get_app_info

The app's `info.xml` metadata as Nextcloud parses it (`GET /ocs/v2.php/cloud/apps/<appId>`).

**Parameters:**
- `appId` (string, required), e.g. `tasks`

**Returns:** JSON with fields such as `id`, `name`, `summary`, `version`, `licence`, `author`,
`dependencies` and `category`.

---

### enable_app

Enable an app that is present on the server (`POST /ocs/v2.php/cloud/apps/<appId>`). To fetch an
app from the App Store first, use [`install_app`](#install_app).

**Parameters:**
- `appId` (string, required)

**Returns:** `App "<appId>" has been enabled successfully.`

---

### disable_app

Disable an app (`DELETE /ocs/v2.php/cloud/apps/<appId>`). Its data stays and it can be enabled
again.

**Parameters:**
- `appId` (string, required)

**Returns:** `App "<appId>" has been disabled successfully. …`

---

### install_app

Download an app from the App Store and enable it, via `occ app:install`. Runs with a 300-second
timeout. Needs the AIquila app.

**Parameters:**
- `appId` (string, required)
- `keepDisabled` (boolean, optional): install without enabling (`--keep-disabled`)

**Returns:** the command's output. An app that is already installed, or not compatible with this
Nextcloud version, is reported as an error with the command's message.

---

### uninstall_app

Remove an app's code from the server, via `occ app:remove`. Needs the AIquila app.

**Parameters:**
- `appId` (string, required)
- `keepData` (boolean, optional): keep the app's database tables and settings (`--keep-data`)

**Returns:** the command's output. Without `keepData`, the app's data is deleted as well.

## Integrity tools

Both compare installed files against the signature Nextcloud ships for each release. They need
the AIquila app and an admin account.

### check_core_integrity

Runs `occ integrity:check-core`.

**Parameters:** none

**Returns:** `Core integrity check passed.` with no further output when the files match.
Otherwise `Core integrity check found issues (exit code: <n>).` and the list of modified,
extra or missing files.

---

### check_app_integrity

Runs `occ integrity:check-app <appId>`.

**Parameters:**
- `appId` (string, required)

**Returns:** `App "<appId>" integrity check passed.`, or the issues found. Apps without a
signature (most apps installed from git or by hand) report that the signature is missing.

## Development

- [Adding tools](../development/adding-tools.md)
- Source: [`files.ts`](../../../mcp-server/src/tools/system/files.ts),
  [`tags.ts`](../../../mcp-server/src/tools/system/tags.ts),
  [`search.ts`](../../../mcp-server/src/tools/system/search.ts),
  [`status.ts`](../../../mcp-server/src/tools/system/status.ts),
  [`apps.ts`](../../../mcp-server/src/tools/system/apps.ts),
  [`security.ts`](../../../mcp-server/src/tools/system/security.ts),
  [`occ.ts`](../../../mcp-server/src/tools/system/occ.ts)
- The AIquila app endpoints these tools call are described in [OpenAPI](../../dev/openapi.md).
