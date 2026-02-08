# MCP Server Architecture

This document explains the architecture of the AIquila MCP Server, its design principles, and how the components work together.

## Overview

The AIquila MCP Server is built with a modular, scalable architecture that separates concerns between system-level operations, app-specific integrations, and shared infrastructure.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     Claude Desktop / MCP Client              │
└───────────────────────────────┬─────────────────────────────┘
                                │ stdio (JSON-RPC)
┌───────────────────────────────▼─────────────────────────────┐
│                      MCP Server (index.ts)                   │
│  ┌────────────────────────────────────────────────────────┐ │
│  │        Tool Registration & Request Handling            │ │
│  └────────────────────────────────────────────────────────┘ │
└──────────┬────────────────────────────────────┬─────────────┘
           │                                    │
    ┌──────▼────────┐                  ┌───────▼────────┐
    │ System Tools  │                  │   App Tools    │
    │  (files.ts)   │                  │   (apps/*.ts)  │
    └──────┬────────┘                  └───────┬────────┘
           │                                    │
           └──────────┬──────────────────┬──────┘
                      │                  │
            ┌─────────▼────────┐  ┌─────▼──────────┐
            │  WebDAV Client   │  │  CalDAV Client │
            │  (client/*.ts)   │  │  (client/*.ts) │
            └─────────┬────────┘  └─────┬──────────┘
                      │                 │
                      └────────┬────────┘
                               │
          ┌────────────────────▼───────────────────┐
          │        Nextcloud Server                │
          │  (WebDAV, CalDAV, Files, Tasks, etc.)  │
          └────────────────────────────────────────┘
```

## Directory Structure

```
mcp-server/
├── src/
│   ├── index.ts                 # Main server entry point
│   ├── client/                  # Client infrastructure
│   │   ├── webdav.ts           # WebDAV client singleton
│   │   └── caldav.ts           # CalDAV operations helper
│   └── tools/                   # Tool implementations
│       ├── types.ts             # Shared type definitions
│       ├── system/              # System-level tools
│       │   └── files.ts         # File operations (5 tools)
│       └── apps/                # App-specific tools
│           ├── tasks.ts         # Nextcloud Tasks (2 tools)
│           ├── cookbook.ts      # Nextcloud Cookbook (1 tool)
│           ├── notes.ts         # Nextcloud Notes (1 tool)
│           └── aiquila.ts       # AIquila internal (3 tools)
├── dist/                        # Compiled JavaScript output
├── package.json                 # Dependencies and scripts
└── tsconfig.json                # TypeScript configuration
```

## Core Components

### 1. Main Server (index.ts)

**Responsibilities:**
- Initialize MCP server instance
- Import all tool modules
- Register tools with the server
- Handle tool execution requests
- Manage error handling

**Key Functions:**
- `registerTools()` - Registers all tools from modules
- `main()` - Starts the server with stdio transport

**Code Structure:**
```typescript
// Import tool modules
import { fileSystemTools } from './tools/system/files.js';
import { tasksTools } from './tools/apps/tasks.js';
// ... more imports

// Create server
const server = new McpServer({ name: 'aiquila', version: '0.1.1' });

// Register all tools
function registerTools() {
  fileSystemTools.forEach(tool => {
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });
  // ... register other tool categories
}

// Start server
async function main() {
  registerTools();
  const transport = new StdioServerTransport();
  await server.connect(transport);
}
```

### 2. Client Infrastructure (client/)

#### WebDAV Client (webdav.ts)

**Purpose:** Manage WebDAV connections to Nextcloud for file operations.

**Pattern:** Singleton - one client instance reused across all operations.

**Key Functions:**
- `getWebDAVClient()` - Get or create WebDAV client
- `resetWebDAVClient()` - Reset client (for testing)

**Why Singleton?**
- Reuse HTTP connections
- Avoid authentication overhead
- Maintain session state

**Configuration:**
- Reads from environment variables
- Constructs full WebDAV URL: `{NEXTCLOUD_URL}/remote.php/dav/files/{user}`
- Uses Basic Authentication

#### CalDAV Client (caldav.ts)

**Purpose:** Handle CalDAV operations for Tasks integration.

**Key Functions:**
- `fetchCalDAV()` - Make authenticated CalDAV requests

**Responsibilities:**
- PROPFIND requests (list calendars)
- PUT requests (create tasks)
- Proper XML headers and authentication

### 3. Shared Types (tools/types.ts)

**Purpose:** Common type definitions and utilities used across tools.

**Contents:**
- `ToolResponse` interface
- Zod schemas for common parameters
- `NextcloudConfig` interface
- `getNextcloudConfig()` helper

**Why Separate File?**
- DRY principle - avoid duplication
- Single source of truth for types
- Easy to extend and modify

### 4. System Tools (tools/system/)

#### File Operations (files.ts)

**Category:** System-level tools

**Tools Provided:**
1. `list_files` - List directory contents
2. `read_file` - Read file contents
3. `write_file` - Create/update files
4. `create_folder` - Create directories
5. `delete` - Delete files/folders

**Structure:**
```typescript
export const toolName = {
  name: 'tool_name',
  description: 'Tool description',
  inputSchema: zodSchema,
  handler: async (args) => {
    // Tool implementation
    return { content: [{ type: 'text', text: result }] };
  },
};

export const fileSystemTools = [tool1, tool2, ...];
```

**Why This Pattern?**
- Each tool is self-contained
- Easy to test individually
- Clear separation of concerns
- Simple to add new tools

### 5. App Tools (tools/apps/)

Each app has its own module following the same pattern as system tools.

#### Tasks (tasks.ts)
- `list_task_lists` - CalDAV PROPFIND
- `create_task` - CalDAV PUT with VTODO

#### Cookbook (cookbook.ts)
- `add_recipe` - Create markdown recipe files

#### Notes (notes.ts)
- `create_note` - Create markdown note files

#### AIquila (aiquila.ts)
- `aiquila_show_config` - OCC command instructions
- `aiquila_configure` - OCC configure instructions
- `aiquila_test` - OCC test instructions

## Design Principles

### 1. Modularity

**Problem:** Original code had all tools in one 374-line file.

**Solution:** Separate modules by concern:
- Infrastructure (client/)
- System tools (tools/system/)
- App tools (tools/apps/)

**Benefits:**
- Easier to maintain
- Simpler to test
- Clearer responsibilities
- Scalable architecture

### 2. Single Responsibility

Each module has one clear responsibility:
- `webdav.ts` - Only WebDAV client management
- `files.ts` - Only file operations
- `tasks.ts` - Only Tasks app integration

### 3. DRY (Don't Repeat Yourself)

**Shared Infrastructure:**
- WebDAV client used by files, cookbook, notes
- CalDAV client used by tasks
- Shared types used by all tools

**Avoid:**
- Duplicating WebDAV client creation
- Repeated Zod schemas
- Duplicate type definitions

### 4. Separation of Concerns

**Three Layers:**
1. **Transport Layer** - MCP protocol (index.ts)
2. **Business Logic** - Tool implementations (tools/)
3. **Data Layer** - Client connections (client/)

### 5. Extensibility

**Adding New Tools:**
1. Create new tool in appropriate module
2. Add to exported array
3. Server automatically registers it

**Adding New Apps:**
1. Create new file in `tools/apps/`
2. Import in `index.ts`
3. Register in `registerTools()`

### 6. Type Safety

**TypeScript Throughout:**
- Strict mode enabled
- Type definitions for all parameters
- Zod schemas for runtime validation
- Compile-time type checking

## Tool Design Pattern

All tools follow this consistent structure:

```typescript
import { z } from 'zod';
import { getWebDAVClient } from '../../client/webdav.js';

export const myTool = {
  // Tool identifier (unique)
  name: 'tool_name',

  // Human-readable description
  description: 'What this tool does',

  // Parameter schema (Zod)
  inputSchema: z.object({
    param1: z.string().describe('Parameter description'),
    param2: z.number().optional().describe('Optional parameter'),
  }),

  // Handler function
  handler: async (args: { param1: string; param2?: number }) => {
    // 1. Get client/dependencies
    const client = getWebDAVClient();

    // 2. Perform operation
    const result = await client.someOperation(args.param1);

    // 3. Return formatted response
    return {
      content: [
        {
          type: 'text',
          text: result,
        },
      ],
    };
  },
};

// Export array of tools for this module
export const myTools = [myTool, anotherTool];
```

## Error Handling

### Strategy

**Per-Tool Error Handling:**
```typescript
handler: async (args) => {
  try {
    const result = await operation(args);
    return { content: [{ type: 'text', text: result }] };
  } catch (error) {
    return {
      content: [{
        type: 'text',
        text: `Error: ${error.message}`
      }],
      isError: true
    };
  }
}
```

**Main Server Error Handling:**
```typescript
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const tool = allTools.find(t => t.name === request.params.name);

  if (!tool) {
    throw new Error(`Unknown tool: ${request.params.name}`);
  }

  try {
    return await tool.handler(request.params.arguments ?? {});
  } catch (error) {
    return {
      content: [{
        type: 'text',
        text: `Error: ${error.message}`
      }],
      isError: true
    };
  }
});
```

### Error Types

1. **Authentication Errors** - Invalid credentials
2. **Not Found Errors** - Resource doesn't exist
3. **Permission Errors** - Insufficient permissions
4. **Network Errors** - Connection failures
5. **Validation Errors** - Invalid parameters (caught by Zod)

## Communication Flow

### 1. Claude Desktop → MCP Server

```
1. User asks Claude: "List my files"
2. Claude Desktop determines which tool to use
3. Sends JSON-RPC request over stdio:
   {
     "method": "tools/call",
     "params": {
       "name": "list_files",
       "arguments": { "path": "/" }
     }
   }
```

### 2. MCP Server Processing

```
1. Server receives request
2. Finds matching tool by name
3. Validates arguments with Zod schema
4. Calls tool handler
5. Returns response
```

### 3. Tool Execution

```
1. Handler gets WebDAV client
2. Makes API call to Nextcloud
3. Formats response
4. Returns to server
```

### 4. Response Flow

```
1. Server packages response
2. Sends JSON-RPC response over stdio
3. Claude Desktop receives result
4. Claude presents to user in natural language
```

## Configuration Management

### Environment Variables

Required variables (validated at runtime):
- `NEXTCLOUD_URL`
- `NEXTCLOUD_USER`
- `NEXTCLOUD_PASSWORD`

### Validation

**At Client Creation:**
```typescript
export function getWebDAVClient(): WebDAVClient {
  if (!NEXTCLOUD_URL || !NEXTCLOUD_USER || !NEXTCLOUD_PASSWORD) {
    throw new Error('Missing required environment variables');
  }
  // ... create client
}
```

**Early Failure:**
- Fail fast if configuration is invalid
- Clear error messages
- User-friendly guidance

## Performance Considerations

### 1. Connection Reuse

**WebDAV Singleton:**
- Single client instance
- HTTP connection pooling
- Reduced authentication overhead

### 2. Lazy Initialization

**Client Creation:**
- Clients created on first use
- Not initialized if tools aren't called
- Minimal startup overhead

### 3. Async Operations

**Non-Blocking:**
- All I/O operations are async
- Doesn't block server
- Multiple requests can be handled concurrently

## Security Considerations

### 1. Credential Management

- Environment variables (not hardcoded)
- No credentials logged
- Basic Auth over HTTPS only

### 2. Input Validation

- Zod schemas validate all inputs
- Type checking at compile and runtime
- Prevent injection attacks

### 3. Path Safety

- Paths validated before use
- No directory traversal attacks
- User-scoped access via credentials

## Testing Strategy

### Unit Tests

Test individual tools in isolation:
```typescript
describe('listFilesTool', () => {
  it('should list files in directory', async () => {
    const result = await listFilesTool.handler({ path: '/' });
    expect(result.content[0].text).toBeDefined();
  });
});
```

### Integration Tests

Test with actual Nextcloud instance:
- Requires test credentials
- Test environment setup
- Full end-to-end validation

### Mock Tests

Mock WebDAV/CalDAV clients:
- Fast execution
- No external dependencies
- Test error conditions

## Future Enhancements

### Planned Improvements

1. **Direct OCC Execution** - Execute commands via API
2. **Batch Operations** - Multiple operations in one call
3. **Caching** - Cache frequently accessed data
4. **Rate Limiting** - Protect Nextcloud from overload
5. **Retry Logic** - Automatic retry on transient failures
6. **Metrics** - Track tool usage and performance
7. **Logging** - Structured logging for debugging

### Extensibility Points

1. **New Apps** - Easy to add new Nextcloud app integrations
2. **Custom Tools** - Users can add their own tools
3. **Middleware** - Add logging, auth, caching layers
4. **Alternative Transports** - HTTP, WebSocket, etc.

## Best Practices

### Adding New Tools

1. ✅ Follow the tool design pattern
2. ✅ Add comprehensive parameter descriptions
3. ✅ Use Zod for validation
4. ✅ Handle errors gracefully
5. ✅ Write unit tests
6. ✅ Document in `/docs/mcp/tools/`
7. ✅ Export in module's tool array

### Code Organization

1. ✅ One module per app
2. ✅ Group related tools
3. ✅ Shared code in `types.ts` or `client/`
4. ✅ Clear, descriptive names
5. ✅ Consistent formatting

### Documentation

1. ✅ Document all tools
2. ✅ Include usage examples
3. ✅ Explain parameters
4. ✅ Note limitations
5. ✅ Provide troubleshooting

## References

- [MCP SDK Documentation](https://github.com/anthropics/mcp-sdk)
- [WebDAV Specification](https://tools.ietf.org/html/rfc4918)
- [CalDAV Specification](https://tools.ietf.org/html/rfc4791)
- [TypeScript Best Practices](https://www.typescriptlang.org/docs/handbook/declaration-files/do-s-and-don-ts.html)
