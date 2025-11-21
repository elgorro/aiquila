# Development Guide

## Project Structure

```
nextclaude/
├── mcp-server/          # MCP server (TypeScript/Node.js)
│   ├── src/
│   │   ├── index.ts     # Main server with all tools
│   │   └── __tests__/   # Vitest tests
│   ├── dist/            # Compiled output
│   └── package.json
├── nextcloud-app/       # Nextcloud app (PHP)
│   ├── appinfo/
│   │   ├── info.xml     # App manifest
│   │   └── routes.php   # API routes
│   ├── lib/
│   │   ├── AppInfo/     # App bootstrap
│   │   ├── Controller/  # API controllers
│   │   ├── Service/     # Business logic
│   │   └── Settings/    # Admin settings
│   ├── js/              # Frontend JavaScript
│   ├── css/             # Styles
│   ├── templates/       # PHP templates
│   └── tests/           # PHPUnit tests
└── docs/                # Documentation
```

## MCP Server Development

### Setup

```bash
cd mcp-server
npm install
```

### Development workflow

```bash
# Run with hot reload
npm run dev

# Type check
npx tsc --noEmit

# Run tests
npm test

# Watch tests
npm run test:watch

# Build for production
npm run build
```

### Adding a new tool

Edit `src/index.ts`:

```typescript
server.tool(
  "tool_name",
  "Tool description for Claude",
  {
    param1: z.string().describe("Parameter description"),
    param2: z.number().optional().describe("Optional param"),
  },
  async ({ param1, param2 }) => {
    // Implementation
    return { content: [{ type: "text", text: "Result" }] };
  }
);
```

### Testing with Claude

1. Build: `npm run build`
2. Update `claude_desktop_config.json` to point to your dev build
3. Restart Claude Desktop
4. Test the tools in conversation

## Nextcloud App Development

### Setup

```bash
cd nextcloud-app
composer install  # If available
```

### Linking to Nextcloud

```bash
ln -s /path/to/nextclaude/nextcloud-app /path/to/nextcloud/apps/nextclaude
sudo -u www-data php /path/to/nextcloud/occ app:enable nextclaude
```

### Adding a new API endpoint

1. Add route in `appinfo/routes.php`:
```php
['name' => 'controller#action', 'url' => '/api/endpoint', 'verb' => 'POST'],
```

2. Create or update controller in `lib/Controller/`:
```php
/**
 * @NoAdminRequired
 */
public function action(): JSONResponse {
    // Implementation
    return new JSONResponse(['result' => 'data']);
}
```

### Adding a file action

Edit `js/fileactions.js` to register new actions:

```javascript
fileActions.registerAction({
    name: 'my-action',
    displayName: t('nextclaude', 'My Action'),
    mime: 'text',
    permissions: OC.PERMISSION_READ,
    actionHandler: async function(fileName, context) {
        // Implementation
    },
});
```

### Running tests

```bash
# PHP tests (requires composer)
composer test

# Or with PHPUnit directly
./vendor/bin/phpunit
```

## Code Style

### TypeScript (MCP Server)

- Use TypeScript strict mode
- Async/await for all async operations
- Zod for parameter validation

### PHP (Nextcloud App)

- Follow Nextcloud coding standards
- Use dependency injection
- Type hints for all parameters and returns
- PHPDoc for public methods

## Debugging

### MCP Server

```bash
# Run with debug output
DEBUG=* npm run dev

# Check Claude Desktop logs
tail -f ~/.config/claude/logs/mcp.log  # Linux
```

### Nextcloud App

```php
// Add to your code temporarily
\OC::$server->getLogger()->debug('Debug message', ['app' => 'nextclaude']);
```

Check logs:
```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```
