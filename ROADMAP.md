# AIquila Roadmap

## Phase 1: Foundation ✅
*Release: v0.1.1*

### MCP Server
- [x] File operations (list, read, write, delete)
- [x] Task creation via CalDAV
- [x] Notes creation
- [x] Recipe creation
- [] SSE Support

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
- [ ] Photo tagging via Claude Vision API
- [ ] Calendar event creation
- [ ] Native Cookbook app API integration
- [ ] Search files by content
- [ ] List/complete tasks

### Nextcloud App
- [ ] Conversation history storage
- [ ] Token usage tracking
- [ ] Streaming responses

### Infrastructure
- [ ] Docker dev environment for improved testing capabilities

---

## Phase 3: Advanced Features
*Target: v0.3.0*

### MCP Server
- [ ] Bulk file operations
- [ ] Nextcloud Talk integration
- [ ] Deck (kanban) integration
- [ ] Contacts integration

### Nextcloud App
- [ ] Multi-turn conversations with file context
- [ ] Text editor integration (inline AI)
- [ ] Batch document processing
- [ ] Custom prompts/templates

---

## Phase 4: Polish & Distribution
*Target: v1.0.0*

- [ ] Nextcloud App Store submission
- [ ] npm package for MCP server
- [ ] Performance optimization
- [ ] Comprehensive error handling
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
- [ ] npm package publishing for MCP server
- [ ] Homebrew formula for easy installation
- [ ] Docker images for MCP server

---

## Contributing

See an item you'd like to work on? Check our [issues](../../issues) or open a new one!

Feature requests and feedback welcome via [GitHub Issues](../../issues/new?template=feature_request.md).
