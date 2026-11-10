# Out-of-Office Tools

Read, set and clear an absence period: the dates, a status label, a message for others and an optional replacement.

## Prerequisites

- Nextcloud's `dav` component, which is always present. The category is always registered.
- Setting or clearing another user's absence needs the rights Nextcloud requires for that, normally an administrator.

## API

Uses the [Out-of-office OCS API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-out-of-office-api.html) at `/ocs/v2.php/apps/dav/api/v1/outOfOffice/<userId>`.

## Available Tools

#### `get_out_of_office`
Get a user's absence, whether it is active now or scheduled for later. The result includes `firstDay` and `lastDay`.

**Parameters:**
- `userId` (string, optional) — default: the signed-in user.

#### `set_out_of_office`
Set an absence period. It replaces any absence the user already has.

**Parameters:**
- `firstDay` (string, required) — `YYYY-MM-DD`.
- `lastDay` (string, required) — `YYYY-MM-DD`.
- `status` (string, required) — short label, such as `Vacation`.
- `message` (string, required) — the message shown to others.
- `replacementUserId` (string, optional) — who covers during the absence.
- `userId` (string, optional) — default: the signed-in user.

**Example:** "I'm on holiday from 22 December to 2 January. Anna covers for me."

#### `clear_out_of_office`
Remove a user's absence.

**Parameters:**
- `userId` (string, optional) — default: the signed-in user.
