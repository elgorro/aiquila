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
- **Bulk & Archives** — Multi-file operations, and creating, listing and extracting archives
- **File Analysis** — Read binary files (images, PDFs) and analyze images with Claude vision
- **Trash & Versions** — Restore deleted files, browse and roll back to earlier file versions
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
- **Mail** — Email accounts, mailboxes, messages, send, flags, and attachments
- **Talk** — Conversations, messages, participants, polls, and reactions
- **Deck** — Boards, stacks, cards, labels, and assignments
- **Photos** — Albums, collaborators, favourites, and photo metadata
- **Circles** — Team circles and their membership
- **Projects** — Group related files and entities into Nextcloud projects
- **Coworkers** — Create, run, pause and inspect the AIquila Coworkers (saved AI jobs) and their run history
- **Text** — Read, write and delete a folder's Text workspace (`Readme.md`), or get a direct-edit URL
- **Notifications** — Read, mark and dismiss Nextcloud notifications
- **User Status & Out of Office** — Set status, status message, and absence periods
- **Bookmarks** — Bookmark CRUD, folder hierarchy, and tag management
- **Maps** — Favorites, category/device sharing, GPS devices/tracks, photo geotagging, contacts, custom maps, import/export
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
- **Announcements, Registration & Terms of Service** — Instance-wide notices, self-service signup settings, and ToS documents
- **Passman** — List vaults and credential metadata (never secret values)
- **Translate & Social Sharing** — Translate text, and generate social-network share URLs for a file
- **Recommendations** — The files Nextcloud suggests for the current user
- **AIquila** — Configure and test the AI provider integration

**Total: 316 tools across 43 categories**

## Tools Reference

### System Tools

#### File Operations (15 tools)
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
| `bulk_file_operations` | Execute multiple file operations (move, copy, delete) sequentially in a single call | [System Tools](tools/system-tools.md) |
| `create_archive` | Create a zip archive in Nextcloud from one or more files and/or folders | [System Tools](tools/system-tools.md) |
| `extract_archive` | Extract a zip archive in Nextcloud into a destination folder | [System Tools](tools/system-tools.md) |
| `list_archive` | List the contents of a zip archive in Nextcloud without extracting it | [System Tools](tools/system-tools.md) |

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

