# MCP Server Architecture (Developer Reference)

Technical architecture overview of the AIquila MCP Server for developers working on the codebase.

## Overview

The MCP Server is a TypeScript-based implementation of the Model Context Protocol that provides Claude Desktop with tools to interact with Nextcloud. It follows a modular architecture pattern with clear separation of concerns.

## Technology Stack

- **Runtime**: Node.js 24+
- **Language**: TypeScript 5.8+ (strict mode)
- **Protocol**: Model Context Protocol (MCP) via `@modelcontextprotocol/sdk`
- **Transport**: stdio (JSON-RPC) or Streamable HTTP
- **Dependencies**:
  - `pino` ^9.x - Structured JSON logging
  - `webdav` ^5.x - WebDAV client
  - `zod` ^3.x - Schema validation
- **Dev Tools**:
  - `vitest` - Testing framework
  - `tsx` - TypeScript execution with hot reload
  - `eslint` + `prettier` - Code quality

## Project Structure

```
mcp-server/
├── src/
│   ├── index.ts                 # Main server & registration
│   ├── logger.ts                # Shared pino logger instance
│   ├── client/                  # Infrastructure layer
│   │   ├── webdav.ts           # WebDAV singleton client
│   │   └── caldav.ts           # CalDAV helper functions
│   └── tools/                   # Business logic layer
│       ├── types.ts             # Shared types & schemas
│       ├── system/              # System-level tools
│       │   └── files.ts         # 5 file operation tools
│       └── apps/                # App-specific tools
│           ├── tasks.ts         # 2 Tasks tools (CalDAV)
│           ├── cookbook.ts      # 1 Cookbook tool (WebDAV)
│           ├── notes.ts         # 1 Notes tool (WebDAV)
│           └── aiquila.ts       # 3 AIquila internal tools
├── dist/                        # Compiled JS output
├── src/__tests__/               # Vitest test files
├── package.json
├── tsconfig.json
└── README.md
```

## Architecture Layers

### 1. Transport Layer (index.ts)

**Responsibility**: MCP protocol handling and tool registration.

**Components**:
- `McpServer` instance creation
- `StdioServerTransport` for stdio communication
- Tool registration via `registerTools()`
- Request routing to tool handlers

**Code Flow**:
```typescript
main()
  → registerTools()
    → server.registerTool() × 12 tools
  → server.connect(transport)
  → listens for JSON-RPC requests
```

**Key Methods**:
- `registerTools()` - Iterates through all tool modules and registers each tool
- `main()` - Initializes server and connects transport
- Error handling wrapper for tool execution

### 2. Client Layer (client/)

**Responsibility**: Manage external service connections.

#### WebDAV Client (webdav.ts)

**Pattern**: Singleton

**Purpose**: Provide a single, reusable WebDAV client instance for file operations.

**Implementation**:
```typescript
let webdavClient: WebDAVClient | null = null;

export function getWebDAVClient(): WebDAVClient {
  if (!webdavClient) {
    webdavClient = createClient(`${url}/remote.php/dav/files/${user}`, {
      username, password
    });
  }
  return webdavClient;
}
```

**Why Singleton?**
- Connection pooling
- Avoid multiple authentication flows
- Shared state across tool invocations

**Used By**: files.ts, cookbook.ts, notes.ts

#### CalDAV Client (caldav.ts)

**Pattern**: Functional helper

**Purpose**: Provide authenticated HTTP fetch for CalDAV operations.

**Implementation**:
```typescript
export async function fetchCalDAV(url: string, options: {...}) {
  return fetch(url, {
    headers: {
      Authorization: `Basic ${auth}`,
      'Content-Type': 'application/xml; charset=utf-8',
      ...
    },
    ...options
  });
}
```

**Used By**: tasks.ts

### 3. Business Logic Layer (tools/)

**Responsibility**: Implement tool functionality.

#### Tool Structure Pattern

Every tool follows this structure:

```typescript
export const toolName = {
  name: string,              // Unique identifier
  description: string,       // User-facing description
  inputSchema: ZodObject,    // Zod schema for parameters
  handler: AsyncFunction,    // Implementation logic
};
```

**Handler Signature**:
```typescript
handler: async (args: SchemaType) => {
  return {
    content: [{ type: 'text', text: string }],
    isError?: boolean
  };
}
```

#### Module Pattern

Each module exports an array of tools:

