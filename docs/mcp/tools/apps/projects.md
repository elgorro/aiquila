# Projects Tools

Manage AIquila projects, project templates and the prompt library. A project bundles files and folders with an optional system prompt that is added to the conversations that use it.

## Prerequisites

- The AIquila Nextcloud app enabled (app ID: `aiquila`)
- For a team project: membership in the team

## API

Uses the AIquila app's REST API under `/index.php/apps/aiquila/api/`, described in its [OpenAPI spec](../../../dev/openapi.md).

## Available Tools

### Projects

#### `list_projects`
List the projects the user can use: their own, and those of the teams they belong to.

#### `create_project`
**Parameters:**
- `title` (string, required)
- `description` (string, optional)
- `systemPrompt` (string, optional) — added to every conversation that uses the project.
- `teamId` (string, optional) — a team you belong to. Every member can then use and change the project, and it is deleted with the team.

**Example:** "Make a project for the Acme tender with the tender folder and a prompt to answer in formal German."

#### `get_project`
Get a project with its paths and their IDs.

**Parameters:**
- `id` (number, required)

#### `update_project`
Change a project. Only the fields passed change.

**Parameters:**
- `id` (number, required)
- `title`, `description`, `systemPrompt` (string, optional)

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

#### `create_project_from_template`
Create a project as a copy of a template. Later changes to the template do not reach the project.

**Parameters:**
- `templateId` (number, required)
- `title` (string, optional) — default: the template's title.

### Prompt library

#### `list_prompts`
List the prompts the organization published and the user's own, with title and description.

#### `get_prompt`
Get the full text of a prompt.

**Parameters:**
- `id` (number, required) — from `list_prompts`.
