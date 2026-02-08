# Adding New Apps

Guide for adding support for new Nextcloud apps to the AIquila MCP Server.

## Overview

This guide walks you through creating a new app module for integrating additional Nextcloud apps (Mail, Calendar, Deck, etc.) into the MCP server.

## When to Create a New App Module

Create a new app module when:
- ✅ Integrating a new Nextcloud app
- ✅ Adding 2+ related tools for a specific functionality
- ✅ The tools have shared logic or dependencies

Add to existing module when:
- ❌ Adding a single tool to an existing app
- ❌ The functionality fits in system tools
- See [Adding Tools Guide](adding-tools.md)

## Step-by-Step Guide

### 1. Plan Your App Integration

**Questions to answer:**
- Which Nextcloud app are you integrating?
- What operations do users need?
- What protocol/API does it use? (WebDAV, CalDAV, API)
- What tools will you create?

**Example: Nextcloud Mail**
- App: Nextcloud Mail
- Operations: Send email, list folders, search emails
- Protocol: Custom API
- Tools: `send_email`, `list_mail_folders`, `search_emails`

### 2. Create the App Module File

Create a new file: `mcp-server/src/tools/apps/your-app.ts`

```typescript
import { z } from 'zod';
import { getWebDAVClient } from '../../client/webdav.js';
// Import other clients as needed

/**
 * Nextcloud Your-App Tools
 * Provides [app functionality] integration
 */

// Define your tools here
export const tool1 = {
  name: 'app_tool1',
  description: 'Description of tool 1',
  inputSchema: z.object({
    param: z.string().describe('Parameter description'),
  }),
  handler: async (args: { param: string }) => {
    // Tool implementation
    return {
      content: [{
        type: 'text',
        text: 'Result',
      }],
    };
  },
};

export const tool2 = {
  // Second tool definition
};

/**
 * Export all [Your App] tools
 */
export const yourAppTools = [tool1, tool2];
```

### 3. Register the App in Main Server

**Edit** `mcp-server/src/index.ts`:

```typescript
// Add import at top
import { yourAppTools } from './tools/apps/your-app.js';

// Add registration in registerTools()
function registerTools() {
  // ... existing registrations

  // Register Your App tools
  yourAppTools.forEach((tool) => {
    // @ts-expect-error - TS2589: Type instantiation depth limit in MCP SDK
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });
}
```

### 4. Create Documentation

Create documentation file: `docs/mcp/tools/apps/your-app.md`

Use this template:

```markdown
# Nextcloud Your App Tools

Integration with Nextcloud Your App. Brief description of what it does.

## Prerequisites

- Nextcloud Your App must be installed and enabled
- Any specific configuration needed

## Available Tools

### tool_name_1

Description of the tool.

**Parameters:**
- `param1` (type, required/optional): Description

**Returns:**
Description of what it returns.

**Example Usage:**
\`\`\`
Ask Claude: "Example usage"
\`\`\`

### tool_name_2

...

## Limitations

List current capabilities and what's not yet supported.

## Troubleshooting

Common issues and solutions.

## References

- Link to Nextcloud app
- Link to relevant API docs
```

### 5. Update Main Documentation

**Edit** `docs/mcp/README.md`:

Add your app to the tools reference table:

```markdown
#### Nextcloud Your App
| Tool | Description | Documentation |
|------|-------------|---------------|
| `your_tool` | Description | [Your App](tools/apps/your-app.md#your_tool) |
```

### 6. Build and Test

```bash
cd mcp-server
npm run build
npm test
```

## Complete Example: Adding Deck Support

Let's walk through adding Nextcloud Deck (kanban board) support.

### Step 1: Create Module File

**File:** `mcp-server/src/tools/apps/deck.ts`

