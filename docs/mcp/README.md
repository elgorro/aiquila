# AIquila MCP Server Documentation

Welcome to the AIquila Model Context Protocol (MCP) Server documentation. This server enables any MCP-compatible client to interact with your Nextcloud instance through a standardized protocol.

## What is MCP?

The Model Context Protocol (MCP) is an open standard developed by Anthropic that enables AI assistants to connect to external data sources and tools. The AIquila MCP Server implements this protocol to provide seamless integration between MCP clients and Nextcloud.

## Quick Links

- **[Setup Guide](setup.md)** - Installation and configuration
- **[OAuth 2.0](oauth.md)** - OAuth authentication for remote MCP clients
- **[MCP-Connector Integration](mcp-connector.md)** - Use AIquila via the Anthropic Messages API (beta)
- **[Tools Reference](#tools-reference)** - Available tools and their usage
- **[Development](development/)** - Guides for extending the server

## Features

### System Tools
Core system operations via WebDAV and OCC:
- **File Operations** (WebDAV) — List, read, write, move, copy, search, and delete files and folders
- **File Analysis** — Read binary files (images, PDFs) and analyze images with Claude vision
- **System Status** (OCC) — Monitor system status, local time, and run configuration checks
- **App Management** (OCC) — List, enable, disable, install, and uninstall Nextcloud apps
- **Security** (OCC) — Verify system and app integrity
- **Search** — Unified search across all Nextcloud apps
- **OCC** — Execute arbitrary Nextcloud CLI commands

### App Integrations
Nextcloud apps and administration:
- **Calendar** — Full CRUD for events with recurrence, attendees, and alarms (CalDAV)
- **Tasks** — Full CRUD for tasks with subtasks, priorities, and categories (CalDAV)
- **Contacts** — Full CRUD for contacts with structured fields (CardDAV)
- **Mail** — Email accounts, mailboxes, messages, send, and flags
- **Bookmarks** — Bookmark CRUD, folder hierarchy, and tag management
- **Maps** — Favorites, GPS devices/tracks, photo geotagging, custom maps, import/export
- **Notes** — Markdown notes with categories and search
- **News** — RSS/Atom feed subscriptions, folders, and article reading/triage
- **Polls** — Create text/date polls, vote, comment, and share
- **Forms** — Build surveys, collect responses, and export results
- **Cookbook** — Recipe management with schema.org format
- **Assistant** — Nextcloud AI task processing and image generation
- **Shares** — File share CRUD and auditing
- **Users** — Manage user accounts
- **Groups** — Manage groups and memberships
- **File Tags** — Personal and system tag management
- **AIquila** — Configure and test Claude integration

**Total: 100+ tools across 20 modules**

## Tools Reference

### System Tools

#### File Operations (11 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_files` | List files and folders | [System Tools](tools/system-tools.md#list_files) |
| `read_file` | Read file contents (text) | [System Tools](tools/system-tools.md#read_file) |
| `get_file_content` | Read file with mime type (text/base64/image) | [System Tools](tools/system-tools.md) |
| `write_file` | Create or update files | [System Tools](tools/system-tools.md#write_file) |
| `create_folder` | Create folders | [System Tools](tools/system-tools.md#create_folder) |
| `delete` | Delete files or folders | [System Tools](tools/system-tools.md#delete) |
| `move_file` | Move or rename files/folders | [System Tools](tools/system-tools.md) |
| `copy_file` | Copy files/folders | [System Tools](tools/system-tools.md) |
| `get_file_info` | Get file metadata | [System Tools](tools/system-tools.md) |
| `search_files` | Search files by name/mime type | [System Tools](tools/system-tools.md) |
| `analyze_image` | Analyze image with Claude vision | [System Tools](tools/system-tools.md) |

#### System Status & Diagnostics (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `system_status` | Get system status | [System Tools](tools/system-tools.md#system_status) |
| `run_setup_checks` | Run configuration checks | [System Tools](tools/system-tools.md#run_setup_checks) |
| `get_local_time` | Get server local time & timezone | [System Tools](tools/system-tools.md) |

#### App Management (6 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_apps` | List installed apps | [System Tools](tools/system-tools.md#list_apps) |
| `get_app_info` | Get app details | [System Tools](tools/system-tools.md#get_app_info) |
| `enable_app` | Enable an app | [System Tools](tools/system-tools.md#enable_app) |
| `disable_app` | Disable an app | [System Tools](tools/system-tools.md#disable_app) |
| `install_app` | Install an app from the App Store | [System Tools](tools/system-tools.md) |
| `uninstall_app` | Remove an app | [System Tools](tools/system-tools.md) |

#### Security & Integrity (2 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `check_core_integrity` | Check core system integrity | [System Tools](tools/system-tools.md#check_core_integrity) |
| `check_app_integrity` | Check app integrity | [System Tools](tools/system-tools.md#check_app_integrity) |

#### Search (2 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `unified_search` | Search across all NC apps | [System Tools](tools/system-tools.md#unified_search) |
| `list_search_providers` | List available search providers | [System Tools](tools/system-tools.md#list_search_providers) |

#### OCC (1 tool)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `run_occ` | Execute any OCC command | [System Tools](tools/system-tools.md) |

### App Tools

#### Calendar (6 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_calendars` | List all calendars | [Calendar](tools/apps/calendar.md#list_calendars) |
| `list_events` | List events in a time range | [Calendar](tools/apps/calendar.md#list_events) |
| `get_event` | Get event details by UID | [Calendar](tools/apps/calendar.md#get_event) |
| `create_event` | Create an event | [Calendar](tools/apps/calendar.md#create_event) |
| `update_event` | Update an event | [Calendar](tools/apps/calendar.md#update_event) |
| `delete_event` | Delete an event | [Calendar](tools/apps/calendar.md#delete_event) |

#### Tasks (6 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_task_lists` | List all task lists | [Tasks](tools/apps/tasks.md#list_task_lists) |
| `list_tasks` | List tasks with details | [Tasks](tools/apps/tasks.md#list_tasks) |
| `create_task` | Create a new task | [Tasks](tools/apps/tasks.md#create_task) |
| `update_task` | Update a task | [Tasks](tools/apps/tasks.md#update_task) |
| `complete_task` | Mark task complete/reopen | [Tasks](tools/apps/tasks.md#complete_task) |
| `delete_task` | Delete a task | [Tasks](tools/apps/tasks.md#delete_task) |

#### Contacts (6 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_address_books` | List address books | [Contacts](tools/apps/contacts.md#list_address_books) |
| `list_contacts` | List/search contacts | [Contacts](tools/apps/contacts.md#list_contacts) |
| `get_contact` | Get contact details | [Contacts](tools/apps/contacts.md#get_contact) |
| `create_contact` | Create a contact | [Contacts](tools/apps/contacts.md#create_contact) |
| `update_contact` | Update a contact | [Contacts](tools/apps/contacts.md#update_contact) |
| `delete_contact` | Delete a contact | [Contacts](tools/apps/contacts.md#delete_contact) |

#### Mail (8 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_mail_accounts` | List email accounts | [Mail](tools/apps/mail.md#list_mail_accounts) |
| `list_mailboxes` | List mailboxes/folders | [Mail](tools/apps/mail.md#list_mailboxes) |
| `list_messages` | List messages in a mailbox | [Mail](tools/apps/mail.md#list_messages) |
| `read_message` | Read full message content | [Mail](tools/apps/mail.md#read_message) |
| `send_message` | Send an email | [Mail](tools/apps/mail.md#send_message) |
| `delete_message` | Delete a message | [Mail](tools/apps/mail.md#delete_message) |
| `move_message` | Move message to another mailbox | [Mail](tools/apps/mail.md#move_message) |
| `set_message_flags` | Set read/star/junk flags | [Mail](tools/apps/mail.md#set_message_flags) |

#### Bookmarks (13 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_bookmarks` | List/search bookmarks | [Bookmarks](tools/apps/bookmarks.md#list_bookmarks) |
| `get_bookmark` | Get bookmark details | [Bookmarks](tools/apps/bookmarks.md#get_bookmark) |
| `create_bookmark` | Create a bookmark | [Bookmarks](tools/apps/bookmarks.md#create_bookmark) |
| `update_bookmark` | Update a bookmark | [Bookmarks](tools/apps/bookmarks.md#update_bookmark) |
| `delete_bookmark` | Delete a bookmark | [Bookmarks](tools/apps/bookmarks.md#delete_bookmark) |
| `list_bookmark_folders` | List folder hierarchy | [Bookmarks](tools/apps/bookmarks.md#list_bookmark_folders) |
| `get_bookmark_folder_contents` | Get folder contents | [Bookmarks](tools/apps/bookmarks.md#get_bookmark_folder_contents) |
| `create_bookmark_folder` | Create a folder | [Bookmarks](tools/apps/bookmarks.md#create_bookmark_folder) |
| `update_bookmark_folder` | Rename/move a folder | [Bookmarks](tools/apps/bookmarks.md#update_bookmark_folder) |
| `delete_bookmark_folder` | Delete a folder | [Bookmarks](tools/apps/bookmarks.md#delete_bookmark_folder) |
| `list_bookmark_tags` | List all tags | [Bookmarks](tools/apps/bookmarks.md#list_bookmark_tags) |
| `rename_bookmark_tag` | Rename a tag | [Bookmarks](tools/apps/bookmarks.md#rename_bookmark_tag) |
| `delete_bookmark_tag` | Delete a tag | [Bookmarks](tools/apps/bookmarks.md#delete_bookmark_tag) |

#### Maps (26 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_map_favorites` | List saved locations | [Maps](tools/apps/maps.md#list_map_favorites) |
| `create_map_favorite` | Create a favorite | [Maps](tools/apps/maps.md#create_map_favorite) |
| `update_map_favorite` | Update a favorite | [Maps](tools/apps/maps.md#update_map_favorite) |
| `delete_map_favorite` | Delete a favorite | [Maps](tools/apps/maps.md#delete_map_favorite) |
| `export_map_favorites` | Export favorites as GPX | [Maps](tools/apps/maps.md#export_map_favorites) |
| `import_map_favorites` | Import favorites from file | [Maps](tools/apps/maps.md#import_map_favorites) |
| `list_map_devices` | List GPS devices | [Maps](tools/apps/maps.md#list_map_devices) |
| `get_map_device_points` | Get device location history | [Maps](tools/apps/maps.md#get_map_device_points) |
| `add_map_device_point` | Log a GPS point | [Maps](tools/apps/maps.md#add_map_device_point) |
| `update_map_device` | Update device color | [Maps](tools/apps/maps.md#update_map_device) |
| `delete_map_device` | Delete a device | [Maps](tools/apps/maps.md#delete_map_device) |
| `export_map_devices` | Export device data as GPX | [Maps](tools/apps/maps.md#export_map_devices) |
| `import_map_devices` | Import device data | [Maps](tools/apps/maps.md#import_map_devices) |
| `list_map_tracks` | List GPS tracks | [Maps](tools/apps/maps.md#list_map_tracks) |
| `get_map_track` | Get track details/content | [Maps](tools/apps/maps.md#get_map_track) |
| `update_map_track` | Update track metadata | [Maps](tools/apps/maps.md#update_map_track) |
| `export_map_route` | Export route as GPX | [Maps](tools/apps/maps.md#export_map_route) |
| `list_map_photos` | List geolocated photos | [Maps](tools/apps/maps.md#list_map_photos) |
| `list_map_photos_nonlocalized` | List photos without GPS | [Maps](tools/apps/maps.md#list_map_photos_nonlocalized) |
| `place_map_photos` | Set GPS coords on photos | [Maps](tools/apps/maps.md#place_map_photos) |
| `reset_map_photo_coords` | Remove GPS from photos | [Maps](tools/apps/maps.md#reset_map_photo_coords) |
| `list_maps` | List custom maps | [Maps](tools/apps/maps.md#list_maps) |
| `create_map` | Create a custom map | [Maps](tools/apps/maps.md#create_map) |
| `update_map` | Update a custom map | [Maps](tools/apps/maps.md#update_map) |
| `delete_map` | Delete a custom map | [Maps](tools/apps/maps.md#delete_map) |

#### Notes (5 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_notes` | List all notes | [Notes](tools/apps/notes.md#list_notes) |
| `get_note` | Get note content | [Notes](tools/apps/notes.md#get_note) |
| `create_note` | Create a note | [Notes](tools/apps/notes.md#create_note) |
| `update_note` | Update a note | [Notes](tools/apps/notes.md#update_note) |
| `delete_note` | Delete a note | [Notes](tools/apps/notes.md#delete_note) |

#### News (17 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_feeds` | List subscribed RSS feeds | [News](tools/apps/news.md#list_feeds) |
| `add_feed` | Subscribe to a feed | [News](tools/apps/news.md#add_feed) |
| `delete_feed` | Delete a feed | [News](tools/apps/news.md#delete_feed) |
| `move_feed` | Move a feed to a folder | [News](tools/apps/news.md#move_feed) |
| `rename_feed` | Rename a feed | [News](tools/apps/news.md#rename_feed) |
| `mark_feed_read` | Mark a feed's items read | [News](tools/apps/news.md#mark_feed_read) |
| `list_news_folders` | List feed folders | [News](tools/apps/news.md#list_news_folders) |
| `create_news_folder` | Create a folder | [News](tools/apps/news.md#create_news_folder) |
| `rename_news_folder` | Rename a folder | [News](tools/apps/news.md#rename_news_folder) |
| `delete_news_folder` | Delete a folder | [News](tools/apps/news.md#delete_news_folder) |
| `mark_news_folder_read` | Mark a folder's items read | [News](tools/apps/news.md#mark_news_folder_read) |
| `list_news_items` | List/filter articles | [News](tools/apps/news.md#list_news_items) |
| `mark_item_read` | Mark an article read | [News](tools/apps/news.md#mark_item_read) |
| `mark_item_unread` | Mark an article unread | [News](tools/apps/news.md#mark_item_unread) |
| `star_item` | Star an article | [News](tools/apps/news.md#star_item) |
| `unstar_item` | Unstar an article | [News](tools/apps/news.md#unstar_item) |
| `mark_items_read` | Mark multiple articles read | [News](tools/apps/news.md#mark_items_read) |

#### Activity (2 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_activity` | List recent activity feed entries | [Activity](tools/apps/activity.md#list_activity) |
| `get_object_activity` | List activity history for one object | [Activity](tools/apps/activity.md#get_object_activity) |

#### Polls (21 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_polls` | List all polls | [Polls](tools/apps/polls.md#list_polls) |
| `get_poll` | Get poll details | [Polls](tools/apps/polls.md#get_poll) |
| `create_poll` | Create a new poll (text or date) | [Polls](tools/apps/polls.md#create_poll) |
| `update_poll` | Update poll configuration | [Polls](tools/apps/polls.md#update_poll) |
| `delete_poll` | Delete a poll | [Polls](tools/apps/polls.md#delete_poll) |
| `close_poll` | Close voting on a poll | [Polls](tools/apps/polls.md#close_poll) |
| `reopen_poll` | Reopen a closed poll | [Polls](tools/apps/polls.md#reopen_poll) |
| `clone_poll` | Clone an existing poll | [Polls](tools/apps/polls.md#clone_poll) |
| `list_poll_options` | List options with tallies | [Polls](tools/apps/polls.md#list_poll_options) |
| `add_text_poll_option` | Add a text option | [Polls](tools/apps/polls.md#add_text_poll_option) |
| `add_date_poll_option` | Add a date/time option | [Polls](tools/apps/polls.md#add_date_poll_option) |
| `delete_poll_option` | Delete an option | [Polls](tools/apps/polls.md#delete_poll_option) |
| `list_poll_votes` | List votes on a poll | [Polls](tools/apps/polls.md#list_poll_votes) |
| `vote_on_poll` | Cast or change your vote | [Polls](tools/apps/polls.md#vote_on_poll) |
| `list_poll_comments` | List poll comments | [Polls](tools/apps/polls.md#list_poll_comments) |
| `add_poll_comment` | Post a comment | [Polls](tools/apps/polls.md#add_poll_comment) |
| `delete_poll_comment` | Delete a comment | [Polls](tools/apps/polls.md#delete_poll_comment) |
| `list_poll_shares` | List shares | [Polls](tools/apps/polls.md#list_poll_shares) |
| `add_poll_share` | Share via link/user/email | [Polls](tools/apps/polls.md#add_poll_share) |
| `delete_poll_share` | Revoke a share | [Polls](tools/apps/polls.md#delete_poll_share) |
| `set_poll_subscription` | Subscribe/unsubscribe to updates | [Polls](tools/apps/polls.md#set_poll_subscription) |

#### Forms (25 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_forms` | List owned/shared/partial forms | [Forms](tools/apps/forms.md#list_forms) |
| `get_form` | Get a form with questions and shares | [Forms](tools/apps/forms.md#get_form) |
| `create_form` | Create a new empty form | [Forms](tools/apps/forms.md#create_form) |
| `clone_form` | Clone an existing form | [Forms](tools/apps/forms.md#clone_form) |
| `update_form` | Update form title, state, expiration, flags | [Forms](tools/apps/forms.md#update_form) |
| `transfer_form_owner` | Transfer form ownership | [Forms](tools/apps/forms.md#transfer_form_owner) |
| `delete_form` | Delete a form | [Forms](tools/apps/forms.md#delete_form) |
| `list_form_questions` | List questions on a form | [Forms](tools/apps/forms.md#list_form_questions) |
| `create_form_question` | Add a question | [Forms](tools/apps/forms.md#create_form_question) |
| `update_form_question` | Update a question | [Forms](tools/apps/forms.md#update_form_question) |
| `reorder_form_questions` | Reorder questions | [Forms](tools/apps/forms.md#reorder_form_questions) |
| `delete_form_question` | Delete a question | [Forms](tools/apps/forms.md#delete_form_question) |
| `create_form_options` | Add options to a choice question | [Forms](tools/apps/forms.md#create_form_options) |
| `update_form_option` | Update an option | [Forms](tools/apps/forms.md#update_form_option) |
| `reorder_form_options` | Reorder options | [Forms](tools/apps/forms.md#reorder_form_options) |
| `delete_form_option` | Delete an option | [Forms](tools/apps/forms.md#delete_form_option) |
| `create_form_share` | Share with a user, group, or public link | [Forms](tools/apps/forms.md#create_form_share) |
| `update_form_share` | Change share permissions | [Forms](tools/apps/forms.md#update_form_share) |
| `delete_form_share` | Revoke a share | [Forms](tools/apps/forms.md#delete_form_share) |
| `list_form_submissions` | List submissions (search/paginate) | [Forms](tools/apps/forms.md#list_form_submissions) |
| `get_form_submission` | Get a single submission | [Forms](tools/apps/forms.md#get_form_submission) |
| `create_form_submission` | Submit answers to a form | [Forms](tools/apps/forms.md#create_form_submission) |
| `delete_form_submission` | Delete a submission | [Forms](tools/apps/forms.md#delete_form_submission) |
| `delete_all_form_submissions` | Delete all submissions | [Forms](tools/apps/forms.md#delete_all_form_submissions) |
| `export_form_submissions` | Export submissions to Nextcloud storage | [Forms](tools/apps/forms.md#export_form_submissions) |

#### Cookbook (6 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_recipes` | List/search recipes | [Cookbook](tools/apps/cookbook.md#list_recipes) |
| `list_recipe_categories` | List recipe categories | [Cookbook](tools/apps/cookbook.md#list_recipe_categories) |
| `get_recipe` | Get full recipe details | [Cookbook](tools/apps/cookbook.md#get_recipe) |
| `create_recipe` | Create a recipe | [Cookbook](tools/apps/cookbook.md#create_recipe) |
| `update_recipe` | Update a recipe | [Cookbook](tools/apps/cookbook.md#update_recipe) |
| `delete_recipe` | Delete a recipe | [Cookbook](tools/apps/cookbook.md#delete_recipe) |

#### Assistant / AI (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_text_tasks` | List available AI task types | [Assistant](tools/apps/assistant.md#list_text_tasks) |
| `process_text` | Submit text processing task | [Assistant](tools/apps/assistant.md#process_text) |
| `get_task_result` | Get AI task status/result | [Assistant](tools/apps/assistant.md#get_task_result) |
| `generate_image` | Generate image from prompt | [Assistant](tools/apps/assistant.md#generate_image) |

#### Shares (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_shares` | List file shares | [Shares](tools/apps/shares.md#list_shares) |
| `create_share` | Create a share | [Shares](tools/apps/shares.md#create_share) |
| `update_share` | Update a share | [Shares](tools/apps/shares.md#update_share) |
| `delete_share` | Delete a share | [Shares](tools/apps/shares.md#delete_share) |

#### Users (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_users` | List all users | [Users](tools/apps/users.md#list_users) |
| `get_user_info` | Get user details | [Users](tools/apps/users.md#get_user_info) |
| `enable_user` | Enable a user | [Users](tools/apps/users.md#enable_user) |
| `disable_user` | Disable a user | [Users](tools/apps/users.md#disable_user) |

#### Groups (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_groups` | List all groups | [Groups](tools/apps/groups.md#list_groups) |
| `get_group_info` | Get group details | [Groups](tools/apps/groups.md#get_group_info) |
| `add_user_to_group` | Add user to group | [Groups](tools/apps/groups.md#add_user_to_group) |
| `remove_user_from_group` | Remove user from group | [Groups](tools/apps/groups.md#remove_user_from_group) |

#### File Tags (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_file_tags` | Get tags on a file | [System Tools](tools/system-tools.md) |
| `set_file_tags` | Set tags on a file | [System Tools](tools/system-tools.md) |
| `list_system_tags` | List all system tags | [System Tools](tools/system-tools.md) |
| `create_system_tag` | Create a system tag | [System Tools](tools/system-tools.md) |
| `assign_system_tag` | Assign system tag to file | [System Tools](tools/system-tools.md) |
| `remove_system_tag` | Remove system tag from file | [System Tools](tools/system-tools.md) |

#### AIquila Internal (3 tools)
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
│       │   ├── files.ts     # File operations (11 tools)
│       │   ├── status.ts    # System status (3 tools)
│       │   ├── apps.ts      # App management (6 tools)
│       │   ├── security.ts  # Security checks (2 tools)
│       │   ├── search.ts    # Unified search (2 tools)
│       │   └── occ.ts       # OCC command execution (1 tool)
│       └── apps/            # App-specific tools
│           ├── calendar.ts  # Calendar events (6 tools)
│           ├── tasks.ts     # Tasks via CalDAV (6 tools)
│           ├── contacts.ts  # Contacts via CardDAV (6 tools)
│           ├── mail.ts      # Email management (8 tools)
│           ├── bookmarks.ts # Bookmarks, folders, tags (13 tools)
│           ├── maps.ts      # Maps, GPS, tracks, photos (26 tools)
│           ├── notes.ts     # Markdown notes (5 tools)
│           ├── cookbook.ts   # Recipes (6 tools)
│           ├── assistant.ts # NC AI task processing (4 tools)
│           ├── shares.ts    # File sharing (4 tools)
│           ├── users.ts     # User management (4 tools)
│           ├── groups.ts    # Group management (4 tools)
│           ├── tags.ts      # File tagging (6 tools)
│           └── aiquila.ts   # AIquila config/test (3 tools)
```

For developers looking to extend the server, see:
- [Architecture Overview](development/architecture.md)
- [Adding New Tools](development/adding-tools.md)
- [Adding New Apps](development/adding-apps.md)

## Usage Examples

### Working with Files
```
Ask your AI assistant: "List my documents folder in Nextcloud"
Ask your AI assistant: "Create a file called meeting-notes.md in /Documents"
Ask your AI assistant: "Read the contents of /Documents/project-plan.md"
```

### Managing Tasks
```
Ask your AI assistant: "Create a task 'Review pull requests' in my personal task list"
Ask your AI assistant: "Show me all my task lists"
```

### Adding Recipes
```
Ask your AI assistant: "Add a recipe for chocolate chip cookies to my cookbook"
```

### Creating Notes
```
Ask your AI assistant: "Create a note with title 'Ideas' and content 'New project brainstorm'"
```

### Managing Users
```
Ask your AI assistant: "List all Nextcloud users"
Ask your AI assistant: "Get information about user alice"
Ask your AI assistant: "Disable user bob"
Ask your AI assistant: "Enable user charlie"
```

### Managing Groups
```
Ask your AI assistant: "List all groups"
Ask your AI assistant: "Show me who's in the admin group"
Ask your AI assistant: "Add alice to the developers group"
Ask your AI assistant: "Remove bob from the marketing group"
```

### System Monitoring
```
Ask your AI assistant: "Check Nextcloud system status"
Ask your AI assistant: "Run setup checks on my Nextcloud"
Ask your AI assistant: "What version of Nextcloud am I running?"
```

### Managing Apps
```
Ask your AI assistant: "List all installed Nextcloud apps"
Ask your AI assistant: "Enable the tasks app"
Ask your AI assistant: "Disable the survey app"
Ask your AI assistant: "Get info about the photos app"
```

### Security & Integrity
```
Ask your AI assistant: "Check Nextcloud core integrity"
Ask your AI assistant: "Verify the tasks app hasn't been modified"
Ask your AI assistant: "Run security integrity checks"
```

### Auditing Shares
```
Ask your AI assistant: "List all file shares"
Ask your AI assistant: "Show me shares for user alice"
Ask your AI assistant: "Audit all public link shares"
```

## Getting Help

- **Issues**: Report bugs at [GitHub Issues](https://github.com/elgorro/aiquila/issues)
- **Development**: See [docs/dev/](../dev/) for developer documentation
- **Nextcloud**: Refer to [Nextcloud documentation](https://docs.nextcloud.com/) for app-specific features

## License

AIquila is open source software. See the main project repository for license information.