#### Mail (10 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_mail_accounts` | List all configured email accounts in Nextcloud Mail | [Mail](tools/apps/mail.md#list_mail_accounts) |
| `list_mailboxes` | List all mailboxes (folders) for a Nextcloud Mail account | [Mail](tools/apps/mail.md#list_mailboxes) |
| `mail_list_messages` | List email messages in a Nextcloud Mail mailbox | [Mail](tools/apps/mail.md) |
| `mail_read_message` | Read the full content of an email message by ID | [Mail](tools/apps/mail.md) |
| `mail_get_attachment` | Download an email attachment by message ID and attachment ID | [Mail](tools/apps/mail.md#mail_get_attachment) |
| `mail_search_messages` | Search email messages across all mailboxes by subject or sender | [Mail](tools/apps/mail.md) |
| `mail_send_message` | Send an email message through Nextcloud Mail | [Mail](tools/apps/mail.md) |
| `mail_delete_message` | Delete an email message by ID | [Mail](tools/apps/mail.md) |
| `mail_move_message` | Move an email message to a different mailbox/folder | [Mail](tools/apps/mail.md) |
| `mail_set_message_flags` | Set flags on an email message (mark as read/unread, star/unstar, mark as important or… | [Mail](tools/apps/mail.md) |

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

#### Maps (40 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_map_favorites` | List saved locations | [Maps](tools/apps/maps.md#list_map_favorites) |
| `create_map_favorite` | Create a favorite | [Maps](tools/apps/maps.md#create_map_favorite) |
| `update_map_favorite` | Update a favorite | [Maps](tools/apps/maps.md#update_map_favorite) |
| `delete_map_favorite` | Delete a favorite | [Maps](tools/apps/maps.md#delete_map_favorite) |
| `rename_map_favorite_category` | Rename favorite categories | [Maps](tools/apps/maps.md#rename_map_favorite_category) |
| `list_shared_map_categories` | List shared favorite categories | [Maps](tools/apps/maps.md#list_shared_map_categories) |
| `share_map_category` | Share a category via link | [Maps](tools/apps/maps.md#share_map_category) |
| `unshare_map_category` | Revoke a category share | [Maps](tools/apps/maps.md#unshare_map_category) |
| `add_shared_category_to_map` | Add a shared category to a map | [Maps](tools/apps/maps.md#add_shared_category_to_map) |
| `export_map_favorites` | Export favorites as GPX | [Maps](tools/apps/maps.md#export_map_favorites) |
| `import_map_favorites` | Import favorites from file | [Maps](tools/apps/maps.md#import_map_favorites) |
| `list_map_devices` | List GPS devices | [Maps](tools/apps/maps.md#list_map_devices) |
| `get_map_device_points` | Get device location history | [Maps](tools/apps/maps.md#get_map_device_points) |
| `add_map_device_point` | Log a GPS point | [Maps](tools/apps/maps.md#add_map_device_point) |
| `update_map_device` | Update device color | [Maps](tools/apps/maps.md#update_map_device) |
| `delete_map_device` | Delete a device | [Maps](tools/apps/maps.md#delete_map_device) |
| `export_map_devices` | Export device data as GPX | [Maps](tools/apps/maps.md#export_map_devices) |
| `import_map_devices` | Import device data | [Maps](tools/apps/maps.md#import_map_devices) |
| `share_map_device` | Share a device via link | [Maps](tools/apps/maps.md#share_map_device) |
| `list_shared_map_devices` | List device shares on a map | [Maps](tools/apps/maps.md#list_shared_map_devices) |
| `remove_map_device_share` | Revoke a device share | [Maps](tools/apps/maps.md#remove_map_device_share) |
| `add_shared_device_to_map` | Add a shared device to a map | [Maps](tools/apps/maps.md#add_shared_device_to_map) |
| `list_map_tracks` | List GPS tracks | [Maps](tools/apps/maps.md#list_map_tracks) |
| `get_map_track` | Get track details/content | [Maps](tools/apps/maps.md#get_map_track) |
| `update_map_track` | Update track metadata | [Maps](tools/apps/maps.md#update_map_track) |
| `export_map_route` | Export route as GPX | [Maps](tools/apps/maps.md#export_map_route) |
| `list_map_photos` | List geolocated photos | [Maps](tools/apps/maps.md#list_map_photos) |
| `list_map_photos_nonlocalized` | List photos without GPS | [Maps](tools/apps/maps.md#list_map_photos_nonlocalized) |
| `place_map_photos` | Set GPS coords on photos | [Maps](tools/apps/maps.md#place_map_photos) |
| `reset_map_photo_coords` | Remove GPS from photos | [Maps](tools/apps/maps.md#reset_map_photo_coords) |
| `get_map_photo_job_status` | Photo geolocation job status | [Maps](tools/apps/maps.md#get_map_photo_job_status) |
| `list_map_contacts` | List contacts with addresses | [Maps](tools/apps/maps.md#list_map_contacts) |
| `search_map_contacts` | Search contacts by name | [Maps](tools/apps/maps.md#search_map_contacts) |
| `place_map_contact` | Put a contact on the map | [Maps](tools/apps/maps.md#place_map_contact) |
| `add_contact_to_map` | Copy a contact into a map | [Maps](tools/apps/maps.md#add_contact_to_map) |
| `delete_map_contact_address` | Remove a contact's address | [Maps](tools/apps/maps.md#delete_map_contact_address) |
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

#### Shares (10 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_shares` | List file shares in Nextcloud (for diagnostics and security auditing) | [Shares](tools/apps/shares.md#list_shares) |
| `create_share` | Create a file or folder share in Nextcloud | [Shares](tools/apps/shares.md) |
| `update_share` | Update an existing share in Nextcloud | [Shares](tools/apps/shares.md) |
| `delete_share` | Delete a share in Nextcloud | [Shares](tools/apps/shares.md) |
| `get_share` | Get detailed information about a specific share by its ID | [Shares](tools/apps/shares.md) |
| `list_shares_with_me` | List all files and folders shared with the current user | [Shares](tools/apps/shares.md) |
| `search_sharees` | Search for valid share recipients (users, groups, emails, federated users, circles… | [Shares](tools/apps/shares.md) |
| `list_pending_shares` | List pending federated/remote shares waiting to be accepted or declined | [Shares](tools/apps/shares.md) |
| `accept_pending_share` | Accept a pending federated/remote share | [Shares](tools/apps/shares.md) |
| `decline_pending_share` | Decline a pending federated/remote share | [Shares](tools/apps/shares.md) |

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

#### File Tags (6 tools)
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
#### Talk (10 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `talk_list_conversations` | List all Talk conversations the user has access to | — |
| `talk_list_messages` | List recent messages in a Talk conversation | — |
| `talk_send_message` | Send a message to a Talk conversation | — |
| `talk_create_conversation` | Create a new Talk conversation | — |
| `talk_list_participants` | List all participants in a Talk conversation with their roles | — |
| `talk_add_participant` | Add a user, group, or email participant to a Talk conversation | — |
| `talk_remove_participant` | Remove a participant from a Talk conversation by their attendee ID (from… | — |
| `talk_delete_message` | Delete a message from a Talk conversation | — |
| `talk_create_poll` | Create a poll in a Talk conversation | — |
| `talk_react_to_message` | Add an emoji reaction to a message in a Talk conversation | — |

#### Deck (12 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `deck_list_boards` | List all Deck boards | — |
| `deck_get_board` | Get details of a Deck board including its labels and access control list (ACL) | — |
| `deck_create_board` | Create a new Deck board | — |
| `deck_list_stacks` | List all stacks (columns) of a Deck board, including the cards in each stack | — |
| `deck_create_stack` | Create a new stack (column) on a Deck board | — |
| `deck_get_card` | Get full details of a Deck card including description, labels, and assigned users | — |
| `deck_create_card` | Create a new card in a Deck stack | — |
| `deck_update_card` | Update an existing Deck card | — |
| `deck_move_card` | Move a card to a different stack (column) on the same board | — |
| `deck_archive_card` | Archive or unarchive a Deck card | — |
| `deck_assign_label` | Assign a label to a Deck card | — |
| `deck_assign_user` | Assign a user to a Deck card | — |

#### Photos (11 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `photos_list_albums` | List all photo albums owned by the current user | — |
| `photos_get_album` | Get details of a photo album including its files | — |
| `photos_create_album` | Create a new photo album | — |
| `photos_delete_album` | Delete a photo album | — |
| `photos_rename_album` | Rename a photo album | — |
| `photos_add_to_album` | Add one or more files to a photo album | — |
| `photos_remove_from_album` | Remove one or more files from a photo album by file ID (use photos_get_album to find… | — |
| `photos_get_metadata` | Get photo/video metadata (EXIF) for a file | — |
| `photos_set_favorite` | Mark or unmark a file as favorite | — |
| `photos_set_album_location` | Set or update the location metadata on a photo album | — |
| `photos_add_collaborators` | Add collaborators (users or groups) to a photo album | — |

#### Circles (8 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `circles_list` | List all circles/teams accessible to the current user | — |
| `circles_get` | Get detailed information about a specific circle/team, including its description… | — |
| `circles_create` | Create a new circle/team | — |
| `circles_delete` | Delete a circle/team | — |
| `circles_list_members` | List all members of a circle/team | — |
| `circles_add_member` | Add a member to a circle/team | — |
| `circles_remove_member` | Remove a member from a circle/team | — |
| `circles_search` | Search for circles/teams by name | — |

#### Projects (7 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_projects` | List all AIquila projects for the current user | — |
| `create_project` | Create a new AIquila project | — |
| `get_project` | Get details of an AIquila project including its file/directory paths | — |
| `update_project` | Update an AIquila project | — |
| `delete_project` | Delete an AIquila project | — |
| `add_project_path` | Add a file or directory path to an AIquila project | — |
| `remove_project_path` | Remove a file or directory path from an AIquila project | — |

#### Coworkers (12 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_coworkers` | List the current user's coworkers (persistent scheduled AI tasks) with their status… | — |
| `list_coworker_templates` | List built-in coworker templates (e.g | — |
| `get_coworker` | Get a single coworker by ID, including its schedule and last run status | — |
| `create_coworker` | Create a coworker | — |
| `update_coworker` | Update a coworker's configuration (title, provider, input folder, schedule, options) | — |
| `delete_coworker` | Delete a coworker and its run history | — |
| `enable_coworker` | Enable a coworker so it runs on its schedule | — |
| `disable_coworker` | Disable a coworker so it stops running on its schedule | — |
| `pause_coworker` | Temporarily pause a coworker without disabling it | — |
| `resume_coworker` | Resume a paused coworker | — |
| `run_coworker` | Run a coworker immediately (synchronously) and return the run result | — |
| `get_coworker_runs` | Get recent run history (progress, status, summary) for a coworker | — |

#### Text (5 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_text_workspace` | Get metadata of the Text workspace file (Readme.md) for a folder | — |
| `read_text_workspace` | Read the content of a folder's Text workspace file (Readme.md) | — |
| `write_text_workspace` | Create or overwrite a folder's Text workspace file | — |
| `delete_text_workspace` | Delete the Text workspace file (Readme.md) for a folder | — |
| `get_text_workspace_edit_url` | Get a one-shot direct-edit URL for a folder's Text workspace | — |

#### Trash (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_trash` | List files in the trash / recycle bin | — |
| `restore_from_trash` | Restore a file from the trash to its original location (use the Key from list_trash) | — |
| `empty_trash` | Permanently delete all files in the trash (cannot be undone) | — |

#### File Versions (2 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_file_versions` | List previous versions of a file (use get_file_info to find the fileId) | — |
| `restore_file_version` | Restore a previous version of a file (creates a new current version from the old one) | — |

#### Notifications (4 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_notifications` | List all notifications for the current user | — |
| `get_notification` | Get details of a specific notification by ID | — |
| `mark_notification_read` | Mark a notification as read (deletes it) | — |
| `delete_all_notifications` | Delete all notifications for the current user | — |

#### User Status (5 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_user_status` | Get the current user's presence status (online, away, DND, invisible, custom message) | — |
| `set_user_status` | Set the current user's status type (online, away, dnd, invisible, offline) | — |
| `set_user_status_message` | Set a custom status message with optional emoji icon and auto-clear time | — |
| `clear_user_status_message` | Clear the current user's custom status message | — |
| `list_user_statuses` | List all users' statuses for team presence visibility | — |

#### Out of Office (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_out_of_office` | Get a user's current out-of-office / absence status (NC 28+) | — |
| `set_out_of_office` | Set an out-of-office / absence period with status message (NC 28+) | — |
| `clear_out_of_office` | Clear a user's out-of-office / absence status (NC 28+) | — |

#### Announcements (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_announcements` | List announcements from the Nextcloud Announcement Center (org-wide notices such as | — |
| `create_announcement` | Create a new announcement in the Announcement Center | — |
| `delete_announcement` | Delete an announcement by its ID | — |

#### Passman (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `passman_list_vaults` | List all Passman password vaults | — |
| `passman_list_credentials` | List credentials (metadata only) in a Passman vault | — |
| `passman_get_credential_info` | Get non-secret metadata for a single Passman credential (label, id, timestamps, flags) | — |

#### Registration (3 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_registration_settings` | Read the Nextcloud Registration app settings (self-service signup configuration), such… | — |
| `update_registration_settings` | Update one or more Nextcloud Registration app settings | — |
| `reset_registration_setting` | Reset a Nextcloud Registration app setting to its default by deleting the stored value | — |

#### Social Sharing (1 tool)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `generate_social_share_links` | Generate social-network share URLs (email, X/Twitter, Facebook, Telegram, WhatsApp, | — |

#### Terms of Service (5 tools)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `get_terms_of_service` | Read the Nextcloud Terms of Service admin configuration: all published terms (by | — |
| `set_terms_of_service` | Create or update the terms of service for a given country/language pair | — |
| `delete_terms_of_service` | Delete a single terms of service entry by its id (see get_terms_of_service) | — |
| `reset_terms_signatures` | Reset ALL users' terms of service signatures org-wide, forcing every user to accept | — |
| `update_terms_settings` | Update the Terms of Service enforcement settings: whether logged-in users must accept | — |

#### Translate (1 tool)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `translate_text` | Translate text between languages using Nextcloud's configured translation provider | — |

#### Recommendations (1 tool)
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_recommendations` | List files Nextcloud recommends for the configured user (e.g | — |

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
