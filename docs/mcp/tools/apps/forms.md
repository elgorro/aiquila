# Nextcloud Forms Tools

Integration with the [Nextcloud Forms](https://apps.nextcloud.com/apps/forms) app. Build surveys and questionnaires, gather responses, and export results — all via MCP.

## Prerequisites

- Nextcloud Forms app installed and enabled (app ID: `forms`)
- The MCP user has permission to create/read forms on the target Nextcloud

## API

Uses the documented Forms OCS API v3 at `/ocs/v2.php/apps/forms/api/v3/…`. All requests send `OCS-APIRequest: true` and Basic auth; responses are the unwrapped `ocs.data`.

## Available Tools

### Forms

#### `list_forms`
List forms visible to the current user.

**Parameters:**
- `type` (`"owned"` | `"shared"` | `"partial"`, optional) — defaults to `owned`. `partial` returns forms with unfinished drafts.

#### `get_form`
Get a single form with its questions, options, shares, and metadata.

**Parameters:** `formId` (number, required).

#### `create_form`
Create a new empty form. Set title/description afterwards with `update_form`, then add questions with `create_form_question`.

**Parameters:** none.

#### `clone_form`
Duplicate an existing form into a new form (questions, options, and settings are copied).

**Parameters:** `formId` (number, required).

#### `update_form`
Update any subset of a form's properties. Only the fields you pass are changed.

**Parameters (all optional except `formId`):**
- `formId` (number, required)
- `title`, `description`, `submissionMessage` (string)
- `expires` (number) — unix timestamp; `0` disables expiration
- `isAnonymous`, `submitMultiple`, `allowEditSubmissions`, `showExpiration` (boolean)
- `state` (`0` = active, `1` = closed, `2` = archived)

#### `transfer_form_owner`
Transfer ownership of a form to another Nextcloud user.

**Parameters:** `formId` (number), `ownerId` (string — the new owner's user ID).

#### `delete_form`
Permanently delete a form and all its submissions.

**Parameters:** `formId` (number).

### Questions

#### `list_form_questions`
List all questions on a form, in display order.

**Parameters:** `formId` (number).

#### `create_form_question`
Add a question. For choice questions (`multiple`, `multiple_unique`, `dropdown`), call `create_form_options` afterwards.

**Parameters:**
- `formId` (number)
- `type` (`short` | `long` | `multiple` | `multiple_unique` | `dropdown` | `date` | `datetime` | `time` | `file` | `linearscale` | `color`)
- `text` (string, optional) — question text

#### `update_form_question`
Update question properties (text, requirement, type, extra settings).

**Parameters (all optional except `formId` + `questionId`):**
- `formId`, `questionId` (number)
- `text`, `name` (string)
- `isRequired` (boolean)
- `type` (enum — same values as `create_form_question`)
- `extraSettings` (object) — question-type-specific settings (`validationRegex`, `allowedFileTypes`, date/time bounds, linear-scale labels, …)

#### `reorder_form_questions`
Reorder all questions on a form. Pass the complete list of question IDs in the desired order.

**Parameters:** `formId` (number), `newOrder` (array of numbers).

#### `delete_form_question`
Delete a question.

**Parameters:** `formId` (number), `questionId` (number).

### Options

#### `create_form_options`
Add one or more options to a choice question.

**Parameters:**
- `formId` (number)
- `questionId` (number) — must be a choice-type question
- `optionTexts` (array of strings)

#### `update_form_option`
Update an option's text (and optionally its `order`).

**Parameters:** `formId`, `questionId`, `optionId` (numbers), `text` (string, optional), `order` (number, optional).

#### `reorder_form_options`
Reorder all options on a question. Pass the full list of option IDs in the desired order.

**Parameters:** `formId` (number), `questionId` (number), `newOrder` (array of numbers).

#### `delete_form_option`
Delete one option.

**Parameters:** `formId`, `questionId`, `optionId` (numbers).

### Shares

#### `create_form_share`
Share a form with a user, group, or as a public link.

**Parameters:**
- `formId` (number)
- `type` (`user` | `group` | `link`)
- `shareWith` (string) — user ID (`type=user`) or group ID (`type=group`); omitted for `type=link`
- `permissions` (array of `edit` | `results` | `results_delete` | `submit` | `embed`, optional) — defaults to `["submit"]`

#### `update_form_share`
Change the permissions on an existing share.

**Parameters:** `formId` (number), `shareId` (number), `permissions` (array).

#### `delete_form_share`
Revoke a share.

**Parameters:** `formId` (number), `shareId` (number).

### Submissions

#### `list_form_submissions`
List submissions for a form (owner / admin only). Supports free-text search and pagination.

**Parameters:**
- `formId` (number)
- `query` (string, optional) — search across answer values
- `limit` (number, 1–500, optional)
- `offset` (number ≥ 0, optional)

#### `get_form_submission`
Get a single submission and its answers.

**Parameters:** `formId` (number), `submissionId` (number).

#### `create_form_submission`
Record answers to a form. `answers` is keyed by **question ID**, and every value is an array:
- text / long / date / time / number / email / phone / color questions → `["text answer"]`
- `multiple` / `multiple_unique` / `dropdown` → `[optionId1, optionId2]` (numeric option IDs)
- `linearscale` → `[3]`

**Parameters:**
- `formId` (number)
- `answers` (record of `questionId → (string|number)[]`)
- `shareHash` (string, optional) — required when submitting via a public link

#### `delete_form_submission`
Delete one submission.

**Parameters:** `formId` (number), `submissionId` (number).

#### `delete_all_form_submissions`
Delete every submission for a form (the form itself is kept).

**Parameters:** `formId` (number).

#### `export_form_submissions`
Export all submissions to the user's Nextcloud storage. Returns the destination path; use `read_file` / `get_file_info` to work with it afterwards.

**Parameters:**
- `formId` (number)
- `path` (string) — destination folder inside the user's Nextcloud (e.g. `/Exports`)
- `fileFormat` (`csv` | `ods` | `xlsx`)

## Example Workflow

```
User: "Create a short feedback form with two questions and share a public link."
Claude:
  1. create_form()                                                      → formId = 42
  2. update_form(formId: 42, title: "Sprint retro", state: 0)
  3. create_form_question(formId: 42, type: "short", text: "What went well?")
  4. create_form_question(formId: 42, type: "long",  text: "What would you change?")
  5. create_form_share(formId: 42, type: "link")
```

## Limitations / Not Implemented

- **Binary export download** (`GET /submissions?fileFormat=…`) — exposes bytes over HTTP; not a natural fit for the text-based MCP tool surface. Use `export_form_submissions` to land a file in Nextcloud, then `read_file`.
- **File-upload answers** (`POST /submissions/files/{questionId}`) — requires multipart form-data; not in the initial integration. Text/choice/date/etc. questions are fully supported.
- **Question clone** (`POST /questions?fromId=X`) — can be added later if needed.

## References

- [Nextcloud Forms app](https://apps.nextcloud.com/apps/forms)
- [Forms API v3 documentation](https://github.com/nextcloud/forms/blob/main/docs/API_v3.md)
- [Forms data structures](https://github.com/nextcloud/forms/blob/main/docs/DataStructure.md)
- Source: [`mcp-server/src/tools/apps/forms.ts`](../../../../mcp-server/src/tools/apps/forms.ts)
- Client: [`mcp-server/src/client/forms.ts`](../../../../mcp-server/src/client/forms.ts)
