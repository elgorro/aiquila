# Development Guide

## Project Structure

```
aiquila/
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
  "Tool description for the MCP client",
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

### Testing with an MCP Client

1. Build: `npm run build`
2. Update your MCP client config to point to your dev build
3. Restart your MCP client
4. Test the tools in conversation

## Nextcloud App Development

### Setup

```bash
cd nextcloud-app
composer install  # If available
```

### Frontend dependencies

The Vue frontend is bundled by Vite; Nextcloud ships no shared runtime for it, so
every library the app imports must be declared in `nextcloud-app/package.json`.
A package that is only reachable through someone else's dependency tree works
until that dependency drops it — declare what you `import`.

Two constraints are worth knowing before bumping anything:

- **`@nextcloud/vue` pins `@nextcloud/files` and `@nextcloud/dialogs`.** Each
  `@nextcloud/vue` release peer-requires specific majors of both, so a major bump
  of either may need a newer `@nextcloud/vue` first.
  Check with `npm view @nextcloud/vue@<version> peerDependencies`.
- **Most of the `@nextcloud/*` family declares support only up to Node 24**, while
  this repo builds on Node 26. `npm install` therefore prints `EBADENGINE` warnings
  for those packages. They are advisory — the builds and tests pass — but an
  `engine-strict` install would fail on them.

Use `npm view <pkg> dist-tags` to find the current release. `npm outdated`
misreports the "Latest" column for the `@nextcloud/*` packages.

### Linking to Nextcloud

```bash
ln -s /path/to/aiquila/nextcloud-app /path/to/nextcloud/apps/aiquila
sudo -u www-data php /path/to/nextcloud/occ app:enable aiquila
```

### Adding a new API endpoint

1. Add route in `appinfo/routes.php`:
```php
['name' => 'controller#action', 'url' => '/api/endpoint', 'verb' => 'POST'],
```

2. Create or update controller in `lib/Controller/`. Add an `#[OpenAPI]` attribute and
   typed PHPDoc so the endpoint appears in the OpenAPI spec:
```php
use OpenAPI\Attributes as OA;

/**
 * Short description
 *
 * @return JSONResponse<Http::STATUS_OK, array{result: string}, array{}>
 *
 * 200: Success
 */
#[OA\OpenAPI]
#[NoAdminRequired]
public function action(): JSONResponse {
    // Implementation
    return new JSONResponse(['result' => 'data']);
}
```

3. Regenerate and commit the spec:
```bash
cd nextcloud-app && make openapi
git add openapi.json openapi-administration.json openapi-full.json
```

See [`docs/dev/openapi.md`](openapi.md) for full annotation rules and CI details.

### Adding a file action

Files-app actions are registered in `src/fileactions.js` with `registerFileAction()`
from `@nextcloud/files`, which takes a plain `IFileAction` object (`id`,
`displayName`, `iconSvgInline`, `enabled`, `exec`). `LoadFilesScriptsListener`
loads the script whenever the Files app dispatches `LoadAdditionalScriptsEvent`.

The script loads on every Files page view, so it is built on its own by
`vite.files.config.js` (the second half of `npm run build`) and shares no chunks
with the app bundle. Keep its static imports to `@nextcloud/files` and
`@nextcloud/l10n`, and `import()` anything heavier, such as Vue components, from
`exec`.

### Code highlighting in rendered Markdown

Chat replies and Coworker summaries are rendered by `renderMarkdown()` in
`src/utils/markdown.js` (`marked` plus highlight.js). highlight.js is not part of the
eager bundle. Its core loads with the first fenced code block, and each grammar
loads with the first fence that names it. Until then the block shows as escaped
plain text. A Vue `ref` read inside `renderMarkdown()` then makes the calling
computed property re-render highlighted, so components need no extra code.

- **Adding a language**: add an entry to `LOADERS`, written out as
  `() => import('highlight.js/lib/languages/<name>')`. Vite cannot expand a
  template-literal `import()` of a bare package path. Put alternative fence
  names in `ALIASES`.
- **Unlabelled fences** are auto-detected only among `AUTO_DETECT`. Keep that
  list short: the first unlabelled block loads all of those grammars.
- **Do not pin highlight.js in `manualChunks`** (`vite.config.js`). That merges
  the dynamic chunks back into one chunk that loads up front. Only `marked`
  belongs in `vendor-markdown`.

### Running tests

```bash
# PHP tests (requires composer)
composer test

