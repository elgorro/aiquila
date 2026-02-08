# System Tools Documentation

System tools provide core operations for your Nextcloud instance including file management (via WebDAV) and system diagnostics (via OCC commands).

## Overview

System tools are divided into two categories:
- **File Operations**: WebDAV-based file and folder management
- **System Status**: OCC-based system diagnostics and monitoring

## File Operation Tools

All file operation tools use WebDAV. Paths are relative to your user's home directory (e.g., `/Documents/file.txt`).

### list_files

List files and folders in a Nextcloud directory.

**Parameters:**
- `path` (string, optional): The directory path to list. Default: `/` (root directory)

**Returns:**
JSON array of file and folder information including:
- `basename`: File/folder name
- `filename`: Full path
- `lastmod`: Last modified date
- `size`: File size in bytes
- `type`: `"file"` or `"directory"`
- `etag`: Entity tag for caching

**Example Usage:**
```
Ask Claude: "List the files in my Documents folder"
Ask Claude: "Show me what's in /Photos/2024/"
Ask Claude: "What folders are in my root directory?"
```

**Example Response:**
```json
[
  {
    "basename": "project-plan.md",
    "filename": "/Documents/project-plan.md",
    "lastmod": "2024-01-15T10:30:00Z",
    "size": 2048,
    "type": "file"
  },
  {
    "basename": "meeting-notes",
    "filename": "/Documents/meeting-notes",
    "type": "directory"
  }
]
```

---

### read_file

Read the contents of a file from Nextcloud.

**Parameters:**
- `path` (string, required): The file path to read

**Returns:**
The file contents as text.

**Example Usage:**
```
Ask Claude: "Read the file /Documents/readme.txt"
Ask Claude: "What's in my config.json file?"
Ask Claude: "Show me the contents of /Notes/ideas.md"
```

**Notes:**
- Binary files will be returned as text representation
- Large files may be truncated
- Supports all text formats (txt, md, json, xml, etc.)

---

### write_file

Create or update a file in Nextcloud.

**Parameters:**
- `path` (string, required): The file path to write
- `content` (string, required): The content to write to the file

**Returns:**
Success message with the file path.

**Example Usage:**
```
Ask Claude: "Create a file called /Documents/meeting-notes.md with today's meeting summary"
Ask Claude: "Write 'Hello World' to /test.txt"
Ask Claude: "Update /config.json with the new configuration"
```

**Notes:**
- Automatically creates parent directories if they don't exist
- Overwrites existing files
- Supports all text formats

**Example:**
```json
{
  "path": "/Documents/todo.md",
  "content": "# TODO\n\n- Review pull requests\n- Update documentation\n- Deploy to production"
}
```

---

### create_folder

Create a folder in Nextcloud.

**Parameters:**
- `path` (string, required): The folder path to create

**Returns:**
Success message with the folder path.

**Example Usage:**
```
Ask Claude: "Create a folder called /Documents/2024"
Ask Claude: "Make a new directory /Projects/AIquila"
Ask Claude: "Create /Photos/Vacation folder"
```

**Notes:**
- Creates parent directories if they don't exist
- Returns success even if folder already exists
- Use forward slashes for path separators

---

### delete

Delete a file or folder from Nextcloud.

**Parameters:**
- `path` (string, required): The path to delete

**Returns:**
Success message confirming deletion.

**Example Usage:**
```
Ask Claude: "Delete the file /temp/old-data.txt"
Ask Claude: "Remove the /archive folder"
Ask Claude: "Delete /Documents/draft.md"
```

**Warning:**
- Deletion is permanent and cannot be undone via MCP
- Deleting a folder removes all its contents recursively
- Check Nextcloud trash/recycle bin if you need to recover files

---

## Error Handling

All system tools handle common errors gracefully:

- **Authentication errors**: Invalid credentials
- **Not found errors**: File or folder doesn't exist
- **Permission errors**: Insufficient permissions
- **Network errors**: Connection issues

Error messages are returned in plain text format.

## Path Format

