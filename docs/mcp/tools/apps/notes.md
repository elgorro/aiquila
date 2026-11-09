# Notes Tools

Read and write notes in [Nextcloud Notes](https://apps.nextcloud.com/apps/notes).

## Prerequisites

- Notes installed and enabled (app ID: `notes`)

## API

Uses the [Notes API v1](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md) at `/index.php/apps/notes/api/v1`. Notes stays the owner of the files: a note is a Markdown file in the user's notes folder, and its category is the subfolder it sits in.

## Available Tools

#### `list_notes`
List the notes with ID, title, category, favourite flag and modification date.

**Parameters:**
- `category` (string, optional) — only notes in this category.
- `search` (string, optional) — only notes whose title contains this text, ignoring case.

#### `get_note`
Get a note with its full content.

**Parameters:**
- `id` (integer, required) — from `list_notes`.

#### `create_note`
**Parameters:**
- `title` (string, required)
- `content` (string, required) — Markdown.
- `category` (string, optional) — created as a subfolder if it does not exist.
- `favorite` (boolean, optional)

**Example:** "Make a note of today's decisions under Work."

#### `update_note`
Change a note. Only the fields passed change.

**Parameters:**
- `id` (integer, required)
- `title`, `content`, `category` (string, optional)
- `favorite` (boolean, optional)

#### `delete_note`
**Parameters:**
- `id` (integer, required)
