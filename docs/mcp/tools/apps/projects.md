# Projects Tools

Manage AIquila projects, project templates and the prompt library. A project bundles files and folders with an optional system prompt that is added to the conversations that use it.

## Prerequisites

- The AIquila Nextcloud app enabled (app ID: `aiquila`)
- For a team project: membership in the team

## API

Uses the AIquila app's REST API under `/index.php/apps/aiquila/api/`, described in its [OpenAPI spec](../../../dev/openapi.md).

## Team projects

A project with a `teamId` belongs to that team: every member can use and change it,
and each member's conversations with it stay their own. It is deleted with the team.

To keep it, move it to another team first (`update_project` with `teamId`, or the
**Owner** field in the project editor). Nextcloud tells the app about a deleted team
only after the team is gone, so the move cannot be offered at that point.

| Who | Move to another team | Make it personal (`''`) |
|---|---|---|
| The creator | yes | yes, it becomes theirs |
| An owner or admin of the project's team | yes | no |
| Any other member | no | no |

Whoever moves it must belong to the new team. The creator stays the creator. The
project's files and folders stay as they are: they are references that every user
reads with their own permissions, so members of the new team need access to them,
for example through a share or the new team's workspace.

## Available Tools

### Projects

#### `list_projects`
List the projects the user can use: their own, and those of the teams they belong to.

#### `create_project`
**Parameters:**
- `title` (string, required)
- `description` (string, optional)
- `systemPrompt` (string, optional) — added to every conversation that uses the project.
- `teamId` (string, optional) — a team you belong to. Every member can then use and change the project, and it is deleted with the team. See [Team projects](#team-projects).

**Example:** "Make a project for the Acme tender with the tender folder and a prompt to answer in formal German."

[**Structured result**](../../README.md#structured-results): the project as for `get_project`.

#### `get_project`
Get a project with its paths and their IDs.

**Parameters:**
- `id` (number, required)

[**Structured result**](../../README.md#structured-results): `id`, `title`, `userId`, `teamId`, `description`, `systemPrompt`, `isActive`, `createdAt`, `updatedAt` and `paths` (`id`, `path`, `pathType`).

#### `update_project`
Change a project. Only the fields passed change.

**Parameters:**
- `id` (number, required)
- `title`, `description`, `systemPrompt` (string, optional)
- `teamId` (string, optional) — move the project to this team, or `''` to make it personal. See [Team projects](#team-projects) for who may.

**Example:** "Move the Acme tender project to the Sales team before we delete the Bids team."

#### `delete_project`
Delete a project with its paths. Conversations that used it are kept, without the project.

**Parameters:**
- `id` (number, required)

#### `add_project_path`
Add a file or folder to a project.

**Parameters:**
- `id` (number, required)
- `path` (string, required)
- `pathType` (enum, optional) — `file` or `directory`. Default `file`.

#### `remove_project_path`
**Parameters:**
- `id` (number, required)
- `pathId` (number, required) — from `get_project`.

### Templates

#### `list_project_templates`
List the organization's templates and the user's own. A template holds a title, description, system prompt and suggested paths.

#### `save_project_as_template`
Save a copy of a project as one of your own templates: title, description, system prompt and paths. Works for a team project too, for any member of the team. A team project is deleted with its team, so this is how a member keeps a copy, and `create_project_from_template` turns it back into a project. The **Save as template** action in the project list does the same.

**Parameters:**
- `projectId` (number, required)
- `title` (string, optional) — default: the project's title.

**Example:** "Keep a copy of the Bids team's tender project before the team is deleted."

#### `create_project_from_template`
Create a project as a copy of a template. Later changes to the template do not reach the project.

**Parameters:**
- `templateId` (number, required)
- `title` (string, optional) — default: the template's title.

[**Structured result**](../../README.md#structured-results): the new project, as for `get_project`.

### Prompt library

#### `list_prompts`
List the prompts the organization published and the user's own, with title and description.

#### `get_prompt`
Get the full text of a prompt.

**Parameters:**
- `id` (number, required) — from `list_prompts`.
