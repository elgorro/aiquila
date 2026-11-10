# Terms of Service Tools

Manage the documents and enforcement settings of the [Terms of service](https://apps.nextcloud.com/apps/terms_of_service) app.

## Prerequisites

- Terms of service installed and enabled (app ID: `terms_of_service`)
- An administrator account for every tool

## API

Uses the app's OCS API at `/ocs/v2.php/apps/terms_of_service`, described in its [OpenAPI spec](https://github.com/nextcloud/terms_of_service/blob/master/openapi-full.json). The two enforcement settings have no endpoint of their own, so they are written through the provisioning API at `/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/terms_of_service`.

## Available Tools

#### `get_terms_of_service`
Read the published terms per country and language, the enforcement settings, and the country and language codes the app accepts.

#### `set_terms_of_service`
Create the terms for a country and language, or replace the ones that exist.

**Parameters:**
- `languageCode` (string, required) — two letters, such as `en`.
- `body` (string, required) — the terms, in Markdown.
- `countryCode` (string, optional) — two letters, or `--` for all countries. Default `--`.

A code the app does not accept is an error (HTTP 417). `get_terms_of_service` lists the valid ones.

#### `delete_terms_of_service`
Delete one set of terms.

**Parameters:**
- `id` (number, required) — from `get_terms_of_service`.

#### `reset_terms_signatures`
Discard every user's acceptance, so all users must accept the terms again at their next login. This cannot be undone.

#### `update_terms_settings`
Change where the terms are enforced.

**Parameters:**
- `tosForUsers` (boolean, optional) — signed-in users must accept them.
- `tosOnPublicShares` (boolean, optional) — visitors of public shares must accept them.

**Example:** "Publish the updated German terms and make everyone accept them again."
