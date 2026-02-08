# Adding New Tools

Guide for adding new tools to existing app modules in the AIquila MCP Server.

## When to Add a Tool vs. Create an App

**Add a Tool** when:
- Extending functionality of an existing app
- The tool logically belongs with other tools in a module
- Example: Adding `update_task` to the Tasks module

**Create a New App** when:
- Integrating a completely new Nextcloud app
- The functionality is standalone
- Example: Adding Nextcloud Mail integration
- See [Adding Apps Guide](adding-apps.md)

## Step-by-Step Guide

### 1. Choose the Right Module

Identify which module your tool belongs to:
- **System tools**: `src/tools/system/files.ts` - File operations
- **Tasks**: `src/tools/apps/tasks.ts` - Task management
- **Cookbook**: `src/tools/apps/cookbook.ts` - Recipe management
- **Notes**: `src/tools/apps/notes.ts` - Note management
- **AIquila**: `src/tools/apps/aiquila.ts` - Internal configuration

### 2. Define the Tool

Add your tool definition to the appropriate file:

```typescript
import { z } from 'zod';
import { getWebDAVClient } from '../../client/webdav.js';

export const myNewTool = {
  name: 'my_new_tool',
  description: 'Clear description of what this tool does',
  inputSchema: z.object({
    requiredParam: z.string().describe('Description of required parameter'),
    optionalParam: z.number().optional().describe('Description of optional parameter'),
  }),
  handler: async (args: { requiredParam: string; optionalParam?: number }) => {
    // Get necessary clients
    const client = getWebDAVClient();

    // Implement your logic
    const result = await client.someOperation(args.requiredParam);

    // Return formatted response
    return {
      content: [
        {
          type: 'text',
          text: `Operation successful: ${result}`,
        },
      ],
    };
  },
};
```

### 3. Add to Export Array

Add your tool to the module's export array at the bottom of the file:

```typescript
export const myAppTools = [
  existingTool1,
  existingTool2,
  myNewTool,  // ← Add here
];
```

### 4. Build and Test

```bash
cd mcp-server
npm run build
npm test
```

### 5. Document the Tool

Create or update documentation in `/docs/mcp/tools/`:

```markdown
### my_new_tool

Brief description of what the tool does.

**Parameters:**
- `requiredParam` (string, required): Description
- `optionalParam` (number, optional): Description

**Returns:**
Success message with result details.

**Example Usage:**
\`\`\`
Ask Claude: "Use my new tool with parameter X"
\`\`\`
```

## Examples

### Example 1: Adding a "Delete Task" Tool

**File**: `src/tools/apps/tasks.ts`

```typescript
export const deleteTaskTool = {
  name: 'delete_task',
  description: 'Delete a task from Nextcloud Tasks',
  inputSchema: z.object({
    calendarName: z.string().describe('The calendar name'),
    taskUid: z.string().describe('The task UID to delete'),
  }),
  handler: async (args: { calendarName: string; taskUid: string }) => {
    const config = getNextcloudConfig();
    const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${args.calendarName}/${args.taskUid}.ics`;

    const response = await fetchCalDAV(calDavUrl, {
      method: 'DELETE',
    });

    if (response.ok) {
      return {
        content: [
          {
            type: 'text',
            text: `Task deleted successfully: ${args.taskUid}`,
          },
        ],
      };
    } else {
      throw new Error(`Failed to delete task: ${response.status}`);
    }
  },
};

// Add to exports
export const tasksTools = [
  listTaskListsTool,
  createTaskTool,
  deleteTaskTool,  // ← New tool
];
```

### Example 2: Adding a "Move File" Tool

**File**: `src/tools/system/files.ts`

```typescript
export const moveFileTool = {
  name: 'move_file',
  description: 'Move a file from one location to another in Nextcloud',
  inputSchema: z.object({
    sourcePath: z.string().describe('Source file path'),
    destinationPath: z.string().describe('Destination file path'),
  }),
  handler: async (args: { sourcePath: string; destinationPath: string }) => {
    const client = getWebDAVClient();
    await client.moveFile(args.sourcePath, args.destinationPath);

    return {
      content: [
        {
          type: 'text',
          text: `File moved from ${args.sourcePath} to ${args.destinationPath}`,
        },
      ],
    };
  },
};

// Add to exports
export const fileSystemTools = [
  listFilesTool,
  readFileTool,
  writeFileTool,
  createFolderTool,
  deleteTool,
  moveFileTool,  // ← New tool
];
```

## Tool Design Best Practices

### 1. Naming

**Tool Names:**
- Use snake_case: `create_task`, `list_files`
- Be descriptive: `delete_task` not `delete`
- Include scope if ambiguous: `nextcloud_delete_file`

**Variable Names:**
```typescript
// Good
export const createTaskTool = { name: 'create_task', ... };