# Or with PHPUnit directly
./vendor/bin/phpunit
```

`phpunit.xml` sets `failOnPhpunitDeprecation` and `failOnPhpunitNotice`, so the
suite fails on how a test double is used, not only on assertions:

- Use `createStub()` for a collaborator the test only needs answers from, and
  `createMock()` only when the test verifies calls with `expects()`. A mock with
  no expectations is a notice.
- `with()` needs an explicit `expects()` in front of it. For an answer that
  depends on the arguments, use `willReturnMap()` or `willReturnCallback()` on a stub.
- Fixtures shared through `setUp()` are stubs. A test that verifies one swaps in
  a mock and rebuilds the object under test, as the `build…()` helpers do.

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

### JSON objects in PHP

`json_decode(..., true)` turns an empty JSON object `{}` into an empty PHP array, and
`json_encode` writes that back as the list `[]`. An object with the keys `"0"`, `"1"`,
… comes back as a list too. JSON the app decodes and later sends on, to a model
provider or an MCP server, can therefore turn an object into a list. Tool schemas,
tool-call arguments and stored tool definitions are all affected.

Send such values through `OCA\AIquila\Service\JsonShape` at the point where they
leave the app:

| Value | Helper |
|---|---|
| Must be an object as a whole: MCP `tools/call` `arguments`, `tool_use.input`, the JSON in an OpenAI `function.arguments` string | `JsonShape::object()` |
| A tool's input schema: MCP `inputSchema`, Anthropic `input_schema`, OpenAI `parameters` | `JsonShape::toolInputSchema()` (any other JSON Schema: `JsonShape::schema()`) |
| Tool-call arguments where the tool's schema is known | `JsonShape::arguments($args, $schema)`, which also restores nested objects the schema requires |

Assert on the encoded JSON in tests, not on the decoded array: `{}` and `[]` are the
same PHP value, so only the bytes show the difference.

### Entities and mappers

An `update()` writes the fields marked as changed on the entity, not the whole row.
Nextcloud's `QBMapper` never clears those marks, so an instance that was inserted or
updated once would write every field set on it again on its next `update()`. If
another request changed one of those columns in between, its change is lost.

- Mappers extend `OCA\AIquila\Db\ChangeTrackingMapper`, not `QBMapper`. It clears
  the marks after every successful `insert()` and `update()`, so the next `update()`
  writes only what changed since. A test checks that every mapper does.
- Entities use `InsertsFullRow` so an insert writes default values too.
- That still lets two requests that write the *same* column overwrite each other.
  Where that matters, decide from the stored row: re-read before computing the value
  (`CoworkerService::reloadSchedule()`), or make the write conditional
  (`ConversationMapper::setTitleIfUntitled()`).

## Debugging

### MCP Server

```bash
# Run with verbose structured logging
LOG_LEVEL=debug npm run dev

# Check MCP client logs (example: Claude Desktop on Linux)
tail -f ~/.config/claude/logs/mcp.log
```

Logs are emitted as JSON to stderr. Set `LOG_LEVEL` to `trace` for maximum verbosity or `warn` to suppress startup noise in production.

### Nextcloud App

```php
// Add to your code temporarily
\OC::$server->getLogger()->debug('Debug message', ['app' => 'aiquila']);
```

Check logs:
```bash
tail -f /path/to/nextcloud/data/nextcloud.log
```
