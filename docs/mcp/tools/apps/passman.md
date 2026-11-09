# Passman Tools

List [Passman](https://apps.nextcloud.com/apps/passman) vaults and the metadata of their credentials. **Secret values are never available:** Passman encrypts passwords, usernames, URLs and the other credential fields end to end, in the browser, so this server cannot read them.

## Prerequisites

- Passman installed and enabled (app ID: `passman`)

## API

Uses the [Passman REST API v2](https://github.com/nextcloud/passman/blob/master/docs/api.md) at `/index.php/apps/passman/api/v2`.

## Available Tools

#### `passman_list_vaults`
List the vaults with name, GUID, creation and last access time, and credential count.

#### `passman_list_credentials`
List the credentials of a vault: label, ID, GUID and non-secret flags.

**Parameters:**
- `vault_guid` (string, required) — from `passman_list_vaults`.

#### `passman_get_credential_info`
Get the non-secret metadata of one credential: label, ID, timestamps and flags.

**Parameters:**
- `vault_guid` (string, required)
- `credential_guid` (string, required) — from `passman_list_credentials`.

**Example:** "Which entries in my work vault haven't been changed in over a year?"