// Bad
export const ct = { name: 'createTask', ... };
```

### 2. Parameter Design

**Required vs Optional:**
```typescript
inputSchema: z.object({
  // Required - no default, no optional()
  title: z.string().describe('Task title'),

  // Optional - use optional()
  description: z.string().optional().describe('Task description'),

  // Optional with default - use default()
  priority: z.number().default(5).describe('Priority (1-9)'),
}),
```

**Good Descriptions:**
```typescript
// Good - Clear and specific
path: z.string().describe('The file path in Nextcloud (e.g., /Documents/file.txt)')

// Bad - Vague
path: z.string().describe('A path')
```

### 3. Error Handling

**Always handle errors:**
```typescript
handler: async (args) => {
  try {
    const result = await operation(args);
    return { content: [{ type: 'text', text: result }] };
  } catch (error) {
    return {
      content: [{
        type: 'text',
        text: `Error: ${error instanceof Error ? error.message : String(error)}`
      }],
      isError: true
    };
  }
}
```

**Provide helpful error messages:**
```typescript
// Good
throw new Error('Failed to create task: Calendar "work" not found');

// Bad
throw new Error('Error');
```

### 4. Response Formatting

**Consistent structure:**
```typescript
return {
  content: [
    {
      type: 'text',
      text: 'Your formatted response here',
    },
  ],
};
```

**For multiple items, use structured text:**
```typescript
const items = ['item1', 'item2', 'item3'];
return {
  content: [{
    type: 'text',
    text: items.join('\n'),  // Or JSON.stringify(items, null, 2)
  }],
};
```

## Testing Your Tool

### Unit Test Template

Create a test file: `src/__tests__/apps/my-tool.test.ts`

```typescript
import { describe, it, expect } from 'vitest';
import { myNewTool } from '../../tools/apps/myapp.js';

describe('myNewTool', () => {
  it('should have correct name', () => {
    expect(myNewTool.name).toBe('my_new_tool');
  });

  it('should have valid schema', () => {
    const result = myNewTool.inputSchema.safeParse({
      requiredParam: 'test',
    });
    expect(result.success).toBe(true);
  });

  it('should reject invalid input', () => {
    const result = myNewTool.inputSchema.safeParse({});
    expect(result.success).toBe(false);
  });

  // Integration test (requires test environment)
  it.skip('should execute successfully', async () => {
    const result = await myNewTool.handler({
      requiredParam: 'test-value',
    });
    expect(result.content[0].text).toContain('successful');
  });
});
```

### Manual Testing

1. **Build the server:**
   ```bash
   npm run build
   ```

2. **Update Claude Desktop config** (if needed)

3. **Restart Claude Desktop**

4. **Test the tool:**
   ```
   Ask Claude: "Use my new tool with parameter X"
   ```

## Common Patterns

### Using WebDAV Client

```typescript
import { getWebDAVClient } from '../../client/webdav.js';

const client = getWebDAVClient();
await client.getDirectoryContents('/path');
await client.getFileContents('/path/file.txt');
await client.putFileContents('/path/file.txt', 'content');
```

### Using CalDAV Client

```typescript
import { fetchCalDAV } from '../../client/caldav.js';

const response = await fetchCalDAV(url, {
  method: 'PROPFIND',
  body: xmlBody,
  headers: { Depth: '1' },
});
```

### Using Shared Types

```typescript
import { getNextcloudConfig, PathSchema } from '../types.js';

const config = getNextcloudConfig();
inputSchema: PathSchema,  // Reuse common schemas
```

## Troubleshooting

### Tool Not Appearing

**Check:**
1. Tool is exported in module array
2. Module is imported in `index.ts`
3. Server built successfully (`npm run build`)
4. Claude Desktop restarted

### Type Errors

**Common issues:**
```typescript
// Issue: Handler args don't match schema
inputSchema: z.object({ name: z.string() }),
handler: async (args: { title: string }) => { ... }  // ❌ Mismatch

// Fix: Match parameter names
handler: async (args: { name: string }) => { ... }  // ✅ Correct
```

### Runtime Errors

**Debug steps:**
1. Check server logs: `tail -f ~/.local/share/Claude/logs/mcp-server-aiquila.log`
2. Add console.error() for debugging
3. Test tool in isolation
4. Verify Nextcloud connectivity

## Next Steps

- [Adding Apps Guide](adding-apps.md) - Create a new app module
- [Architecture Overview](architecture.md) - Understand the system
- [API Reference](../README.md) - Browse existing tools

## Getting Help

- Check existing tools for examples
- Review [Architecture Documentation](architecture.md)
- Open an issue on GitHub
- Ask in discussions
