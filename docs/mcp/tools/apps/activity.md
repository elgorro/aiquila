# Nextcloud Activity Tools

Integration with the Nextcloud Activity app. Read the per-user activity feed — file
changes, shares, comments, calendar/contact edits, and more — through Claude.

## Prerequisites

- Nextcloud Activity app must be installed and enabled (bundled with Nextcloud by default)
- Uses the Activity OCS API v2 (`/ocs/v2.php/apps/activity/api/v2/activity`)

## Available Tools

| Tool | Description |
|------|-------------|
| `list_activity` | List recent activity feed entries |
| `get_object_activity` | List activity history for a single object (e.g. one file) |

---

## list_activity

List recent entries from the activity feed for the current user.

Parameters:

- `filter` (optional) — which feed to read: `all` (default), `self` (your own actions),
  or `by` (others' actions)
- `limit` (optional) — maximum number of activities to return (default 50)
- `since` (optional) — return activities after this `activity_id` (for pagination)
- `sort` (optional) — time order: `desc` (newest first, default) or `asc`

The response includes the last `activity_id`, which can be passed back as `since` to page
through older entries.

## get_object_activity

List the activity history for a single object, e.g. one file.

Parameters:

- `object_type` (optional) — the object type to filter by (default `files`)
- `object_id` (required) — the object ID (e.g. the Nextcloud file ID)
- `limit` (optional) — maximum number of activities to return (default 50)
