# Nextcloud Calendar Tools

Integration with Nextcloud Calendar app via CalDAV protocol. Create, list, update, and delete calendar events with full support for recurrence, attendees, and alarms, and find and book meeting rooms.

## Prerequisites

- Nextcloud Calendar app must be installed and enabled
- At least one calendar must exist
- CalDAV access must be enabled (default in Nextcloud)
- For rooms and resources: a backend app that provides them, such as [Calendar Resource Management](https://apps.nextcloud.com/apps/calendar_resource_management) (`calendar_resource_management`), and an email address in the organizer's profile

## Available Tools

| Tool | Description |
|------|-------------|
| `list_calendars` | List all calendars |
| `list_events` | List events in a time range |
| `get_event` | Get full event details by UID |
| `create_event` | Create a new event |
| `update_event` | Update an existing event |
| `delete_event` | Delete an event |
| `list_calendar_rooms` | List bookable rooms and resources |
| `get_free_busy` | Show when rooms, resources or people are busy |
| `find_free_rooms` | Find the rooms free for a time range |

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

Each time is shown as stored, with the zone it is expressed in: `2026-11-12 07:45 UTC` for a
UTC time, `2026-11-12 08:45 (Europe/Paris)` for a time with a time zone (RFC 5545 §3.3.5,
forms 2 and 3), and no zone for a floating time (form 1). Times are not converted.

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
Complete event details including full description, attendees, and recurrence rules. Times
are shown with their zone, as for `list_events`.

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
- `attendees` (object[], optional): Attendees, each with `email`, and optionally `cn` (display name), `role`, `rsvp` (default `true`) and `cutype` (`INDIVIDUAL`, `GROUP`, `RESOURCE`, `ROOM` or `UNKNOWN`; RFC 5545 §3.2.3). `ROOM` or `RESOURCE` books a room or resource, see [Rooms and resources](#rooms-and-resources). The signed-in user becomes the `ORGANIZER`, addressed by the email set in their Nextcloud profile. Without one the property is left out, and Nextcloud sends no invitations and books no rooms
- `rrule` (string, optional): Recurrence rule (e.g. `FREQ=WEEKLY;BYDAY=MO,WE,FR`)
- `accessClass` (enum, optional): `PUBLIC`, `PRIVATE`, `CONFIDENTIAL`
- `alarm` (number, optional): Single reminder in minutes before the event
- `alarms` (number[], optional): Multiple reminders in minutes before the event (e.g. `[1440, 60]` for 1 day and 1 hour before). Combined with `alarm` if both are set.

**Returns:**
Confirmation with event UID, and for each room or resource whether it was booked.

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
    { "email": "alice@example.com", "cn": "Alice", "rsvp": true }
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
- `attendees` (object[]|null, optional): Replace all attendees, as in `create_event`, or `null` to remove them and the organizer. An existing `ORGANIZER` with an email address is kept; otherwise it is set as for `create_event`. When the event has rooms or resources, the result says whether each accepted

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

## Rooms and resources

Nextcloud keeps bookable rooms and resources as CalDAV principals under `principals/calendar-rooms` and `principals/calendar-resources`. A backend app fills them, such as Calendar Resource Management, where an administrator sets them up. Without one, the room tools report that none exist.

### list_calendar_rooms

List the rooms and resources with their email address, type, building, floor, room number, capacity, features and contact person.

**Parameters:**
- `kind` (enum, optional): `rooms`, `resources` or `all` (default)
- `minCapacity` (integer, optional): only rooms for at least this many people
- `features` (string[], optional): features a room must have: `PHONE`, `VIDEO-CONFERENCING`, `TV`, `PROJECTOR`, `WHITEBOARD`, `WHEELCHAIR-ACCESSIBLE`

### get_free_busy

Show when rooms, resources or people are busy in a time range. It sends a free-busy request through the signed-in user's scheduling outbox (RFC 6638 §5), so it shows the times other calendars are busy without their event details.

**Parameters:**
- `start`, `end` (string, required): UTC, as `YYYYMMDDTHHmmssZ` or ISO 8601 with `Z` or an offset
- `addresses` (string[], required): email addresses of rooms, resources or Nextcloud users, up to 50

An address that belongs to no one on this Nextcloud is reported as unknown.

### find_free_rooms

Find the rooms that are free for the whole range, with the busy times of the others.

**Parameters:**
- `start`, `end` (string, required): as for `get_free_busy`
- `minCapacity` (integer, optional), `features` (string[], optional): as for `list_calendar_rooms`

**Example:** "Find a room for eight people with video conferencing tomorrow from 2 to 3 pm, and book it for the planning meeting."

### Booking

Book a room by inviting it: pass its email address in `attendees` of `create_event` or `update_event`, with `cutype` `ROOM` (or `RESOURCE` for a resource). Nextcloud answers for the room while the event is saved: it accepts if the room is free and declines if it is already booked. The tool reads the event back and reports which:

- **booked**: the room accepted.
- **DECLINED**: the room was already booked at that time. The tool takes it off the event's attendees. Nextcloud keeps a declined booking in the room's calendar, where free/busy counts it as busy until the room is removed from the event. If removing it fails (for example because the event changed in between), the report says so and asks you to remove the room yourself.
- **not answered**: Nextcloud does not answer for recurring events, so a recurring booking stays unconfirmed.
- **not booked**: the event has no organizer, because the signed-in user has no email address in their profile, so the room was never asked.

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

## Input validation

Dates, recurrence rules, time zones and attendee addresses are written into the event
unescaped, so they are checked first. A value that fails the check rejects the call:

- `dtstart`, `dtend`: `YYYYMMDD`, `YYYYMMDDTHHmmss` or `YYYYMMDDTHHmmssZ` (RFC 5545 §3.3.4,
  §3.3.5). ISO forms such as `2026-03-15T10:00:00Z` are rejected.
- `rrule`: letters, digits and `= ; , + -` only, i.e. RFC 5545 §3.3.10 rule parts without
  the `RRULE:` prefix.
- `tzid`: an IANA name such as `Europe/Berlin`.
- `attendees[].email`: a valid email address.

An attendee's display name is free text. It is written as a quoted parameter value (RFC 5545
§3.2), with line breaks and double quotes removed. Task dates (`due`, `dtstart`) follow the
same date rule.

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
- [CalDAV Scheduling RFC 6638](https://www.rfc-editor.org/rfc/rfc6638)
