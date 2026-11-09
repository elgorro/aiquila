# Announcements Tools

Read and publish organization-wide notices in the [Announcement Center](https://apps.nextcloud.com/apps/announcementcenter).

## Prerequisites

- Announcement Center installed and enabled (app ID: `announcementcenter`)
- Creating and deleting announcements requires an account the app allows to post, by default an administrator

## API

Uses the Announcement Center OCS API at `/ocs/v2.php/apps/announcementcenter/api/v1/announcements`. The app documents it only through its [routes](https://github.com/nextcloud/announcementcenter/blob/main/appinfo/routes.php).

## Available Tools

#### `list_announcements`
List announcements, newest first.

**Parameters:**
- `offset` (number, optional) — return the announcements before this one, for paging. Default `0`.

#### `create_announcement`
Publish an announcement. Depending on the options it notifies or emails its audience, so use it deliberately.

**Parameters:**
- `subject` (string, required) — a short title.
- `message` (string, required) — the body, in Markdown.
- `plainMessage` (string, optional) — plain-text version. Default: `message`.
- `groups` (string[], optional) — group IDs to address. Default: `["everyone"]`.
- `activities` (boolean, optional) — publish to the activity stream. Default `true`.
- `notifications` (boolean, optional) — send notifications. Default `true`.
- `emails` (boolean, optional) — send emails. Default `false`.
- `comments` (boolean, optional) — allow comments. Default `true`.
- `scheduleTime` (number, optional) — Unix timestamp to publish at, instead of now.
- `deleteTime` (number, optional) — Unix timestamp at which the announcement deletes itself.

**Example:** "Announce Saturday's maintenance window to everyone, without email."

#### `delete_announcement`
Delete an announcement.

**Parameters:**
- `id` (number, required) — from `list_announcements`.
