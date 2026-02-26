# Using AIquila with the Anthropic MCP-Connector

Anthropic's `mcp-client-2025-11-20` beta lets you call the **Messages API** and have
Claude connect directly to a remote MCP server — no separate MCP client process needed.
AIquila is fully compatible out of the box: its HTTP transport and built-in OAuth 2.0 provider
produce exactly the bearer token that `authorization_token` expects.

## Prerequisites

- AIquila MCP server running in HTTP transport mode with OAuth enabled (`MCP_AUTH_ENABLED=true`)
- A public HTTPS URL (or `http://localhost:3339` for local testing)
- An Anthropic API key
- An OAuth access token (see [OAuth PKCE flow](#obtaining-an-oauth-access-token))

## Quick Start

```typescript
import Anthropic from "@anthropic-ai/sdk";

const anthropic = new Anthropic(); // reads ANTHROPIC_API_KEY from env

const response = await (anthropic.beta.messages as any).create({
  model: "claude-opus-4-6",
  max_tokens: 2000,
  messages: [
    {
      role: "user",
      content: "Check Nextcloud system status and list my root folder.",
    },
  ],
  mcp_servers: [
    {
      type: "url",
      url: "https://mcp.example.com/mcp",
      name: "aiquila",
      authorization_token: "<your-access-token>",
    },
  ],
  tools: [{ type: "mcp_toolset", mcp_server_name: "aiquila" }],
  betas: ["mcp-client-2025-11-20"],
});
```

Claude will autonomously call the tools it needs and return its answer.

## Obtaining an OAuth Access Token

Run the OAuth PKCE flow against the AIquila server. The script
`mcp-server/scripts/test-mcp-connector.ts` does this automatically, or you can
follow the manual shell flow in `docker/standalone/scripts/test-oauth.sh`.

Key endpoints discovered from `GET /.well-known/oauth-authorization-server`:

| Step | Endpoint | Notes |
|------|----------|-------|
| Register | `registration_endpoint` | Dynamic client registration (PKCE, no secret) |
| Login | `POST /auth/login` | Returns 302 with `code` in Location |
| Token | `token_endpoint` | `grant_type=authorization_code` + `code_verifier` |

The returned `access_token` is a short-lived JWT (1 hour). Pass it directly as
`authorization_token` in the `mcp_servers` array.

## Token Efficiency: `defer_loading`

AIquila exposes many tools. Sending all tool descriptions in every request wastes tokens.
Use `defer_loading: true` so that only tool summaries are transmitted initially; full
definitions are fetched on demand when Claude decides to call a tool.

```typescript
tools: [
  {
    type: "mcp_toolset",
    mcp_server_name: "aiquila",
    // Defer all tools by default — only summaries sent initially
    default_config: { defer_loading: true },
    // Opt specific critical tools out of deferral (always send full schema)
    configs: {
      system_status: { defer_loading: false },
      list_files:    { defer_loading: false },
    },
  },
],
betas: ["mcp-client-2025-11-20"],
```

This is especially valuable when you know which tools Claude will need — for example,
a read-only file browser always needs `list_files` and `read_file`, so load those eagerly
and defer the rest.

## Tool Allowlist and Denylist Patterns

The `configs` map supports per-tool opt-in / opt-out. Use this to scope Claude's access
to exactly the tools required for your use case.

### Minimal — read-only file access

Only expose browsing and search tools. Claude cannot write, delete, or run OCC commands.

```typescript
tools: [
  {
    type: "mcp_toolset",
    mcp_server_name: "aiquila",
    // Start with everything deferred (effectively an allowlist)
    default_config: { defer_loading: true },
    configs: {
      // Eagerly load only read-only tools
      system_status:  { defer_loading: false },
      list_files:     { defer_loading: false },
      read_file:      { defer_loading: false },
      get_file_info:  { defer_loading: false },
      search_files:   { defer_loading: false },
    },
  },
],
```

### Calendar and tasks focus

Scope Claude to calendar, tasks, and basic file reads only.

```typescript
tools: [
  {
    type: "mcp_toolset",
    mcp_server_name: "aiquila",
    default_config: { defer_loading: true },
    configs: {
      // Calendar
      list_calendars: { defer_loading: false },
      list_events:    { defer_loading: false },
      get_event:      { defer_loading: false },
      create_event:   { defer_loading: false },
      update_event:   { defer_loading: false },
      delete_event:   { defer_loading: false },
      // Tasks
      list_task_lists: { defer_loading: false },
      list_tasks:      { defer_loading: false },
      create_task:     { defer_loading: false },
      complete_task:   { defer_loading: false },
      update_task:     { defer_loading: false },
      delete_task:     { defer_loading: false },
    },
  },
],
```

### Full admin — all tools

No `configs` needed. All tools are available with full schemas.

```typescript
tools: [{ type: "mcp_toolset", mcp_server_name: "aiquila" }],
```

### Denylist — all tools except sensitive operations

Load everything eagerly but explicitly defer destructive or privileged tools so
Claude won't call them without first fetching and confirming their schemas.

```typescript
tools: [
  {
    type: "mcp_toolset",
    mcp_server_name: "aiquila",
    // Load all tools by default
    default_config: { defer_loading: false },
    configs: {
      // Defer (suppress from initial context) sensitive / destructive tools
      delete:          { defer_loading: true },
      run_occ:         { defer_loading: true },
      enable_user:     { defer_loading: true },
      disable_user:    { defer_loading: true },
      add_user_to_group:      { defer_loading: true },
      remove_user_from_group: { defer_loading: true },
    },
  },
],
```

## Running the Built-in Test Script

`mcp-server/scripts/test-mcp-connector.ts` runs the full flow end-to-end:
OAuth PKCE → access token → Messages API → Claude uses `system_status` + `list_files` → PASS/FAIL.

```bash
# From the standalone docker directory
make test-mcp-connector

# Or directly
cd mcp-server/scripts
npm install
MCP_URL=http://localhost:3339 tsx test-mcp-connector.ts

# With defer_loading demo
DEFER_TOOLS=true tsx test-mcp-connector.ts
```

Required env vars: `ANTHROPIC_API_KEY`, `NEXTCLOUD_USER`, `NEXTCLOUD_PASSWORD`.
Optional: `MCP_URL` (default `http://localhost:3339`), `DEFER_TOOLS` (default `false`).

> **OAuth required.** The test script requires `MCP_AUTH_ENABLED=true` on the server.
> For local testing, see [Standalone Docker Setup](standalone-docker.md) and
> [OAuth 2.0 Setup Guide](oauth.md).

## Architecture

```
Your App
  │  anthropic.beta.messages.create({ mcp_servers: [...], betas: ["mcp-client-2025-11-20"] })
  ▼
Anthropic API
  │  MCP-Connector (server-side) fetches tools and calls them on your behalf
  ▼
AIquila MCP Server  (:3339/mcp)
  │  Bearer token validated via built-in JWT middleware
  ▼
Nextcloud  (WebDAV / OCS / CalDAV)
```

No MCP client library or SDK needed in your application — the Anthropic API handles
the MCP protocol entirely. Your app only needs the Anthropic SDK and the OAuth token.
