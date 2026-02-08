# Nextcloud Tasks Tools

Integration with Nextcloud Tasks app via CalDAV protocol. Create, list, update, complete, and delete tasks directly through Claude.

## Prerequisites

- Nextcloud Tasks app must be installed and enabled
- At least one task list (calendar) must exist
- CalDAV access must be enabled (default in Nextcloud)

## Available Tools

### list_task_lists

List all task lists (calendars) available in Nextcloud Tasks.

**Parameters:**
None

**Returns:**
XML response from CalDAV containing task list information including:
- Display names
- Calendar URLs
- Supported component types (VTODO for tasks)

**Example Usage:**
```
Ask Claude: "Show me my task lists"
Ask Claude: "What task lists do I have in Nextcloud?"
```

**Common Task List Names:**
- `personal` - Default personal task list
- `work` - Work-related tasks
- `tasks` - General task list

---

### list_tasks

List tasks from a Nextcloud Tasks calendar with full field details.

**Parameters:**
- `calendarName` (string, optional): The calendar/task list name. Default: `"tasks"`
- `status` (enum, optional): Filter by status - `NEEDS-ACTION`, `COMPLETED`, `IN-PROCESS`, `CANCELLED`

**Returns:**
Formatted task list showing:
- Checkbox status (`[x]` / `[ ]`)
- Summary, priority label, percent complete
- Dates (start, due, completed)
- Location, tags, parent task, description, classification
- UID (for use with update/delete/complete tools)

**Example Usage:**
```
Ask Claude: "List my tasks"
Ask Claude: "Show completed tasks in my work list"
Ask Claude: "What tasks are in progress?"
```

---

### create_task

Create a new task in a Nextcloud task list.

**Parameters:**
- `summary` (string, required): The task title/summary
- `calendarName` (string, optional): The calendar/task list name. Default: `"tasks"`
- `description` (string, optional): Detailed description of the task
- `priority` (number, optional): Task priority from 0-9 (0=undefined, 1=highest, 9=lowest)
- `due` (string, optional): Due date in `YYYYMMDD` or `YYYYMMDDTHHmmssZ` format
- `dtstart` (string, optional): Start date in `YYYYMMDD` or `YYYYMMDDTHHmmssZ` format
- `location` (string, optional): Location string
- `categories` (string[], optional): Tags/categories (e.g. `['work', 'urgent']`)
- `relatedTo` (string, optional): Parent task UID (creates subtask relationship)
- `percentComplete` (number, optional): Completion percentage (0-100)
- `classification` (enum, optional): `PUBLIC`, `PRIVATE`, or `CONFIDENTIAL`
- `url` (string, optional): Custom URL reference

**Returns:**
Success message with task summary and UID.

**Example Usage:**
```
Ask Claude: "Create a task 'Review pull requests'"
Ask Claude: "Add a high-priority task 'Deploy to production' to my work list with due date 20240320"
Ask Claude: "Create a task 'Buy groceries' tagged 'shopping' with location 'Supermarket'"
Ask Claude: "Create a subtask 'Write unit tests' under parent task uid-123"
```

**Example with multiple parameters:**
```json
{
  "summary": "Complete project documentation",
  "calendarName": "work",
  "description": "Update README, API docs, and deployment guide",
  "priority": 1,
  "due": "20240320",
  "categories": ["docs", "project-x"],
  "classification": "PRIVATE"
}
```

---

### update_task

Update an existing task's fields by UID. Uses CalDAV ETag-based optimistic concurrency to prevent conflicts.

**Parameters:**
- `uid` (string, required): The UID of the task to update
- `calendarName` (string, optional): The calendar name. Default: `"tasks"`
- `summary` (string, optional): New task title
- `description` (string, optional): New description
- `priority` (number, optional): New priority (0-9, 0 removes priority)
- `due` (string|null, optional): New due date, or `null` to remove
- `dtstart` (string|null, optional): New start date, or `null` to remove
- `status` (enum, optional): `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED`, `CANCELLED`
- `location` (string|null, optional): New location, or `null` to remove
- `categories` (string[], optional): Replace all tags with these
- `relatedTo` (string|null, optional): New parent UID, or `null` to remove
- `percentComplete` (number, optional): New completion percentage (0-100)
- `classification` (enum, optional): `PUBLIC`, `PRIVATE`, or `CONFIDENTIAL`
- `url` (string|null, optional): New URL, or `null` to remove
- `pinned` (boolean, optional): Pin or unpin the task

**Returns:**
Success message confirming update.

**Example Usage:**
```
Ask Claude: "Update task uid-123 to have priority 1"
Ask Claude: "Change the due date of task uid-456 to March 25th"
Ask Claude: "Add tags 'urgent' and 'review' to task uid-789"
Ask Claude: "Remove the due date from task uid-123"
```

---

### complete_task

Mark a task as completed or reopen it. Sets STATUS, PERCENT-COMPLETE, and COMPLETED date atomically.

