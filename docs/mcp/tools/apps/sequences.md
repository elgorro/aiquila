# Sequences Tools

A **sequence** is a named, parameterised, ordered list of steps. Each step calls one
AIquila MCP tool. The MCP server runs every step of a sequence in a single
`tools/call`, as the calling user, and returns a report of each step.

Sequences come from three sources:

| Source | Written by | Tool name | Needs |
|---|---|---|---|
| Built-in | shipped with the MCP server | `seq_<slug>` | nothing |
| Organization | an admin, under Administration settings → AIquila → Sequences | `seq_<slug>` | the AIquila app |
| Personal | each user for themselves, under Personal settings → AIquila → Sequences | `seq_my_<slug>` | the AIquila app |

Users manage them in the settings UI, described in [Sequences](../../../sequences.md).
Organization sequences run as the user who calls them, never as the admin who wrote them.

## Prerequisites

- None for the built-ins. A step whose tool you do not have (its app is missing, or
  `MCP_TOOLS` leaves it out) is skipped.
- The AIquila Nextcloud app (app ID: `aiquila`) for organization and personal sequences.

## API

Organization and personal sequences come from the AIquila app's
`GET /index.php/apps/aiquila/api/sequences/effective`, described in its
[OpenAPI spec](../../../dev/openapi.md). The server keeps each user's answer for 30
seconds and then revalidates it with `If-None-Match`. When the entity tag changes,
the user's tool list has changed.

## How sequences reach the client

- `list_sequences` and `run_sequence` are always listed, so a client holding an
  older tool list can still find and run every sequence.
- A sequence marked **Show as its own tool** is also listed as `seq_<slug>` or
  `seq_my_<slug>`, after the fixed tools, with its inputs as the input schema plus
  an optional `dryRun`.
- Each user's `tools/list` carries their own sequences. A request without a token
  (lazy auth) lists the built-ins only.
- 2026-07-28 clients with a `subscriptions/listen` stream receive
  `notifications/tools/list_changed` when their sequences change. 2025-era clients
  see the change on their next `tools/list`.

Including `sequences` in `MCP_TOOLS` turns them on; leaving it out removes
`list_sequences`, `run_sequence` and every `seq_*` tool. Steps can only run tools
that `MCP_TOOLS` lets through.

## Steps

Each step names a tool and its arguments, and says what happens if it fails:

- `onFailure: stop` (the default) ends the run there. The remaining steps are
  reported as not run.
- `onFailure: continue` goes on to the next step.

A step is **skipped**, not failed, when its tool is not available to you, when it
needs the output of a step that did not succeed, or when a required argument comes
from an input the caller left out.

The arguments are checked with the tool's own input schema, just as for a direct call.
Sequences cannot contain loops, conditions or other sequences. Steps on other MCP
servers are not supported: each step's `server` field must be empty.

The result is `isError: true` when any step failed. A step that was skipped does not
count as a failure. When the client sends a `progressToken`, a progress notification
follows each step.

### Limits

| Limit | Value |
|---|---|
| Steps per sequence | 20, or less if the admin's policy says so |
| Inputs per sequence | 20 |
| Run time of a whole sequence | 120 seconds |
| Text kept per step | 20,000 characters |
| Report | 100,000 characters |

Every step after the first counts against the caller's `MCP_RATE_LIMIT`. When the
limit is reached, the run stops.

## Templates

Any argument can contain `{{ variable }}` placeholders:

| Variable | Value |
|---|---|
| `inputs.<name>` | An input of the sequence |
| `today`, `today+7d`, `today-1w` | The local date (RFC 3339 `full-date`, e.g. `2026-11-15`), shifted by days or weeks |
| `now`, `now+2h`, `now-30m` | The current time in UTC (RFC 3339 `date-time`, e.g. `2026-11-15T09:30:00Z`), shifted by minutes, hours, days or weeks |
| `steps.<id>.text` | The text an earlier step returned |
| `steps.<id>.result.<path>` | A field of an earlier step's [structured result](../../README.md#structured-results), e.g. `steps.note.result.id` or `steps.list.result.items.0.id` |

`today` uses the server's time zone (`TZ`).

- **Types:** an argument that is a single placeholder keeps the value's type, so a
  number stays a number and a list stays a list. A placeholder inside longer text
  becomes text.
