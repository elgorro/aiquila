# AIquila MCP Server Documentation

Welcome to the AIquila Model Context Protocol (MCP) Server documentation. This server enables Claude Desktop and other MCP clients to interact with your Nextcloud instance through a standardized protocol.

## What is MCP?

The Model Context Protocol (MCP) is an open standard developed by Anthropic that enables AI assistants to connect to external data sources and tools. The AIquila MCP Server implements this protocol to provide seamless integration between Claude and Nextcloud.

## Quick Links

- **[Setup Guide](setup.md)** - Installation and configuration
- **[OAuth 2.0 for Claude.ai](oauth.md)** - Connect Claude.ai with OAuth authentication
- **[Tools Reference](#tools-reference)** - Available tools and their usage
- **[Development](development/)** - Guides for extending the server

## Features

### System Tools
Core system operations via WebDAV and OCC:
- **File Operations** (WebDAV) - List, read, write, create, and delete files and folders
- **System Status** (OCC) - Monitor system status and run configuration checks
- **App Management** (OCC) - List, enable, and disable Nextcloud apps
- **Security** (OCC) - Verify system and app integrity

### App Integrations
Nextcloud apps and administration:
- **Tasks** - Create and manage tasks via CalDAV
- **Cookbook** - Add and manage recipes
- **Notes** - Create markdown notes
- **Users** - Manage user accounts (list, info, enable, disable)
- **Groups** - Manage groups and memberships
- **Shares** - List and audit file shares
- **AIquila** - Configure and test Claude integration

**Total: 28 tools across 11 modules**

## Tools Reference

### System Tools

#### File Operations
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_files` | List files and folders | [System Tools](tools/system-tools.md#list_files) |
| `read_file` | Read file contents | [System Tools](tools/system-tools.md#read_file) |
| `write_file` | Create or update files | [System Tools](tools/system-tools.md#write_file) |
| `create_folder` | Create folders | [System Tools](tools/system-tools.md#create_folder) |
| `delete` | Delete files or folders | [System Tools](tools/system-tools.md#delete) |

#### System Status & Diagnostics
| Tool | Description | Documentation |
|------|-------------|---------------|
| `system_status` | Get system status | [System Tools](tools/system-tools.md#system_status) |
| `run_setup_checks` | Run configuration checks | [System Tools](tools/system-tools.md#run_setup_checks) |

#### App Management
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_apps` | List installed apps | [System Tools](tools/system-tools.md#list_apps) |
| `get_app_info` | Get app details | [System Tools](tools/system-tools.md#get_app_info) |
| `enable_app` | Enable an app | [System Tools](tools/system-tools.md#enable_app) |
| `disable_app` | Disable an app | [System Tools](tools/system-tools.md#disable_app) |

#### Security & Integrity
| Tool | Description | Documentation |
|------|-------------|---------------|
| `check_core_integrity` | Check core system integrity | [System Tools](tools/system-tools.md#check_core_integrity) |
| `check_app_integrity` | Check app integrity | [System Tools](tools/system-tools.md#check_app_integrity) |

### App Tools

#### Nextcloud Tasks
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_task_lists` | List all task lists | [Tasks](tools/apps/tasks.md#list_task_lists) |
| `create_task` | Create a new task | [Tasks](tools/apps/tasks.md#create_task) |

#### Nextcloud Cookbook
| Tool | Description | Documentation |
|------|-------------|---------------|
| `add_recipe` | Add a recipe | [Cookbook](tools/apps/cookbook.md#add_recipe) |

#### Nextcloud Notes
| Tool | Description | Documentation |
|------|-------------|---------------|
| `create_note` | Create a note | [Notes](tools/apps/notes.md#create_note) |

#### Nextcloud User Management
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_users` | List all users | [Users](tools/apps/users.md#list_users) |
| `get_user_info` | Get user details | [Users](tools/apps/users.md#get_user_info) |
| `enable_user` | Enable a user | [Users](tools/apps/users.md#enable_user) |
| `disable_user` | Disable a user | [Users](tools/apps/users.md#disable_user) |

#### Nextcloud Group Management
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_groups` | List all groups | [Groups](tools/apps/groups.md#list_groups) |
| `get_group_info` | Get group details | [Groups](tools/apps/groups.md#get_group_info) |
| `add_user_to_group` | Add user to group | [Groups](tools/apps/groups.md#add_user_to_group) |
| `remove_user_from_group` | Remove user from group | [Groups](tools/apps/groups.md#remove_user_from_group) |

#### Nextcloud Shares
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_shares` | List file shares | [Shares](tools/apps/shares.md#list_shares) |

#### AIquila Internal
| Tool | Description | Documentation |
|------|-------------|---------------|
| `aiquila_show_config` | Show configuration | [AIquila](tools/apps/aiquila.md#aiquila_show_config) |
| `aiquila_configure` | Configure settings | [AIquila](tools/apps/aiquila.md#aiquila_configure) |
| `aiquila_test` | Test Claude API | [AIquila](tools/apps/aiquila.md#aiquila_test) |

## Architecture

The AIquila MCP Server is built with a modular architecture:

```
mcp-server/
├── src/
│   ├── index.ts              # Main server entry point
│   ├── client/               # Client infrastructure
│   │   ├── webdav.ts        # WebDAV client singleton
│   │   └── caldav.ts        # CalDAV operations
│   └── tools/
│       ├── types.ts         # Shared type definitions
│       ├── system/          # System-level tools
│       │   ├── files.ts     # File operations (5 tools)
│       │   ├── status.ts    # System status (2 tools)
│       │   ├── apps.ts      # App management (4 tools)
│       │   └── security.ts  # Security checks (2 tools)
│       └── apps/            # App-specific tools
│           ├── tasks.ts     # Nextcloud Tasks (2 tools)
│           ├── cookbook.ts  # Nextcloud Cookbook (1 tool)
│           ├── notes.ts     # Nextcloud Notes (1 tool)
│           ├── users.ts     # User Management (4 tools)
│           ├── groups.ts    # Group Management (4 tools)
│           ├── shares.ts    # Share Management (1 tool)
│           └── aiquila.ts   # AIquila internal (3 tools)
```

For developers looking to extend the server, see:
- [Architecture Overview](development/architecture.md)
- [Adding New Tools](development/adding-tools.md)
- [Adding New Apps](development/adding-apps.md)

## Usage Examples

### Working with Files
```
Ask Claude: "List my documents folder in Nextcloud"
Ask Claude: "Create a file called meeting-notes.md in /Documents"
Ask Claude: "Read the contents of /Documents/project-plan.md"
```

### Managing Tasks
```
Ask Claude: "Create a task 'Review pull requests' in my personal task list"
Ask Claude: "Show me all my task lists"
```

### Adding Recipes
```
Ask Claude: "Add a recipe for chocolate chip cookies to my cookbook"
```

### Creating Notes
```
Ask Claude: "Create a note with title 'Ideas' and content 'New project brainstorm'"
```

### Managing Users
```
Ask Claude: "List all Nextcloud users"
Ask Claude: "Get information about user alice"
Ask Claude: "Disable user bob"
Ask Claude: "Enable user charlie"
```

### Managing Groups
```
Ask Claude: "List all groups"
Ask Claude: "Show me who's in the admin group"
Ask Claude: "Add alice to the developers group"
Ask Claude: "Remove bob from the marketing group"
```

### System Monitoring
```
Ask Claude: "Check Nextcloud system status"
Ask Claude: "Run setup checks on my Nextcloud"
Ask Claude: "What version of Nextcloud am I running?"
```

### Managing Apps
```
Ask Claude: "List all installed Nextcloud apps"
Ask Claude: "Enable the tasks app"
Ask Claude: "Disable the survey app"
Ask Claude: "Get info about the photos app"
```

### Security & Integrity
```
Ask Claude: "Check Nextcloud core integrity"
Ask Claude: "Verify the tasks app hasn't been modified"
Ask Claude: "Run security integrity checks"
```

### Auditing Shares
```
Ask Claude: "List all file shares"
Ask Claude: "Show me shares for user alice"
Ask Claude: "Audit all public link shares"
```

## Getting Help

- **Issues**: Report bugs at [GitHub Issues](https://github.com/elgorro/aiquila/issues)
- **Development**: See [docs/dev/](../dev/) for developer documentation
- **Nextcloud**: Refer to [Nextcloud documentation](https://docs.nextcloud.com/) for app-specific features

## Version

Current version: **0.1.1**

## License

AIquila is open source software. See the main project repository for license information.
