# AIquila Roadmap

## Phase 1: Foundation ✅
*Release: v0.1.1*

### MCP Server
- [x] File operations (list, read, write, delete)
- [x] Task creation via CalDAV
- [x] Notes creation
- [x] Recipe creation

### Nextcloud App
- [x] Admin/user API key settings
- [x] User settings UI with personal API key override
- [x] "Ask Claude" file action
- [x] Document summarization API
- [x] Input validation and rate limiting
- [x] Configurable settings (model, tokens, timeout)

### Infrastructure
- [x] CI/CD workflows
- [x] Documentation
- [x] Tests
- [x] Automated GitHub releases (separate for MCP & Nextcloud)
- [x] Nextcloud App Store publishing (semi-automated with approval gate)

---

## Phase 2: Enhanced Integrations
*Target: v0.2.0*

### MCP Server

**Transport & Architecture**
- [x] SSE / Streamable HTTP transport (Docker-ready)
- [x] Server factory refactoring (per-connection server instances)
- [x] Centralized versioning (single source of truth in package.json)

**Auth**
- [x] Built-in OAuth 2.0 provider (auth codes, JWT access/refresh tokens, PKCE)
- [x] Claude.ai remote MCP integration (`MCP_AUTH_ENABLED`)
- [x] Dynamic and static client registration

**Observability**
- [x] Structured JSON logging (pino, `LOG_LEVEL`)

**App Integrations**
- [x] Calendar events (full CRUD with recurrence, attendees, alarms)
- [x] Tasks (full CRUD with subtasks, priorities, categories)
- [x] Contacts (full CRUD via CardDAV with structured fields)
- [x] Mail (accounts, mailboxes, messages, send, flags)
- [x] Bookmarks (CRUD bookmarks, folders, tags)
- [x] Maps (favorites, devices, tracks, photos, custom maps, import/export)
- [x] Cookbook (native API integration with schema.org recipes)
- [x] Notes (markdown-based with search)

**System & Administration**
- [x] System API integration (status, setup checks)
- [x] App management (list, enable/disable apps)
- [x] User & group management (list, enable/disable, group membership)
- [x] File sharing audit (list shares with type/permissions)
- [x] Security checks (core & app integrity verification)
- [x] OCC command execution (arbitrary Nextcloud CLI commands)
- [x] AIquila self-configuration tools (show/configure/test)

**Files & Search**
- [x] Search files by content
- [x] Photo tagging via Claude Vision API (get_file_content + tag tools)
- [x] File tagging (personal + system tags)

**Testing**
- [x] Dedicated test suites (server, tags, transports)

### Nextcloud App
- [x] Streaming responses
- [x] Opus 4.6 model support
- [ ] Conversation history storage
- [ ] Token usage tracking
- [ ] File access in chat window
- [ ] MCP/API access

### Infrastructure
- [x] Docker dev environment for improved testing capabilities
- [x] Docker for MCP Server (HTTP transport on port 3339)
- [x] Docker Github Release
- [x] Update docs
- [ ] OpenAPI support

---

## Phase 3: Advanced Features
*Target: v0.3.0*

### MCP Server

**Core**
- [ ] Bulk file operations

**Collaboration**
- [ ] Talk integration (chat, calls, conversations)
- [ ] Deck integration (kanban boards, cards, stacks)
- [ ] Circles integration (user groups/teams)
- [ ] Polls integration
- [ ] Forms integration

**Content & Media**
- [ ] Text integration (collaborative documents)
- [ ] News integration (RSS feeds)
- [ ] Photos tools (albums, metadata)
- [ ] Recognize integration (AI-based image classification)
- [ ] Files Zip (archive creation/extraction)

**Administration**
- [ ] Activity feed integration
- [ ] Announcements integration
- [ ] Registration management
- [ ] Terms of Service management
- [ ] File recommendations
- [ ] Social sharing
- [ ] Passman integration (password management)

### Nextcloud App
- [ ] Multi-turn conversations with file context
- [ ] Text editor integration (inline AI)
- [ ] Batch document processing
- [ ] Custom prompts/templates

---

## Phase 4: Alternative Providers
*Target: v0.4.0*

### MCP Server
- [ ] Mistral API integration
- [ ] Coworker implementation (agent-driven automation)

### Nextcloud App
- [ ] Mistral API support
- [ ] UI for switching model provider
- [ ] Coworker (repeated/scheduled jobs)

---

## Final Phase: Polish & Distribution
*Target: v1.0.0*

- [ ] Nextcloud App Store official listing (reviewed & approved)
- [ ] npm package for MCP server
- [ ] Performance optimization
- [ ] Internationalization (i18n)
- [ ] User documentation portal

---

## Future Automation Ideas

### Release Process Improvements
- [ ] Automated version bumping across all files (package.json, info.xml)
  - Consider: [semantic-release](https://github.com/semantic-release/semantic-release) or [release-please](https://github.com/googleapis/release-please)
- [ ] Automated changelog generation from conventional commits
  - Consider: conventional-changelog or semantic-release
- [ ] Version consistency validation (pre-commit hook)
- [ ] Automated dependency updates (Dependabot/Renovate)

### Distribution
- [x] Docker images for MCP server
- [ ] npm package publishing for MCP server
- [ ] Homebrew formula for easy installation

---

## Contributing

See an item you'd like to work on? Check our [issues](../../issues) or open a new one!

Feature requests and feedback welcome via [GitHub Issues](../../issues/new?template=feature_request.md).