**Parameters:**
- `uid` (string, required): The UID of the task
- `calendarName` (string, optional): The calendar name. Default: `"tasks"`
- `completed` (boolean, optional): `true` = mark completed, `false` = reopen. Default: `true`

**Returns:**
Success message confirming completion or reopening.

**Example Usage:**
```
Ask Claude: "Mark task uid-123 as done"
Ask Claude: "Complete task uid-456"
Ask Claude: "Reopen task uid-789"
```

---

### delete_task

Delete a task from Nextcloud Tasks by UID. This action is irreversible.

**Parameters:**
- `uid` (string, required): The UID of the task to delete
- `calendarName` (string, optional): The calendar name. Default: `"tasks"`

**Returns:**
Success message confirming deletion.

**Example Usage:**
```
Ask Claude: "Delete task uid-123"
Ask Claude: "Remove task uid-456 from my work list"
```

---

## Priority Levels

| Priority | Level | Use Case |
|----------|-------|----------|
| 0 | Undefined | No specific priority |
| 1 | Highest | Urgent, critical tasks |
| 2-4 | High | Important tasks |
| 5 | Medium | Normal priority |
| 6-8 | Low | Can wait |
| 9 | Lowest | Nice to have |

## Task Status

| Status | Description |
|--------|-------------|
| `NEEDS-ACTION` | Not started (default for new tasks) |
| `IN-PROCESS` | Currently being worked on |
| `COMPLETED` | Finished |
| `CANCELLED` | Cancelled |

## Workflow Examples

### Creating and Completing Tasks
```
User: "Create a task to review documentation, then mark it done when I say so"
Claude: Creates task "Review documentation" -> returns UID
User: "Done with that"
Claude: Uses complete_task with the UID
```

### Managing Subtasks
```
User: "Create a project task 'Launch website' and add subtasks for design, development, and testing"
Claude: Creates parent task -> uses its UID as relatedTo for 3 subtasks
```

### Batch Updates
```
User: "List my tasks and mark all shopping tasks as high priority"
Claude: Lists tasks -> identifies shopping tasks by tags/summary -> updates each with priority 1
```

### Task Lifecycle
```
User: "Create a task 'Fix login bug' with tags 'bug' and 'urgent'"
Claude: Creates task with categories ['bug', 'urgent']
User: "I'm working on the login bug now"
Claude: Updates task status to IN-PROCESS
User: "Login bug is fixed"
Claude: Marks task as completed
```

## Capabilities

- List task lists
- List tasks with full field details
- Create tasks with dates, tags, location, subtask relationships, classification
- Update any task field
- Mark tasks complete / reopen
- Delete tasks
- ETag-based conflict detection for safe concurrent access

## CalDAV Technical Details

### Authentication
Uses HTTP Basic Authentication with your Nextcloud credentials.

### Endpoint
```
https://your-nextcloud.com/remote.php/dav/calendars/{username}/{calendar-name}/
```

### Methods
- **PROPFIND**: List task lists and their properties
- **REPORT**: Query tasks with filters
- **PUT**: Create or update tasks (VTODO)
- **DELETE**: Remove tasks

### Content Type
```
Content-Type: text/calendar; charset=utf-8
```

## Troubleshooting

### "Calendar not found"
**Problem**: Task list doesn't exist

**Solution**:
- Use `list_task_lists` to see available calendars
- Create a new task list in Nextcloud Tasks app
- Use exact calendar name (case-sensitive)

---

### "Task not found"
**Problem**: UID doesn't match any task in the calendar

**Solution**:
- Use `list_tasks` to get current task UIDs
- Check you're using the correct calendar name

---

### "ETag mismatch"
**Problem**: Task was modified by another client between read and write

**Solution**:
- Retry the operation (it will fetch the latest version)

---

### "Failed to create task"
**Problem**: CalDAV authentication or permission error

**Solution**:
- Verify Nextcloud credentials are correct
- Check that Tasks app is installed and enabled
- Ensure CalDAV is enabled in Nextcloud settings

## Integration with Other Tools

### With Notes Tool
```
User: "Create a note with my project plan, then create tasks for each milestone"
Claude: Uses create_note to save the plan, then create_task for each milestone
```

### With File System Tools
```
User: "Read my todo.txt file and create tasks for each item"
Claude: Uses read_file then creates tasks with create_task
```

## Security

- Tasks are created with your user permissions
- Task data is stored in Nextcloud database
- CalDAV uses HTTPS encryption
- Use app passwords for better security
- ETag-based concurrency prevents accidental overwrites

## Development

To extend task tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/tasks.ts](../../../../mcp-server/src/tools/apps/tasks.ts)
- CalDAV client: [mcp-server/src/client/caldav.ts](../../../../mcp-server/src/client/caldav.ts)

## References

- [Nextcloud Tasks App](https://apps.nextcloud.com/apps/tasks)
- [CalDAV RFC 4791](https://tools.ietf.org/html/rfc4791)
- [iCalendar RFC 5545](https://tools.ietf.org/html/rfc5545)
- [Nextcloud CalDAV Docs](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/CalDAV/)