```typescript
// tools/apps/tasks.ts
export const tasksTools = [
  listTaskListsTool,
  createTaskTool,
];

// Imported in index.ts
import { tasksTools } from './tools/apps/tasks.js';
```

**Benefits**:
- Easy to add/remove tools
- Clear module boundaries
- Simple registration loop

### 4. Type Safety Layer (tools/types.ts)

**Responsibility**: Shared type definitions and utilities.

**Contents**:
- `ToolResponse` interface
- Common Zod schemas (`PathSchema`, `FileContentSchema`, etc.)
- `NextcloudConfig` interface
- `getNextcloudConfig()` helper

**Why Separate?**
- DRY principle - reuse common types
- Single source of truth
- Type consistency across modules

## Data Flow

### Request Flow

```
1. Claude Desktop sends request
   ↓ (stdio - JSON-RPC)
2. McpServer receives via StdioServerTransport
   ↓
3. Server finds tool by name
   ↓
4. Zod validates parameters
   ↓
5. Tool handler executes
   ├→ Gets client (WebDAV/CalDAV)
   ├→ Performs operation on Nextcloud
   └→ Returns formatted response
   ↓
6. Server wraps response in MCP format
   ↓ (stdio - JSON-RPC)
7. Claude Desktop receives and displays
```

### Example: create_note Flow

```
User: "Create a note called 'Ideas' with content 'Project brainstorm'"
  ↓
Claude determines: use "create_note" tool
  ↓
JSON-RPC request: {
  method: "tools/call",
  params: {
    name: "create_note",
    arguments: {
      title: "Ideas",
      content: "Project brainstorm"
    }
  }
}
  ↓
MCP Server: routes to createNoteTool.handler()
  ↓
Handler:
  1. getWebDAVClient()
  2. noteContent = `# Ideas\n\nProject brainstorm`
  3. client.putFileContents('/Notes/Ideas.md', noteContent)
  ↓
Response: {
  content: [{
    type: "text",
    text: "Note 'Ideas' created successfully at /Notes/Ideas.md"
  }]
}
  ↓
Claude: "I've created a note called 'Ideas' with your brainstorm content."
```

## Design Patterns

### 1. Singleton Pattern (WebDAV Client)

**Use Case**: Manage single shared resource.

**Implementation**: Lazy initialization with null check.

**Benefits**:
- Resource efficiency
- Consistent state
- Easy to test with reset function

### 2. Factory Pattern (Tool Creation)

**Use Case**: Create tool objects with consistent structure.

**Implementation**: Each tool module exports a factory array.

**Benefits**:
- Uniform tool interface
- Easy to register
- Type-safe

### 3. Dependency Injection

**Use Case**: Tools don't create their own clients.

**Implementation**: Tools call `getWebDAVClient()` or `fetchCalDAV()`.

**Benefits**:
- Testability (can mock clients)
- Loose coupling
- Flexible configuration

### 4. Strategy Pattern (Tool Handlers)

**Use Case**: Different implementations for different tools.

**Implementation**: Each tool has its own handler function.

**Benefits**:
- Encapsulation
- Easy to modify
- Clear responsibilities

## Configuration Management

### Environment Variables

Required variables validated at runtime:
```typescript
NEXTCLOUD_URL       // e.g., https://cloud.example.com
NEXTCLOUD_USER      // Nextcloud username
NEXTCLOUD_PASSWORD  // App password or user password
```

### Validation Strategy

**Early Failure**: Validate on first client access, not at startup.

**Why?**
- Allow server to start without immediate Nextcloud connection
- Fail only when tools are actually used
- Clear error messages to user

**Implementation**:
```typescript
export function getWebDAVClient(): WebDAVClient {
  if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
    throw new Error('Missing required environment variables');
  }
  // ... create client
}
```

## Error Handling Strategy

### Three Levels of Error Handling

#### 1. Tool Handler Level
```typescript
handler: async (args) => {
  try {
    // operation
  } catch (error) {
    return {
      content: [{ type: 'text', text: `Error: ${error.message}` }],
      isError: true
    };
  }
}
```

#### 2. Server Level
```typescript
try {
  return await tool.handler(args);
} catch (error) {
  return {
    content: [{ type: 'text', text: `Error: ${error.message}` }],
    isError: true
  };
}
```

#### 3. Main Function Level
```typescript
main().catch((err) => {
  logger.fatal({ err }, 'Fatal error');
  process.exit(1);
});
```

### Error Types

1. **Configuration Errors** - Missing env vars
2. **Authentication Errors** - Invalid credentials
3. **Network Errors** - Connection failures
4. **Not Found Errors** - Resource doesn't exist
5. **Validation Errors** - Invalid parameters (caught by Zod)

## Testing Strategy

### Unit Tests

Test tools in isolation:
- Mock WebDAV/CalDAV clients
- Test parameter validation
- Test error handling

### Integration Tests

Test with real Nextcloud instance:
- Requires test environment
- Test full data flow
- Validate API interactions

### Test File Organization

```
src/__tests__/
├── system/
│   └── files.test.ts
└── apps/
    ├── tasks.test.ts
    ├── cookbook.test.ts
    └── notes.test.ts
