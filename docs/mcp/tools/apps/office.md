# Nextcloud Office Tools

Convert documents between formats and create them from templates, through [Nextcloud Office](https://apps.nextcloud.com/apps/richdocuments).

## Prerequisites

- Nextcloud Office installed and enabled (app ID: `richdocuments`)
- A Collabora Online server connected to it. Templates and conversion both run there, and creating from a template needs version 24.04.5 or later

## API

Listing and conversion use the core Files API at `/ocs/v2.php/apps/files/api/v1/`, for which Nextcloud Office is the provider. The conversions a server supports are listed in its `files.file_conversions` capability. Creating from a template uses `template/fields/fill` in the [Nextcloud Office OCS API](https://github.com/nextcloud/richdocuments).

## Available Tools

#### `list_document_templates`
List the templates for documents, spreadsheets, presentations and diagrams, with their IDs. Template IDs differ per user, so don't reuse one for another account.

#### `create_document_from_template`
Create a document from a template, with the template's content in it, so it can be read or converted straight away. An existing file is not replaced.

**Parameters:**
- `path` (string, required) — path of the new document. Its extension sets the format, such as `.odt` or `.docx`.
- `templateId` (string, required) — from `list_document_templates`.

**Example:** "Start a new invoice from the template, as a Word document."

#### `convert_document`
Convert a file to another format.

**Parameters:**
- `path` (string, required)
- `format` (string, required) — file extension of the target format, such as `pdf`, `docx`, `odt` or `txt`.
- `destination` (string, optional) — path for the result. Default: next to the original.

If the target already exists, Nextcloud picks a new name, such as `report (2).pdf`, and the tool reports the path it used. A format the file can't be converted to is an error that lists the formats it can. Nextcloud limits each user to 25 conversions in two minutes.

**Example:** "Turn the Q3 report into a PDF."

## Document generation

Generating a document from a description is a Task Processing feature, not one of these tools. Nextcloud Office registers the task types itself (`richdocuments:text_to_text_document`, `richdocuments:text_to_spreadsheet_document`, `richdocuments:slide_deck_generation`), and needs a text generation provider to run them.
