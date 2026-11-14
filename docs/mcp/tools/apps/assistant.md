# Nextcloud Assistant / AI Tools

Integration with Nextcloud's TaskProcessing AI framework, and with the Nextcloud Assistant's agent: submit text processing and image generation tasks to configured AI providers (e.g., AIquila, LocalAI, Stable Diffusion), and manage the user's Assistant **skills** and scheduled **assignments**.

## Prerequisites

- At least one AI task provider must be configured in Nextcloud (e.g., AIquila app, or an external AI integration)
- For image generation, a text-to-image provider must be available
- Skills and assignments need the Assistant app 4.1 or later (Nextcloud 35)

## Available Tools

| Tool | Description |
|------|-------------|
| `list_text_tasks` | List available AI task types |
| `process_text` | Submit a text processing task |
| `get_task_result` | Get status/result of an AI task |
| `generate_image` | Generate an image from a text prompt |
| `list_assistant_skills` | List the user's Assistant skills |
| `get_assistant_skill` | Read a skill's full `SKILL.md` |
| `save_assistant_skill` | Create a skill, or replace the one with the same name |
| `delete_assistant_skill` | Delete one of the user's skills |
| `list_assistant_assignments` | List the user's scheduled assignments |
| `get_assistant_assignment` | Get one assignment |
| `create_assistant_assignment` | Schedule a prompt, once or on a recurrence |
| `update_assistant_assignment` | Change an assignment's prompt, start, recurrence or time zone |
| `delete_assistant_assignment` | Delete an assignment |

---

### list_text_tasks

List AI task types available in Nextcloud's TaskProcessing framework (summarize, headline, image-to-text, etc.). Shows which task types have at least one provider configured.

**Parameters:**
None

**Returns:**
List of available AI task types with their IDs and descriptions.

**Example Usage:**
```
Ask Claude: "What AI task types are available in Nextcloud?"
Ask Claude: "List the text processing capabilities"
```

---

### process_text

Submit a text-processing task to Nextcloud's AI framework and return the task ID. Use `get_task_result` to poll until it completes.

**Parameters:**
- `taskType` (string, required): Task type ID (e.g., `core:text2text`, `core:summarize`, `core:headline`, `core:extract_topics`)
- `input` (string, required): The text to process
- `customId` (string, optional): Optional identifier to tag the task for later retrieval

**Returns:**
Task ID and initial status.

**Example Usage:**
```
Ask Claude: "Use Nextcloud AI to summarize this text: [long text]"
Ask Claude: "Generate a headline for this article using NC assistant"
```

**Common Task Types:**
| Type ID | Description |
|---------|-------------|
| `core:text2text` | General text-to-text processing |
| `core:summarize` | Text summarization |
| `core:headline` | Generate a headline |
| `core:extract_topics` | Extract topics from text |

---

### get_task_result

Get the status and result of a Nextcloud AI task previously created by `process_text` or `generate_image`.

**Parameters:**
- `taskId` (number, required): The task ID returned by `process_text` or `generate_image`

**Returns:**
Task status and result (if completed).

**Status:** Nextcloud reports `STATUS_SCHEDULED`, `STATUS_RUNNING`, `STATUS_SUCCESSFUL`,
`STATUS_FAILED`, `STATUS_CANCELLED` or `STATUS_UNKNOWN`; the tool shows them as `scheduled`,
`running` and so on. A failed or cancelled task is returned as an error, with the provider's
message when it gives one.

**Example Usage:**
```
Ask Claude: "Check the result of AI task 42"
```

---

### generate_image

Generate an image from a text prompt using whichever `core:text2image` provider the Nextcloud instance has registered. Returns the task ID; use `get_task_result` to check completion.

This tool consumes a provider, it does not register one: it calls Nextcloud's own OCS task API and whatever serves that task type answers. The AIquila Nextcloud app registers such a provider itself (backed by Mistral), so on an instance running both components this tool works without a separate image-generation app installed — see [Assistant integration](../../../installation/aiquila-setup.md).

**Parameters:**
- `prompt` (string, required): Text description of the image to generate
- `savePath` (string, optional): Optional Nextcloud path to save the generated image

**Returns:**
Task ID for image generation.