```typescript
import { z } from 'zod';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Deck Tools
 * Provides kanban board management via Deck API
 */

/**
 * List all boards accessible to the user
 */
export const listBoardsTool = {
  name: 'list_deck_boards',
  description: 'List all Deck boards accessible to the user',
  inputSchema: z.object({}),
  handler: async () => {
    const config = getNextcloudConfig();
    const apiUrl = `${config.url}/index.php/apps/deck/api/v1.0/boards`;

    const response = await fetch(apiUrl, {
      headers: {
        'Authorization': `Basic ${Buffer.from(`${config.user}:${config.password}`).toString('base64')}`,
        'OCS-APIRequest': 'true',
      },
    });

    const boards = await response.json();
    return {
      content: [{
        type: 'text',
        text: JSON.stringify(boards, null, 2),
      }],
    };
  },
};

/**
 * Create a card in a Deck board stack
 */
export const createCardTool = {
  name: 'create_deck_card',
  description: 'Create a new card in a Deck board stack',
  inputSchema: z.object({
    boardId: z.number().describe('The board ID'),
    stackId: z.number().describe('The stack ID'),
    title: z.string().describe('Card title'),
    description: z.string().optional().describe('Card description'),
  }),
  handler: async (args: {
    boardId: number;
    stackId: number;
    title: string;
    description?: string;
  }) => {
    const config = getNextcloudConfig();
    const apiUrl = `${config.url}/index.php/apps/deck/api/v1.0/boards/${args.boardId}/stacks/${args.stackId}/cards`;

    const response = await fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Authorization': `Basic ${Buffer.from(`${config.user}:${config.password}`).toString('base64')}`,
        'Content-Type': 'application/json',
        'OCS-APIRequest': 'true',
      },
      body: JSON.stringify({
        title: args.title,
        description: args.description || '',
        type: 'plain',
      }),
    });

    if (response.ok) {
      const card = await response.json();
      return {
        content: [{
          type: 'text',
          text: `Card created: ${args.title} (ID: ${card.id})`,
        }],
      };
    } else {
      throw new Error(`Failed to create card: ${response.status}`);
    }
  },
};

/**
 * Export all Deck tools
 */
export const deckTools = [listBoardsTool, createCardTool];
```

### Step 2: Register in index.ts

```typescript
import { deckTools } from './tools/apps/deck.js';

function registerTools() {
  // ... existing registrations

  // Register Deck tools
  deckTools.forEach((tool) => {
    // @ts-expect-error - TS2589
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: tool.inputSchema,
    }, tool.handler);
  });
}
```

### Step 3: Create Documentation

**File:** `docs/mcp/tools/apps/deck.md`

```markdown
# Nextcloud Deck Tools

Integration with Nextcloud Deck kanban board application.

## Prerequisites

- Nextcloud Deck app must be installed and enabled
- At least one board must exist

## Available Tools

### list_deck_boards

List all Deck boards accessible to the user.

**Parameters:**
None

**Returns:**
JSON array of boards with their IDs, titles, and metadata.

**Example Usage:**
\`\`\`
Ask Claude: "Show me my Deck boards"
Ask Claude: "List all my kanban boards"
\`\`\`

### create_deck_card

Create a new card in a Deck board stack.

**Parameters:**
- `boardId` (number, required): The board ID
- `stackId` (number, required): The stack ID
- `title` (string, required): Card title
- `description` (string, optional): Card description

**Returns:**
Success message with card title and ID.

**Example Usage:**
\`\`\`
Ask Claude: "Create a card 'New feature' in board 1 stack 2"
Ask Claude: "Add a card to my TODO list in Deck"
\`\`\`

## API Details

Deck uses a REST API at `/index.php/apps/deck/api/v1.0/`.

## References

- [Deck App](https://apps.nextcloud.com/apps/deck)
- [Deck API Documentation](https://deck.readthedocs.io/)
```

### Step 4: Update Main Documentation

Add to `docs/mcp/README.md`:

```markdown
#### Nextcloud Deck
| Tool | Description | Documentation |
|------|-------------|---------------|
| `list_deck_boards` | List kanban boards | [Deck](tools/apps/deck.md#list_deck_boards) |
| `create_deck_card` | Create a card | [Deck](tools/apps/deck.md#create_deck_card) |
```

## Integration Patterns

### Pattern 1: WebDAV-Based Apps

For apps that store data as files (Cookbook, Notes):

```typescript
import { getWebDAVClient } from '../../client/webdav.js';

const client = getWebDAVClient();
await client.putFileContents('/AppFolder/file.ext', content);
```

### Pattern 2: CalDAV-Based Apps

For apps using CalDAV (Calendar, Tasks):

```typescript
import { fetchCalDAV } from '../../client/caldav.js';

const response = await fetchCalDAV(url, {
  method: 'PROPFIND',
  body: xmlBody,
});
```

### Pattern 3: REST API-Based Apps

For apps with custom REST APIs (Deck, Mail):

