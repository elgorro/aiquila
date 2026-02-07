# AIquila MCP Server

MCP (Model Context Protocol) server that connects Claude to your Nextcloud instance.

## Features

- **File Management**: List, read, write, delete files and folders (5 tools)
- **Tasks**: Create and manage tasks in Nextcloud Tasks app (2 tools)
- **Notes**: Create notes in Notes folder (1 tool)
- **Recipes**: Add recipes to Cookbook (1 tool)
- **AIquila Config**: Configure and test AIquila settings (3 tools)

**Total: 12 tools**

## Quick Links

- 📚 **[Complete Documentation](../docs/mcp/README.md)** - Full MCP server documentation
- ⚙️ **[Setup Guide](../docs/mcp/setup.md)** - Detailed installation instructions
- 🛠️ **[Tools Reference](../docs/mcp/README.md#tools-reference)** - All available tools
- 📖 **[Development Guides](../docs/mcp/development/)** - Extend the server

## Quick Setup

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
| `aiquila_show_config` | Show AIquila configuration (API key, model, tokens, timeout) |
| `aiquila_configure` | Configure AIquila settings |
| `aiquila_test` | Test AIquila Claude API integration |

## Example Usage (in Claude)

- "Create a task to buy groceries in my personal list"
- "Save a note about today's meeting"
- "Add a recipe for pasta carbonara"
- "List files in my Documents folder"

## Documentation

For complete documentation, see:

- **[MCP Server Documentation](../docs/mcp/README.md)** - Overview and tools reference
- **[Setup Guide](../docs/mcp/setup.md)** - Detailed configuration
- **[System Tools](../docs/mcp/tools/system-tools.md)** - File operations
- **[App Tools](../docs/mcp/tools/apps/)** - Tasks, Cookbook, Notes, AIquila
- **[Architecture](../docs/mcp/development/architecture.md)** - Design and internals
- **[Adding Tools](../docs/mcp/development/adding-tools.md)** - Extend existing apps
- **[Adding Apps](../docs/mcp/development/adding-apps.md)** - Add new Nextcloud apps

## Development

```bash
# Hot reload development
npm run dev

# Run tests
npm test

# Build for production
npm run build

# Lint code
npm run lint

# Format code
npm run format
```

See [MCP Server Architecture](../docs/dev/mcp-server-architecture.md) for technical details.

## Architecture

The server uses a modular architecture:

```
src/
├── index.ts              # Main server & registration
├── client/               # Infrastructure (WebDAV, CalDAV)
│   ├── webdav.ts
│   └── caldav.ts
└── tools/                # Tool implementations
    ├── types.ts          # Shared types
    ├── system/           # System tools (files)
    └── apps/             # App tools (tasks, cookbook, notes, aiquila)
```

For more details, see [Architecture Documentation](../docs/mcp/development/architecture.md).

## Contributing

Contributions are welcome! Please:

1. Read the [Development Guide](../docs/dev/development.md)
2. Follow [Best Practices](../docs/dev/best-practices.md)
3. Add tests for new features
4. Update documentation

## License

Part of the AIquila project. See main repository for license information.