**Example Usage:**
```
Ask Claude: "Generate an image of a sunset over mountains using Nextcloud AI"
Ask Claude: "Create an image of a cat sitting on a laptop and save it to /Photos/ai-cat.png"
```

---

## Skills

A skill is a set of reusable instructions the Assistant's agent loads when a task matches
its description. Each one is a folder with a `SKILL.md` (YAML frontmatter with `name` and
`description`, then Markdown) under `Context Agent/Skills/` in the user's Assistant folder.
Skills an admin shares with everyone are listed too, but only the user's own can be changed
or deleted.

### list_assistant_skills

No parameters. Lists each skill as `name: description`. A user who has never created one
gets an empty list.

### get_assistant_skill

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | The skill name, as `list_assistant_skills` shows it |

Returns the whole `SKILL.md`, frontmatter included.

### save_assistant_skill

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | Skill name; a single folder name, e.g. `weekly-report` |
| `description` | string | Yes | When the agent should use the skill, in one or two sentences |
| `content` | string | Yes | The instructions, in Markdown |

Creates the skill, or replaces the one with the same name, and says which it did.

### delete_assistant_skill

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | Yes | The skill to delete |

The Assistant has no delete endpoint, so the tool deletes the skill's folder in Files. It
goes to the trash bin, where `restore_from_trash` can bring it back.

## Assignments

An assignment is a prompt the Assistant runs as the user on a schedule, and notifies them
of the result.

### create_assistant_assignment

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | string | Yes | Short title, e.g. `Monday inbox summary` |
| `prompt` | string | Yes | What the Assistant should do on each run |
| `startsAt` | string | Yes | First run, ISO 8601 **with an offset**, e.g. `2026-11-16T08:00:00+01:00` |
| `recurrence` | string | Yes | iCalendar RRULE (RFC 5545 §3.3.10), e.g. `FREQ=DAILY` or `FREQ=WEEKLY;BYDAY=MO,TH` |
| `timezone` | string | No | IANA time zone the recurrence follows, e.g. `Europe/Berlin`. Default: the user's |

The start time needs an offset so it cannot be read in the wrong zone. The rule is checked
before it is sent (one `FREQ`, `NAME=value` parts, valid `BYDAY` weekdays), because the
Assistant stores a rule it cannot evaluate, and such an assignment never runs.

### update_assistant_assignment

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | number | Yes | Assignment ID |
| `prompt` | string | No | New prompt |
| `startsAt` | string | No | New first run, as above |
| `recurrence` | string | No | New RRULE |
| `timezone` | string | No | New time zone |

Fields left out keep their value. The title cannot be changed.

### list_assistant_assignments / get_assistant_assignment / delete_assistant_assignment

`list_assistant_assignments` takes no parameters. The other two take the assignment `id`.
Each assignment is shown with its rule, time zone, first run, last run and prompt.

## Workflow Examples

### Text Summarization
```
User: "Read my file /Documents/report.md and summarize it using Nextcloud AI"
Claude: Reads file -> submits to process_text with core:summarize -> polls get_task_result -> returns summary
```

### Image Generation Pipeline
```
User: "Generate a cover image for my blog post about AI and save it to /Photos"
Claude: Calls generate_image with descriptive prompt -> polls for completion -> confirms save path
```

### Weekly digest as an assignment

```
User: "Every Monday at 8, summarise my unread mail and the week's calendar"
Claude: Calls save_assistant_skill for the digest format, then create_assistant_assignment
        with startsAt "2026-11-16T08:00:00+01:00", recurrence "FREQ=WEEKLY;BYDAY=MO"
```

## Development

To extend assistant tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/assistant.ts](../../../../mcp-server/src/tools/apps/assistant.ts) (task processing), [assistant-agent.ts](../../../../mcp-server/src/tools/apps/assistant-agent.ts) (skills, assignments)

## References

- [Nextcloud Assistant](https://docs.nextcloud.com/server/latest/admin_manual/ai/index.html)
- [TaskProcessing API](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/taskprocessing.html)
- [Assistant OpenAPI spec](https://github.com/nextcloud/assistant/blob/main/openapi.json) (tags `agent_skills`, `assignments`)
- [RFC 5545 §3.3.10](https://www.rfc-editor.org/rfc/rfc5545#section-3.3.10) (RECUR)