All paths should:
- Start with `/` (relative to user home directory)
- Use forward slashes `/` (not backslashes `\`)
- Be URL-safe (avoid special characters)

**Examples:**
- ✅ `/Documents/project.md`
- ✅ `/Photos/2024/vacation.jpg`
- ✅ `/Backup/data-2024-01-15.zip`
- ❌ `Documents/project.md` (missing leading slash)
- ❌ `\Documents\project.md` (backslashes)

## Performance Considerations

- **Listing large directories**: May take time for folders with many files
- **Reading large files**: Content may be truncated for very large files
- **Network latency**: All operations depend on Nextcloud server response time

## Related Tools

- **Tasks**: Create and manage tasks → [Tasks Documentation](apps/tasks.md)
- **Notes**: Create notes as markdown files → [Notes Documentation](apps/notes.md)
- **Cookbook**: Add recipes as markdown files → [Cookbook Documentation](apps/cookbook.md)

## Security

- All operations use your Nextcloud credentials
- Files are accessed with your user permissions
- App passwords are recommended over main password
- See [Setup Guide](../setup.md#security-best-practice-app-passwords)

---

## System Status & Diagnostics Tools

These tools provide system status information and diagnostics via OCC commands.

### system_status

Get Nextcloud system status including version, installation path, and configuration.

**Parameters:**
None

**Returns:**
OCC command instructions to display system status in JSON format.

**Example Usage:**
```
Ask Claude: "Check Nextcloud system status"
Ask Claude: "What version of Nextcloud is running?"
Ask Claude: "Show me system information"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ status
```

**Example Output:**
```json
{
  "installed": true,
  "version": "28.0.1.1",
  "versionstring": "28.0.1",
  "edition": "",
  "maintenance": false,
  "needsDbUpgrade": false,
  "productname": "Nextcloud",
  "extendedSupport": false
}
```

---

### run_setup_checks

Run Nextcloud setup checks to verify security and configuration.

**Parameters:**
None

**Returns:**
OCC command instructions to run comprehensive system checks on security, performance, and configuration.

**Example Usage:**
```
Ask Claude: "Run Nextcloud setup checks"
Ask Claude: "Check my Nextcloud configuration"
Ask Claude: "Verify my system security"
```

**Example Command:**
```bash
docker exec -u www-data aiquila-nextcloud php occ setupchecks
```

**What It Checks:**
- **Security**: HTTPS, headers, PHP settings, database security
- **Performance**: PHP memory, opcache, database config, caching
- **Configuration**: PHP modules, database compatibility, cron jobs, file locking

**Example Output:**
```
[✅] PHP version: 8.2.15 - OK
[✅] Database: MySQL 8.0.35 - OK
[⚠️ ] PHP memory limit: 512M (recommended: 1024M)
[✅] HTTPS configured correctly
[❌] Cron not configured - using AJAX
```

---

## App Management Tools

Tools for managing Nextcloud apps via OCC commands.

### list_apps

List all installed Nextcloud apps with their status.

**Parameters:**
- `showDisabled` (boolean, optional): Show only disabled apps

**Returns:**
OCC command instructions to list apps with enabled/disabled status.

**Example Usage:**
```
Ask Claude: "List all Nextcloud apps"
Ask Claude: "Show me disabled apps"
Ask Claude: "What apps are installed?"
```

---

### get_app_info

Get detailed information about a specific app.

**Parameters:**
- `appId` (string, required): App ID (e.g., "tasks", "deck", "photos")

**Returns:**
OCC command instructions to get app installation path and details.

**Example Usage:**
```
Ask Claude: "Get info about the tasks app"
Ask Claude: "Show me details for the deck app"
```

---

### enable_app

Enable a disabled Nextcloud app.

**Parameters:**
- `appId` (string, required): App ID to enable

**Returns:**
OCC command instructions to enable the app.

**Example Usage:**
```
Ask Claude: "Enable the tasks app"
Ask Claude: "Turn on the photos app"
```

---

### disable_app

Disable an enabled Nextcloud app.

**Parameters:**
- `appId` (string, required): App ID to disable

**Returns:**
OCC command instructions to disable the app with warnings.

**Example Usage:**
```
Ask Claude: "Disable the survey app"
Ask Claude: "Turn off the firstrunwizard app"
```

---

## Security & Integrity Tools

Tools for verifying system and app integrity via file signature checks.

### check_core_integrity

Verify Nextcloud core system integrity.

**Parameters:**
None

**Returns:**
OCC command instructions to check core file signatures and detect unauthorized modifications.

**Example Usage:**
```
Ask Claude: "Check Nextcloud core integrity"
Ask Claude: "Verify system files haven't been tampered with"
Ask Claude: "Run security integrity check"
```

**What It Checks:**
- Core file modifications
- Extra files that shouldn't exist
- Missing required files
- File signature mismatches

---

### check_app_integrity

Verify integrity of a specific app.

**Parameters:**
- `appId` (string, required): App ID to check (e.g., "tasks", "deck")

**Returns:**
OCC command instructions to check app file signatures.

**Example Usage:**
```
Ask Claude: "Check integrity of tasks app"
Ask Claude: "Verify the deck app files"
Ask Claude: "Has the photos app been modified?"
```

---

## Development

To extend system tools or add new operations:
- See [Adding Tools Guide](../development/adding-tools.md)
- Source code:
  - File tools: [mcp-server/src/tools/system/files.ts](../../../mcp-server/src/tools/system/files.ts)
  - Status tools: [mcp-server/src/tools/system/status.ts](../../../mcp-server/src/tools/system/status.ts)
  - App tools: [mcp-server/src/tools/system/apps.ts](../../../mcp-server/src/tools/system/apps.ts)
  - Security tools: [mcp-server/src/tools/system/security.ts](../../../mcp-server/src/tools/system/security.ts)
