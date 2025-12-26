# AIquila MCP Server

MCP (Model Context Protocol) server that connects Claude to your Nextcloud instance.

## Features

- **File Management**: List, read, write, delete files and folders
- **Tasks**: Create tasks in Nextcloud Tasks app
- **Notes**: Create notes in Notes folder
- **Recipes**: Add recipes to Recipes folder

## Setup

### 1. Install dependencies

```bash
cd mcp-server
npm install
npm run build
```

### 2. Configure Claude Desktop

Add to `~/.config/claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://your-nextcloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

**Note**: Use an App Password from Nextcloud (Settings → Security → Devices & sessions).

### 3. Restart Claude Desktop

The tools will be available in Claude conversations.

## Available Tools

| Tool | Description |
|------|-------------|
| `list_files` | List files in a directory |
| `read_file` | Read file contents |
| `write_file` | Create/update a file |
| `create_folder` | Create a folder |
| `delete` | Delete file/folder |
| `list_task_lists` | List task lists |
| `create_task` | Create a task |
| `create_note` | Create a note |
| `add_recipe` | Add a recipe |

## Example Usage (in Claude)

- "Create a task to buy groceries in my personal list"
- "Save a note about today's meeting"
- "Add a recipe for pasta carbonara"
- "List files in my Documents folder"
