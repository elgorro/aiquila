# Best Practices

## Security

### API Key Management

- **Never commit API keys** to version control
- Use environment variables or secure storage
- Rotate keys periodically
- Use separate keys for development and production

### Nextcloud App Passwords

- Create dedicated app passwords for AIquila
- Use descriptive names (e.g., "AIquila MCP - Home Server")
- Revoke unused passwords promptly
- Monitor active sessions in Nextcloud security settings

### Network Security

- Always use HTTPS for Nextcloud
- Consider IP allowlisting for API access
- Use VPN or Tailscale for remote MCP server access
- Keep Nextcloud and Node.js updated

## Usage Recommendations

### MCP Server

**Do:**
- Use specific, clear commands: "Create a task called 'Buy milk' in my personal list"
- Specify full paths when needed: "Read the file /Documents/notes.md"
- Use natural language - Claude understands context

**Don't:**
- Share sensitive data unnecessarily
- Use for bulk operations (API rate limits apply)
- Leave MCP server running when not needed

### Nextcloud App

**Do:**
- Summarize large documents before asking detailed questions
- Use context wisely - include relevant file content
- Set user-level API keys for heavy users

**Don't:**
- Process very large files (>100KB) - summarize first
- Use for real-time/streaming needs
- Store sensitive data in Claude's context

## Performance

### MCP Server

- Build for production: `npm run build`
- Use Node.js 18+ for better performance
- Consider caching for frequently accessed data
- Monitor WebDAV connection health

### Nextcloud App

- Use async API calls where possible
- Implement request timeouts
- Cache API responses when appropriate
- Consider rate limiting for multi-user deployments

## Error Handling

### Common Issues

| Issue | Solution |
|-------|----------|
| "No API key configured" | Set key in admin or user settings |
| WebDAV 401 errors | Check app password validity |
| CalDAV task creation fails | Verify calendar name matches exactly |
| Timeouts | Check network connectivity, increase timeout |

### Logging

Enable debug logging during development:

**MCP Server:**
```bash
DEBUG=* npm run dev
```

**Nextcloud:**
```php
// config/config.php
'loglevel' => 0,  // Debug level
```

## Maintenance

### Regular Tasks

- **Weekly**: Check Nextcloud logs for errors
- **Monthly**: Review and rotate API keys
- **Quarterly**: Update dependencies
- **As needed**: Review app password usage

### Updates

**MCP Server:**
```bash
cd mcp-server
npm update
npm run build
# Restart Claude Desktop
```

**Nextcloud App:**
```bash
cd /path/to/nextcloud
sudo -u www-data php occ upgrade
sudo -u www-data php occ app:update aiquila
```

## Contributing

### Before Submitting PRs

1. Run tests: `npm test` (MCP) / `composer test` (PHP)
2. Check types: `npx tsc --noEmit`
3. Follow existing code style
4. Update documentation for new features
5. Add tests for new functionality

### Commit Messages

Use conventional commits:
- `feat: add photo tagging support`
- `fix: handle empty task lists`
- `docs: update installation guide`
- `test: add ClaudeService unit tests`

## Roadmap Considerations

When extending AIquila:

1. **New MCP tools**: Keep them focused and single-purpose
2. **New NC endpoints**: Follow Nextcloud API conventions
3. **New integrations**: Check for existing Nextcloud APIs first
4. **Performance**: Test with realistic data volumes
5. **Security**: Review all input handling and API access