- **Single pass:** text substituted from a step's output is never scanned for
  placeholders again.
- **Left out:** an argument that is only `{{inputs.x}}` is dropped when the caller
  leaves out `x` and it has no default, so the tool's own default applies.
- **Other braces:** `{{ … }}` that does not start with `inputs`, `steps`, `today`
  or `now` is left as it is, so text containing braces needs no escaping.

Passing an id from one step to the next works with the tools that return a
structured result: create a note, then read it with
`{"id": "{{steps.note.result.id}}"}`.

## Annotations

A sequence's [tool annotations](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)
are derived from its steps:

- **read-only** only if every step is read-only;
- **destructive** if any step that is not read-only is destructive;
- **idempotent** only if every step is idempotent or read-only;
- **open-world** if any step is open-world.

A step tool the server does not know is counted with the MCP defaults (not read-only,
destructive, not idempotent, open-world). `run_sequence` can run anything, so it
carries those defaults. A destructive sequence asks for no confirmation in the
middle of a run. Its hints and the step plan in its description are what a client
sees beforehand.

## Built-in sequences

| Tool | Steps | Inputs | Kind |
|---|---|---|---|
| `seq_daily_briefing` | `get_local_time`, `list_events` (today), `list_tasks` (open), `list_notifications`, `list_mailboxes` | `calendar` (default `personal`), `mailAccountId` | read-only |
| `seq_inbox_triage` | `list_mailboxes`, `list_notifications`, `list_pending_shares`, `talk_list_conversations` | `mailAccountId` | read-only |
| `seq_weekly_review` | `list_activity`, `list_tasks` (open), `list_events` (next 7 days) | `calendar` (default `personal`) | read-only |
| `seq_find_meeting_slot` | `get_free_busy`, `find_free_rooms` | `start`, `end`, `attendees`, `minCapacity` | read-only |
| `seq_out_of_office_on` | `set_out_of_office`, `set_user_status` (away), `set_user_status_message` | `firstDay`, `lastDay`, `message`, `status`, `replacementUserId` | destructive |
| `seq_back_at_work` | `clear_out_of_office`, `set_user_status` (online), `clear_user_status_message` | — | destructive |
| `seq_instance_health_check` | `system_status`, `run_setup_checks`, `check_core_integrity`, `list_apps` (enabled) | — | read-only; the checks need admin rights |

The mail step is skipped unless `mailAccountId` is given (see `list_mail_accounts`).
An admin can switch any built-in off for everyone, and each user for themselves.

## Available Tools

#### `list_sequences`
List the sequences you can run, with their source, inputs, steps (and whether each
step's tool is available to you), and whether they are read-only or destructive.

[**Structured result**](../../README.md#structured-results): `sequences`, each with
`name`, `source`, `title`, `description`, `ownTool`, `readOnly`, `destructive`,
`inputs` and `steps` (`id`, `tool`, `available`).

#### `run_sequence`
Run a sequence by its name.

**Parameters:**
- `sequence` (string, required): the name from `list_sequences`, e.g. `seq_daily_briefing` or `seq_my_standup`.
- `inputs` (object, optional): the sequence's inputs.
- `dryRun` (boolean, optional): resolve the templates and check every step's arguments, but run nothing.

**Example:** "Run my daily briefing for the team calendar."

[**Structured result**](../../README.md#structured-results): `sequence`, `title`,
`status` (`completed`, `failed`, or `planned` for a dry run) and `steps`, each with
`index`, `id`, `tool`, `status` (`ok`, `failed`, `skipped`, `not_run`, `planned`),
`reason`, `arguments` (in a dry run, or when they were invalid) and `ms`. The text
of each step is in the result's text content.

#### `seq_<slug>` and `seq_my_<slug>`
One tool per sequence marked **Show as its own tool**. Its parameters are the
sequence's inputs, plus `dryRun`. It returns the same result as `run_sequence`.

## Catalog

The Nextcloud app validates and edits sequences without reaching the MCP server, using
`nextcloud-app/lib/Resources/sequence-catalog.json`. That file lists every tool a step
may call (category, annotations, input schema), the built-ins and the limits. It is
generated from the tool registry:

```bash
cd mcp-server && npm run catalog
```

A test fails when the committed file differs from what the code generates, so
regenerate it whenever a tool or a built-in changes.
