# Nextcloud Share Tools

Create, change, audit and accept file and folder shares.

## Prerequisites

- The Files sharing app (app ID: `files_sharing`), which Nextcloud enables by default
- The `sharebymail` app for email shares, and Talk for `sendPasswordByTalk`

## API

Uses the [OCS Share API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html) at `/ocs/v2.php/apps/files_sharing/api/v1/`.

## Available Tools

### Shares

#### `list_shares`
List the shares of the current user.

**Parameters:**
- `path` (string, optional) — only shares of this file or folder.
- `reshares` (boolean, optional) — include reshares.
- `subfiles` (boolean, optional) — shares of all files in the folder at `path`.

**Example:** "Which of my files are shared by public link?"

#### `get_share`
Get everything the server knows about one share.

**Parameters:**
- `shareId` (number, required)

#### `create_share`
Share a file or folder.

**Parameters:**
- `path` (string, required)
- `shareType` (number, required) — `0` user, `1` group, `3` public link, `4` email, `6` federated.
- `shareWith` (string) — the recipient. Required except for public links.
- `permissions` (number, optional) — bitmask: `1` read, `2` update, `4` create, `8` delete, `16` share.
- `password` (string, optional)
- `sendPasswordByTalk` (boolean, optional) — see [Passwords](#passwords).
- `expireDate` (string, optional) — `YYYY-MM-DD`.
- `label` (string, optional) — label for a public link.
- `note` (string, optional) — note to the recipient.
- `hideDownload` (boolean, optional) — hide the download button on a public link.
- `attributes` (array, optional) — see [Attributes](#attributes).

**Returns:** the share ID and type, the token and URL for links, and any password and attribute details.

**Example:** "Share the report with Bob, read-only, and don't let him download it."

#### `update_share`
Change an existing share. Only the parameters passed are changed.

**Parameters:**
- `shareId` (number, required)
- `permissions`, `password`, `sendPasswordByTalk`, `expireDate`, `label`, `note`, `hideDownload`, `attributes` — as in `create_share`.

#### `delete_share`
Delete a share.

**Parameters:**
- `shareId` (number, required)

#### `list_shares_with_me`
List what other people share with the current user.

#### `search_sharees`
Find valid recipients for a share.

**Parameters:**
- `search` (string, required) — name or email to look for.
- `itemType` (enum, optional) — `file` (default) or `folder`.
- `shareTypes` (array of numbers, optional) — `0` user, `1` group, `3` public link, `4` email, `6` federated, `7` Team, `10` Talk conversation. Default `0`, `1`, `3`, `4`, `6`.

### Pending federated shares

#### `list_pending_shares`
List federated shares waiting to be accepted or declined.

#### `accept_pending_share`
Accept a pending share.

**Parameters:**
- `shareId` (number, required)

#### `decline_pending_share`
Decline a pending share.

**Parameters:**
- `shareId` (number, required)

## Passwords

`sendPasswordByTalk` turns on video verification for a link or email share: the recipient gets the password in a Talk call rather than by mail. It needs Talk and a password.

`create_share` refuses it on other share types and without a password. `update_share` only learns the share type from the server's answer, so there the change is made and the output says `Not applied`.

The password expiry time is reported, not set. An administrator enables it for email shares with the `sharing.enable_mail_link_password_expiration` and `sharing.mail_link_password_expiration_interval` system settings. The server then sets it when the password of an existing email share changes, not for a password given at creation.

## Attributes

Attributes are `scope`, `key` and `value` entries. The one in general use controls downloading:

```json
[{ "scope": "permissions", "key": "download", "value": false }]
```

`update_share` replaces all attributes of a share, so pass the ones to keep along with the change. A share whose parent forbids downloading always keeps `download` off.
