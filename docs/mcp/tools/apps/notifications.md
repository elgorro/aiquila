# Notifications Tools

Read and dismiss the signed-in user's Nextcloud notifications.

## Prerequisites

- Notifications app enabled (app ID: `notifications`), which ships with Nextcloud

## API

Uses the [Notifications OCS API v2](https://github.com/nextcloud/notifications/blob/master/docs/ocs-endpoint-v2.md) at `/ocs/v2.php/apps/notifications/api/v2/notifications`.

## Available Tools

#### `list_notifications`
List the notifications, with ID, app, time, subject and message.

#### `get_notification`
Get one notification in full, including its link and actions.

**Parameters:**
- `id` (number, required)

#### `mark_notification_read`
Dismiss a notification. Nextcloud has no read state for notifications, so this deletes it.

**Parameters:**
- `id` (number, required)

#### `delete_all_notifications`
Dismiss every notification of the signed-in user.
