# Coworker Tools

Control AIquila **coworkers**: persistent AI jobs that run server-side in the AIquila
Nextcloud app on a cron schedule, such as tagging new photos or translating a folder
of documents. From any MCP client you can create, configure, schedule, start, stop
and inspect them.

Nextcloud apps that register coworkers programmatically use the
[Cowork Management API](../../../cowork-api.md) instead.

## Prerequisites

- AIquila app installed and enabled in Nextcloud
- An AI provider configured in AIquila (Anthropic, Mistral, …)
- Nextcloud background jobs (cron) running, for scheduled runs

## Available Tools

| Tool | Description |
|------|-------------|
| `list_coworkers` | List your coworkers with state, schedule and last run |
| `list_coworker_templates` | List built-in and organization templates, and task types |
| `get_coworker` | Show one coworker, including its full options and prompt |
| `create_coworker` | Create a coworker, from a template or from scratch |
| `update_coworker` | Change any part of a coworker's configuration |
| `set_coworker_schedule` | Change when a coworker runs |
| `delete_coworker` | Delete a coworker and its run history |
| `enable_coworker` / `disable_coworker` | Turn scheduled runs on or off |
| `pause_coworker` / `resume_coworker` | Temporarily suspend scheduled runs |
| `run_coworker` | Run now and return the result |
| `stop_coworker` | Cancel the run that is in progress |
| `get_coworker_runs` | Recent run history |
| `get_coworker_run` | One run in full, with its per-item log |

### Stop, pause or disable?

| Want to… | Use |
|---|---|
| Abort the run that is going on right now | `stop_coworker`. The schedule continues |
| Skip upcoming runs for a while | `pause_coworker`, then `resume_coworker` |
| Switch the coworker off | `disable_coworker` |

---

### list_coworkers

List the current user's coworkers.

**Parameters:** none

**Returns:** for each coworker: title, ID, state (`active`, `paused`, `disabled`), task
type, provider and model, input folder, output target, cron schedule, next and last
run, and the last error if any. Agent coworkers also show their prompt (first line)
and tool allowlist.

### list_coworker_templates

List the templates (`templateId` values) and the task types the installed app
supports (`taskType` values, e.g. `vision:classify`, `docs:summarize`,
`docs:translate`). Templates are the built-in ones plus any an administrator
published for the organization; those are marked as such and their ids start
with `org-`.

**Parameters:** none

### get_coworker

**Parameters:**
- `id` (number, required): coworker ID

**Returns:** the summary from `list_coworkers` plus the complete `options` object and
the full prompt.

### create_coworker

Create a coworker. Pass `templateId` to start from a template. Any other field
overrides the template default.

**Parameters:**
- `templateId` (string, optional): built-in template ID
- `taskType` (string, optional): task type ID; required without a template
- plus any of the [configuration fields](#configuration-fields)

**Example:**
```
"Create a coworker from classify-images-mistral that tags /Photos/Inbox every night at 2"
```

### update_coworker

Change a coworker. Only the fields you pass change. Option fields (`maxTags`,
`recursive`, `effort`, `thinking`, `tools`, `options`) are **merged** into the stored
options, so setting one option keeps the others.

**Parameters:**
- `id` (number, required): coworker ID
- any of the [configuration fields](#configuration-fields)

### set_coworker_schedule

**Parameters:**
- `id` (number, required): coworker ID
- `cronSchedule` (string, required): 5-field cron expression
  (`minute hour day-of-month month day-of-week`) in the Nextcloud server's time zone,
  e.g. `30 6 * * 1-5` for weekdays at 06:30

**Returns:** the new schedule and the next run time. An invalid expression is rejected
by the app.

### delete_coworker

Delete a coworker and all of its runs.

**Parameters:**
- `id` (number, required): coworker ID

### enable_coworker, disable_coworker, pause_coworker, resume_coworker

**Parameters:**
- `id` (number, required): coworker ID

**Returns:** the updated coworker.

### run_coworker

Run a coworker immediately, ignoring its schedule, and wait for the result. Batch task
types may return `pending`. They finish on a later background-job tick, and
`get_coworker_runs` shows their progress.

**Parameters:**
- `id` (number, required): coworker ID

### stop_coworker

Cancel the coworker's active run, whether it is `running` or a `pending` batch. The run
ends as `cancelled` and keeps the items it had already processed. Its schedule,
enabled and paused state are unchanged.

**Parameters:**
- `id` (number, required): coworker ID

**Returns:** the cancelled run. Reports *not found* when the coworker has no active run.
Needs an AIquila app version that supports run cancellation; older versions answer
*not found* too.

### get_coworker_runs

**Parameters:**
- `id` (number, required): coworker ID
- `limit` (number, optional): maximum runs, default 20

**Returns:** one line per run: ID, status (`running`, `pending`, `success`, `error`,
`cancelled`), items processed, start time, duration, and the first line of the summary
or the error.

### get_coworker_run

**Parameters:**
- `id` (number, required): coworker ID
- `runId` (number, required): run ID from `get_coworker_runs`

**Returns:** status, duration, the complete summary and error, and the run's per-item
log (timestamp, level, item path, message). Needs an AIquila app version that serves
single runs; older versions answer *not found*.

---

## Configuration fields

Accepted by `create_coworker` and `update_coworker`:

| Field | Type | Notes |
|---|---|---|
| `title` | string | |
| `description` | string | |
| `provider` | string | `anthropic`, `mistral`, `deepseek`, `hetzner`, `local`. `""` follows the owner's setting |
| `model` | string | Omit to use the provider default |
| `inputPath` | string | Folder to process |
| `outputType` | string | `system_tags` (default) or `file` |
| `outputPath` | string | Target file or folder when `outputType` needs one |
| `cronSchedule` | string | 5-field cron, default `0 3 * * *` |
| `isActive` | boolean | Enable or disable |
| `maxTags` | number | Option: tags per image (`vision:classify`) |
| `recursive` | boolean | Option: include sub-folders |
| `effort` | string | Option: reasoning effort, e.g. `low`. `""` uses the default |
| `thinking` | `auto` \| `on` \| `off` | Option: extended thinking |
| `options` | object | Any other task-specific option, e.g. `{"targetLanguage": "de"}` for `docs:translate`. The typed option fields above win on conflict |
| `prompt` | string | Agent coworkers: the instruction to run |
| `promptId` | number | Agent coworkers: a saved prompt to run instead of `prompt` |
| `tools` | string[] | Agent coworkers: MCP tools the run may call |

## Agent coworkers

An agent coworker runs a free-form `prompt` on its schedule and may call MCP tools from
the owner's configured MCP servers. Because these runs are unattended, the coworker
only gets the tools named in its `tools` allowlist. An empty list means the prompt runs
without tools. Name the narrowest set the job needs, and prefer read-only tools.

```
"Create an agent coworker that runs weekdays at 7:00, summarises my unread mail into
/Notes/daily.md, and may only use mail_list_messages and mail_read_message"
```

Agent coworkers need an AIquila app version with the agent task type. If the app
doesn't support it yet, `create_coworker` and `update_coworker` say that the prompt was
ignored.
