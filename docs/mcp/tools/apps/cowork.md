# Coworker Tools

Control AIquila **coworkers**: persistent AI jobs that run server-side in the AIquila
Nextcloud app on a cron schedule or on [triggers](#triggers), such as tagging new photos or translating a folder
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

A personal coworker runs as the user who created it. When that account is deleted, its coworkers are stopped and deleted with their run history.

### Team coworkers

Pass `teamId` to `create_coworker` to give a coworker to a team you belong to. Any member can then see, change, run, stop and delete it, and see all of its runs. The team is set once, when the coworker is created.

A coworker always runs as one account, which `list_coworkers` shows as `runs as`:

- It starts as the member who created it.
- When that person leaves the team, or their account is deleted or disabled, its next run moves to the team owner, and otherwise a team admin. The change is stored, and each run keeps the account it ran as.
- With nobody left to run as, it pauses and says why in its last error.
- When the team is deleted, its coworkers are stopped straight away and deleted with their run history.

Providers and models are those of the account it runs as. Its input and output paths must be inside the [team's workspace](circles.md#workspaces), the one place every account it can move to shares, so a team coworker that uses paths needs a team with a workspace. An input folder is required unless `input_type` is `none`. A pinned provider the account may not use falls back to that account's own, as on any coworker.

### Triggers

When an administrator allows coworker triggers for the account, a coworker can also
start on its own:

- **File changes**: with `runOnFileChange`, a file added, changed, moved or copied
  into its input folder starts it about a minute later. Files it writes itself, and
  files in its output folder, do not.
- **Trigger URL**: another system calls a URL with a secret. It is created in the
  Coworker settings in Nextcloud, so the secret never passes through the chat.
  `list_coworkers` shows whether a coworker has one.

At most 12 triggered runs start per coworker per hour. `get_coworker_runs` shows what
started each run. How triggers work, and how an administrator turns them on, is in
[Triggers](../../../installation/aiquila-setup.md#triggers).

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
- `teamId` (string, optional): team that owns the coworker, see [Team coworkers](#team-coworkers)
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
by the app. Setting a schedule also turns it on for a coworker that ran only on
triggers.

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
`cancelled`), items that succeeded out of the total and how many failed, start time, duration, what started it (scheduled, run
by hand, file change, trigger URL), and the first line of the summary or the error.

### get_coworker_run

**Parameters:**
- `id` (number, required): coworker ID
- `runId` (number, required): run ID from `get_coworker_runs`

**Returns:** status, item counts, duration, the complete summary and error, and the
run's per-item log (timestamp, level, item path, message). Needs an AIquila app version that serves
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
| `scheduled` | boolean | Whether the schedule runs it. `false` leaves it to [triggers](#triggers) and runs by hand |
| `runOnFileChange` | boolean | Also run when files in the input folder are added or changed, see [Triggers](#triggers) |
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

An agent coworker (`agent:run`) runs a free-form `prompt`, or a saved prompt by
`promptId`, on its schedule. It may call tools of the Nextcloud instance's MCP
servers, the ones the AIquila chat uses. Because these runs are unattended, the
coworker only gets the tools named in its `tools` allowlist, and any other tool the
model asks for is refused. An empty list means the prompt runs without tools. Name the
narrowest set the job needs, and prefer read-only tools. With `outputType: "file"` the
answer is written to `outputPath`; otherwise it is the run summary.

```
"Create an agent coworker that runs weekdays at 7:00, summarises my unread mail into
/Notes/daily.md, and may only use mail_list_messages and mail_read_message"
```

Agent coworkers need an AIquila app version with the agent task type. If the app
doesn't support it yet, `create_coworker` and `update_coworker` say that the prompt was
ignored.