```typescript
import { getNextcloudConfig } from '../types.js';

const config = getNextcloudConfig();
const apiUrl = `${config.url}/index.php/apps/yourapp/api/endpoint`;

const response = await fetch(apiUrl, {
  headers: {
    'Authorization': `Basic ${Buffer.from(`${config.user}:${config.password}`).toString('base64')}`,
    'Content-Type': 'application/json',
  },
});
```

## Adding Client Helpers

If your app needs specialized client logic, create a helper in `src/client/`:

**Example:** `src/client/deck-api.ts`

```typescript
import { getNextcloudConfig } from '../tools/types.js';

export async function fetchDeckAPI(
  endpoint: string,
  options: RequestInit = {}
): Promise<Response> {
  const config = getNextcloudConfig();
  const url = `${config.url}/index.php/apps/deck/api/v1.0${endpoint}`;

  const auth = Buffer.from(`${config.user}:${config.password}`).toString('base64');

  return fetch(url, {
    ...options,
    headers: {
      'Authorization': `Basic ${auth}`,
      'OCS-APIRequest': 'true',
      'Content-Type': 'application/json',
      ...options.headers,
    },
  });
}
```

Then use in your tools:

```typescript
import { fetchDeckAPI } from '../../client/deck-api.js';

const response = await fetchDeckAPI('/boards');
```

## Best Practices

### 1. Research the App First

- Check if the app has an API
- Review API documentation
- Test API endpoints manually (curl, Postman)
- Understand authentication requirements

### 2. Start Small

Begin with 2-3 essential tools:
- List/read operations
- Create operations
- Can add update/delete later

### 3. Use Descriptive Names

```typescript
// Good - Clear and specific
'create_deck_card'
'list_mail_folders'
'search_calendar_events'

// Bad - Ambiguous
'create'
'list'
'search'
```

### 4. Handle Errors Gracefully

```typescript
try {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`API error: ${response.status} ${response.statusText}`);
  }
  return await response.json();
} catch (error) {
  return {
    content: [{
      type: 'text',
      text: `Error: ${error.message}`,
    }],
    isError: true,
  };
}
```

### 5. Document Thoroughly

- Prerequisites for using the app
- Parameter descriptions
- Example usage
- Limitations
- Troubleshooting tips

## Testing Your App Integration

### 1. Unit Tests

```typescript
import { describe, it, expect } from 'vitest';
import { yourAppTools } from '../../tools/apps/your-app.js';

describe('Your App Tools', () => {
  it('should export correct number of tools', () => {
    expect(yourAppTools).toHaveLength(2);
  });

  it('should have valid tool structure', () => {
    yourAppTools.forEach(tool => {
      expect(tool.name).toBeDefined();
      expect(tool.description).toBeDefined();
      expect(tool.inputSchema).toBeDefined();
      expect(tool.handler).toBeInstanceOf(Function);
    });
  });
});
```

### 2. Integration Tests

Test with actual Nextcloud instance:

```bash
# Set test credentials
export NEXTCLOUD_URL="https://test.example.com"
export NEXTCLOUD_USER="testuser"
export NEXTCLOUD_PASSWORD="testpass"

# Run tests
npm test
```

### 3. Manual Testing

1. Build: `npm run build`
2. Restart Claude Desktop
3. Test tools through conversation

## Troubleshooting

### API Authentication Issues

```typescript
// Check response status
if (response.status === 401) {
  throw new Error('Authentication failed. Check credentials.');
}

// Log for debugging (remove in production)
console.error('Response:', await response.text());
```

### App Not Installed

Add prerequisite check:

```typescript
// Check if app is available
const appCheckUrl = `${config.url}/index.php/apps/yourapp/`;
const checkResponse = await fetch(appCheckUrl);
if (!checkResponse.ok) {
  throw new Error('Your App is not installed or enabled');
}
```

## Examples of Nextcloud Apps to Integrate

**Easy** (WebDAV-based):
- Bookmarks (JSON files)
- Forms (response exports)
- Polls (data exports)

**Medium** (CalDAV/CardDAV):
- Calendar (full event management)
- Contacts (address book management)

**Advanced** (Custom APIs):
- Mail (email management)
- Talk (chat, calls)
- Deck (kanban boards)
- Circles (team management)

## Next Steps

- Review [Adding Tools Guide](adding-tools.md)
- Study existing app modules
- Check Nextcloud app store for APIs
- Test your integration thoroughly

## Getting Help

- Review existing app implementations
- Check [Architecture Documentation](architecture.md)
- Ask in GitHub Discussions
- Open an issue for bugs
