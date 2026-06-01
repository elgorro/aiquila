# Nextcloud Calendar Tools

Integration with Nextcloud Calendar app via CalDAV protocol. Create, list, update, and delete calendar events with full support for recurrence, attendees, and alarms.

## Prerequisites

- Nextcloud Calendar app must be installed and enabled
- At least one calendar must exist
- CalDAV access must be enabled (default in Nextcloud)

## Available Tools

| Tool | Description |
|------|-------------|
| `list_calendars` | List all calendars |
| `list_events` | List events in a time range |
| `get_event` | Get full event details by UID |
| `create_event` | Create a new event |
| `update_event` | Update an existing event |
| `delete_event` | Delete an event |

---

### list_calendars

List all calendars available to the current user, including their supported component types (events, tasks, journals) and metadata.

**Parameters:**
None

**Returns:**
List of calendars with display names, URLs, and supported types.

**Example Usage:**
```
Ask Claude: "Show my calendars"
Ask Claude: "What calendars do I have?"
```

---

### list_events

List events from a Nextcloud calendar within an optional time range.

**Parameters:**
- `calendarName` (string, required): The calendar name
- `from` (string, optional): Start of time range in `YYYYMMDD` or `YYYYMMDDTHHmmssZ` format (defaults to today)
- `to` (string, optional): End of time range (defaults to 30 days from now)
- `limit` (number, optional): Maximum number of events to return (default 50, max 200)

**Returns:**
List of events with time, location, attendees, recurrence, and UIDs.

**Example Usage:**
```
Ask Claude: "Show my events for this week"
Ask Claude: "List events in my work calendar from March 1 to March 31"
Ask Claude: "What's on my personal calendar tomorrow?"
```

---

### get_event

Get detailed information about a single calendar event by its UID.

**Parameters:**
- `uid` (string, required): The UID of the event
- `calendarName` (string, required): The calendar name

**Returns:**
Complete event details including full description, attendees, and recurrence rules.

**Example Usage:**
```
Ask Claude: "Get details for event uid-abc123 in my personal calendar"
```

---

### create_event

Create a new calendar event with date/time, location, description, attendees, and optional recurrence.

**Parameters:**
- `summary` (string, required): The event title
- `calendarName` (string, required): The calendar name
- `dtstart` (string, required): Start date/time in `YYYYMMDD` or `YYYYMMDDTHHmmssZ` format
- `dtend` (string, optional): End date/time (defaults based on start time)
- `location` (string, optional): Event location
- `description` (string, optional): Event description
- `status` (enum, optional): `TENTATIVE`, `CONFIRMED`, `CANCELLED`
- `transp` (enum, optional): `OPAQUE` (busy) or `TRANSPARENT` (free)
- `categories` (string[], optional): Tags/categories
- `attendees` (object[], optional): Attendees with email, display name, role, RSVP
- `rrule` (string, optional): Recurrence rule (e.g. `FREQ=WEEKLY;BYDAY=MO,WE,FR`)
- `accessClass` (enum, optional): `PUBLIC`, `PRIVATE`, `CONFIDENTIAL`
- `alarm` (number, optional): Single reminder in minutes before the event
- `alarms` (number[], optional): Multiple reminders in minutes before the event (e.g. `[1440, 60]` for 1 day and 1 hour before). Combined with `alarm` if both are set.

**Returns:**
Confirmation with event UID.

**Example Usage:**
```
Ask Claude: "Create an event 'Team Standup' every weekday at 9am in my work calendar"
Ask Claude: "Add a meeting on March 25th from 2pm to 3pm with Alice and Bob"
Ask Claude: "Schedule a birthday party on 20260401 in my personal calendar"
```

**Example with recurrence and attendees:**
```json
{
  "summary": "Weekly Sync",
  "calendarName": "work",
  "dtstart": "20260325T140000Z",
  "dtend": "20260325T150000Z",
  "location": "Conference Room B",
  "rrule": "FREQ=WEEKLY;BYDAY=TU",
  "attendees": [
    { "email": "alice@example.com", "displayName": "Alice", "rsvp": true }
  ],
  "alarm": 15
}
```

---

### update_event

Update an existing calendar event's fields by UID. Uses CalDAV ETag-based optimistic concurrency.

**Parameters:**
- `uid` (string, required): The UID of the event to update
- `calendarName` (string, required): The calendar name
- `summary` (string, optional): New event title
- `dtstart` (string, optional): New start date/time
- `dtend` (string|null, optional): New end date/time, or `null` to remove
- `location` (string|null, optional): New location, or `null` to remove
- `description` (string|null, optional): New description, or `null` to remove
- `status` (enum, optional): New status
- `transp` (enum, optional): New transparency
- `categories` (string[], optional): Replace all tags
- `accessClass` (enum, optional): New classification
- `rrule` (string|null, optional): New recurrence rule, or `null` to remove
- `alarm` (number|null, optional): Single reminder in minutes before the event, or `null` to remove all existing alarms
- `alarms` (number[], optional): Multiple reminders in minutes before the event (e.g. `[1440, 60]`). Replaces existing alarms; combined with `alarm` if both are set.

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Move event uid-123 to 3pm tomorrow"
Ask Claude: "Change the location of event uid-456 to 'Room A'"
Ask Claude: "Make event uid-789 repeat weekly"
```

---

### delete_event

Delete a calendar event by UID. This action is irreversible.

**Parameters:**
- `uid` (string, required): The UID of the event to delete
- `calendarName` (string, required): The calendar name

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Delete event uid-123 from my work calendar"
Ask Claude: "Cancel the meeting uid-456"
```

---

## Recurrence Rules

Common `rrule` patterns:

| Pattern | Meaning |
|---------|---------|
| `FREQ=DAILY` | Every day |
| `FREQ=WEEKLY;BYDAY=MO,WE,FR` | Mon, Wed, Fri |
| `FREQ=MONTHLY;BYMONTHDAY=1` | 1st of every month |
| `FREQ=YEARLY` | Once a year |
| `FREQ=WEEKLY;COUNT=10` | Weekly, 10 occurrences |
| `FREQ=DAILY;UNTIL=20261231T235959Z` | Daily until Dec 31, 2026 |

## Workflow Examples

### Schedule a Recurring Meeting
```
User: "Create a weekly team meeting on Mondays at 10am with a 15-minute reminder"
Claude: Creates event with rrule FREQ=WEEKLY;BYDAY=MO and alarm=15
```

### Calendar Review
```
User: "What's on my calendar next week?"
Claude: Lists events for the next 7 days, summarizes them
```

## Development

To extend calendar tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/calendar.ts](../../../../mcp-server/src/tools/apps/calendar.ts)

## References

- [Nextcloud Calendar App](https://apps.nextcloud.com/apps/calendar)
- [CalDAV RFC 4791](https://tools.ietf.org/html/rfc4791)
- [iCalendar RFC 5545](https://tools.ietf.org/html/rfc5545)
- [Nextcloud CalDAV Docs](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/CalDAV/)