```

## Build & Deploy

### Development

```bash
npm run dev    # Hot reload with tsx
```

### Production Build

```bash
npm run build  # Compile TS to JS in dist/
```

### Deployment

- Copy `dist/` to production
- Set environment variables
- Configure Claude Desktop config
- Restart Claude Desktop

## Performance Considerations

### Connection Reuse
- WebDAV singleton prevents multiple connections
- HTTP keep-alive in fetch operations

### Async Operations
- All I/O is async
- Non-blocking server
- Concurrent request handling

### Memory Management
- Clients released when not in use
- No long-term caching (yet)
- Minimal state retention

## Security

### Credential Handling
- Environment variables only
- No hardcoded credentials
- No logging of sensitive data

### Input Validation
- Zod schemas validate all inputs
- Type checking prevents injection
- Path validation prevents traversal

### Transport Security
- stdio transport (local only)
- HTTPS for Nextcloud connections
- Basic Auth over TLS

## Monitoring & Debugging

### Logging

Structured JSON logging via [pino](https://getpino.io/), writing to stderr (safe for stdio transport).

```typescript
// src/logger.ts
import pino from 'pino';
export const logger = pino({ level: process.env.LOG_LEVEL ?? 'info' }, process.stderr);
```

Control verbosity with the `LOG_LEVEL` environment variable:

| Level | Use case |
|-------|----------|
| `debug` / `trace` | Development, deep tracing |
| `info` | Default — startup, login, token events |
| `warn` | Auth failures, misconfiguration |
| `error` / `fatal` | Unexpected exceptions, fatal config errors |

Example output:
```json
{"level":30,"time":1700000000000,"msg":"[auth] Login successful","user":"alice","client":"claude-desktop"}
```

### Debugging

**Methods**:
1. `LOG_LEVEL=debug npm run dev` — verbose structured output
2. Claude Desktop logs: `~/.local/share/Claude/logs/`
3. Use debugger with tsx
4. Test tools in isolation

## Future Improvements

### Planned Enhancements

1. **Caching Layer** - Cache frequently accessed data
2. **Rate Limiting** - Protect Nextcloud from overload
3. **Retry Logic** - Handle transient failures
4. **Batch Operations** - Multiple operations in one call
5. **Metrics** - Track usage and performance
6. **Direct OCC Execution** - Execute OCC commands via API

### Extension Points

1. **New Clients** - Add support for new protocols
2. **Middleware** - Add logging, auth, caching layers
3. **Custom Tools** - User-defined tools
4. **Alternative Transports** - HTTP, WebSocket

## Development Workflow

### Adding a New Tool

1. Identify appropriate module
2. Add tool definition
3. Export in module array
4. Build and test
5. Document

### Adding a New App

1. Create new module file
2. Import in index.ts
3. Register in registerTools()
4. Create documentation
5. Build and test

### Code Review Checklist

- [ ] Tool follows standard pattern
- [ ] Parameters have descriptions
- [ ] Error handling is present
- [ ] Types are correct
- [ ] Documentation is complete
- [ ] Tests are written
- [ ] Build succeeds
- [ ] Manual testing passes

## References

- [MCP SDK Documentation](https://github.com/anthropics/mcp-sdk)
- [WebDAV RFC 4918](https://tools.ietf.org/html/rfc4918)
- [CalDAV RFC 4791](https://tools.ietf.org/html/rfc4791)
- [TypeScript Handbook](https://www.typescriptlang.org/docs/handbook/)
- [Zod Documentation](https://zod.dev/)
