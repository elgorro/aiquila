#!/usr/bin/env tsx
/**
 * test-mistral-mcp.ts
 *
 * Verifies that the AIquila MCP server works when the chat is driven by
 * Mistral (GitHub issue #136). Mistral has no native MCP connector, so this
 * drives the agentic tool loop manually — exactly how AIquila's Nextcloud app
 * does it for the Mistral provider:
 *
 *   1. OAuth PKCE flow against the AIquila MCP server → access_token
 *   2. MCP initialize + tools/list over Streamable HTTP (JSON-RPC)
 *   3. Convert MCP tools → Mistral function tools, call Mistral chat.complete()
 *   4. Execute requested tools via MCP tools/call, feed results back, loop
 *   5. Assert PASS when Mistral reports both tools returned valid data
 *
 * Required env vars:
 *   MISTRAL_API_KEY       Mistral API key
 *   NEXTCLOUD_USER        Nextcloud username
 *   NEXTCLOUD_PASSWORD    Nextcloud password (or app password)
 *
 * Optional env vars:
 *   MCP_URL               Base URL of the MCP server (default: http://localhost:3339)
 *   MISTRAL_MODEL         Model to drive the loop (default: mistral-large-latest)
 *
 * Usage:
 *   cd mcp-server/scripts
 *   npm install
 *   MCP_URL=http://localhost:3339 tsx test-mistral-mcp.ts
 *
 * Or via make (from docker/standalone/):
 *   make test-mistral
 */
import { Mistral } from '@mistralai/mistralai';
import * as crypto from 'node:crypto';

const BASE_URL = (process.env.MCP_URL ?? 'http://localhost:3339').replace(/\/$/, '');
const NC_USER = process.env.NEXTCLOUD_USER ?? '';
const NC_PASS = process.env.NEXTCLOUD_PASSWORD ?? '';
const MODEL = process.env.MISTRAL_MODEL ?? 'mistral-large-latest';
const MAX_ITERATIONS = 8;

const REDIRECT_URI = 'https://localhost/callback';

function log(msg: string) {
  process.stderr.write(msg + '\n');
}

function sep(label: string) {
  log('\n─────────────────────────────────────────────────');
  log(`  ${label}`);
  log('─────────────────────────────────────────────────');
}

// ── PKCE helpers ──────────────────────────────────────────────────────────────

function generateCodeVerifier(): string {
  return crypto.randomBytes(96).toString('base64url').slice(0, 128);
}

function generateCodeChallenge(verifier: string): string {
  return crypto.createHash('sha256').update(verifier).digest().toString('base64url');
}

// ── OAuth PKCE flow (mirrors test-mcp-connector.ts) ─────────────────────────────

async function getAccessToken(): Promise<string> {
  sep('OAuth — Discovery');
  const discoveryRes = await fetch(`${BASE_URL}/.well-known/oauth-authorization-server`);
  if (!discoveryRes.ok) {
    throw new Error(`Discovery failed: ${discoveryRes.status} ${discoveryRes.statusText}`);
  }
  const discovery = (await discoveryRes.json()) as Record<string, string>;
  const registrationUrl = BASE_URL + new URL(discovery.registration_endpoint).pathname;
  const tokenUrl = BASE_URL + new URL(discovery.token_endpoint).pathname;
  const loginUrl = `${BASE_URL}/auth/login`;
  log(`registration → ${registrationUrl}`);
  log(`token        → ${tokenUrl}`);

  sep('OAuth — Client Registration');
  const regRes = await fetch(registrationUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_name: 'mistral-mcp-test',
      redirect_uris: [REDIRECT_URI],
      grant_types: ['authorization_code'],
      response_types: ['code'],
      token_endpoint_auth_method: 'none',
    }),
  });
  if (!regRes.ok) {
    throw new Error(`Client registration failed: ${regRes.status} ${await regRes.text()}`);
  }
  const registration = (await regRes.json()) as Record<string, string>;
  const clientId = registration.client_id;
  log(`client_id : ${clientId}`);

  const codeVerifier = generateCodeVerifier();
  const codeChallenge = generateCodeChallenge(codeVerifier);
  const state = crypto.randomBytes(8).toString('hex');

  sep('OAuth — Login');
  const loginParams = new URLSearchParams({
    username: NC_USER,
    password: NC_PASS,
    client_id: clientId,
    redirect_uri: REDIRECT_URI,
    state,
    code_challenge: codeChallenge,
    code_challenge_method: 'S256',
    scope: '',
  });
  const loginRes = await fetch(loginUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: loginParams.toString(),
    redirect: 'manual',
  });
  const locationHeader = loginRes.headers.get('location');
  if (!locationHeader) {
    throw new Error(
      `Login failed — no redirect location. Status: ${loginRes.status}\n${(await loginRes.text()).slice(0, 200)}`
    );
  }
  const authCode = new URL(locationHeader, BASE_URL).searchParams.get('code');
  if (!authCode) {
    throw new Error(`Login failed — no code in redirect location: ${locationHeader}`);
  }
  log('Login succeeded (auth code received)');

  sep('OAuth — Token Exchange');
  const tokenRes = await fetch(tokenUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code: authCode,
      redirect_uri: REDIRECT_URI,
      code_verifier: codeVerifier,
      client_id: clientId,
    }).toString(),
  });
  if (!tokenRes.ok) {
    throw new Error(`Token exchange failed: ${tokenRes.status} ${await tokenRes.text()}`);
  }
  const tokenData = (await tokenRes.json()) as Record<string, unknown>;
  const accessToken = tokenData.access_token as string;
  if (!accessToken) {
    throw new Error(`No access_token in response: ${JSON.stringify(tokenData)}`);
  }
  log(`access_token : ${accessToken.slice(0, 20)}...`);
  return accessToken;
}

// ── Minimal MCP-over-HTTP (Streamable HTTP) client ──────────────────────────────

class McpClient {
  private sessionId: string | null = null;
  private nextId = 1;

  constructor(
    private readonly url: string,
    private readonly token: string
  ) {}

  /** POST a JSON-RPC request and return the parsed `result`. */
  private async rpc(method: string, params?: unknown): Promise<Record<string, unknown>> {
    const id = this.nextId++;
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: `Bearer ${this.token}`,
    };
    if (this.sessionId) headers['Mcp-Session-Id'] = this.sessionId;

    const res = await fetch(`${this.url}/mcp`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ jsonrpc: '2.0', id, method, params }),
    });

    const sid = res.headers.get('mcp-session-id');
    if (sid) this.sessionId = sid;

    if (!res.ok) {
      throw new Error(`MCP ${method} failed: ${res.status} ${await res.text()}`);
    }

    const payload = this.parseBody(await res.text());
    if (payload.error) {
      throw new Error(`MCP ${method} error: ${JSON.stringify(payload.error)}`);
    }
    return (payload.result ?? {}) as Record<string, unknown>;
  }

  /** A JSON-RPC notification (no response expected). */
  private async notify(method: string): Promise<void> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: `Bearer ${this.token}`,
    };
    if (this.sessionId) headers['Mcp-Session-Id'] = this.sessionId;
    await fetch(`${this.url}/mcp`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ jsonrpc: '2.0', method }),
    });
  }

  /** Parse either a JSON body or an SSE stream, returning the JSON-RPC envelope. */
  private parseBody(body: string): Record<string, unknown> {
    const trimmed = body.trim();
    if (trimmed.startsWith('{')) {
      return JSON.parse(trimmed);
    }
    // SSE: take the last `data:` line that parses as a JSON-RPC response.
    let last: Record<string, unknown> = {};
    for (const line of trimmed.split('\n')) {
      const l = line.trim();
      if (!l.startsWith('data:')) continue;
      try {
        last = JSON.parse(l.slice(5).trim());
      } catch {
        // ignore non-JSON data lines
      }
    }
    return last;
  }

  async initialize(): Promise<void> {
    await this.rpc('initialize', {
      protocolVersion: '2025-03-26',
      capabilities: {},
      clientInfo: { name: 'mistral-mcp-test', version: '1.0' },
    });
    await this.notify('notifications/initialized');
  }

  async listTools(): Promise<Array<{ name: string; description?: string; inputSchema?: unknown }>> {
    const result = await this.rpc('tools/list');
    return (result.tools ?? []) as Array<{ name: string; description?: string; inputSchema?: unknown }>;
  }

  /** Call a tool and flatten its text content. */
  async callTool(name: string, args: Record<string, unknown>): Promise<string> {
    const result = (await this.rpc('tools/call', { name, arguments: args })) as {
      content?: Array<{ type: string; text?: string }>;
      isError?: boolean;
    };
    let text = '';
    for (const part of result.content ?? []) {
      if (part.type === 'text' && part.text) text += part.text;
    }
    return text || JSON.stringify(result);
  }
}

// ── Mistral-driven agentic loop ─────────────────────────────────────────────────

async function runMistralLoop(mcp: McpClient): Promise<boolean> {
  sep('MCP — initialize + tools/list');
  await mcp.initialize();
  const mcpTools = await mcp.listTools();
  log(`Discovered ${mcpTools.length} MCP tools`);

  // Translate MCP tools → Mistral function tools.
  const tools = mcpTools.map((t) => ({
    type: 'function' as const,
    function: {
      name: t.name,
      description: t.description ?? '',
      parameters: (t.inputSchema as Record<string, unknown>) ?? { type: 'object' },
    },
  }));

  sep('Mistral — chat.complete() with MCP tools');
  log(`model : ${MODEL}`);

  const client = new Mistral({ apiKey: process.env.MISTRAL_API_KEY });

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const messages: any[] = [
    {
      role: 'user',
      content:
        'Use the system_status tool to verify the Nextcloud connection, then use list_files with path "/" to list the root folder. ' +
        'Report the single word PASS if both tools returned valid data, or FAIL otherwise.',
    },
  ];

  let finalText = '';
  for (let i = 0; i < MAX_ITERATIONS; i++) {
    const res = await client.chat.complete({ model: MODEL, messages, tools, toolChoice: 'auto' });
    const choice = res.choices?.[0];
    const message = choice?.message;
    if (!message) throw new Error('Mistral returned no message');

    const toolCalls = message.toolCalls ?? [];
    const content = typeof message.content === 'string' ? message.content : '';
    finalText = content || finalText;

    if (toolCalls.length === 0 || choice?.finishReason === 'stop') {
      log(`\nMistral final answer:\n${content}`);
      break;
    }

    // Echo the assistant turn (with its tool calls) back into the history.
    messages.push({ role: 'assistant', content: content ?? '', toolCalls });

    for (const tc of toolCalls) {
      const name = tc.function?.name ?? '';
      const rawArgs = tc.function?.arguments;
      const args =
        typeof rawArgs === 'string' ? (rawArgs ? JSON.parse(rawArgs) : {}) : (rawArgs ?? {});
      log(`  → tool call: ${name}(${JSON.stringify(args)})`);
      const output = await mcp.callTool(name, args);
      log(`    ← ${output.slice(0, 120).replace(/\n/g, ' ')}…`);
      messages.push({ role: 'tool', toolCallId: tc.id, name, content: output });
    }
  }

  return finalText.toUpperCase().includes('PASS') && !finalText.toUpperCase().includes('FAIL');
}

// ── Main ──────────────────────────────────────────────────────────────────────

async function main() {
  const missing: string[] = [];
  if (!process.env.MISTRAL_API_KEY) missing.push('MISTRAL_API_KEY');
  if (!NC_USER) missing.push('NEXTCLOUD_USER');
  if (!NC_PASS) missing.push('NEXTCLOUD_PASSWORD');
  if (missing.length > 0) {
    log(`ERROR: Missing required env vars: ${missing.join(', ')}`);
    process.exit(1);
  }

  log('\nAIquila Mistral × MCP Test');
  log(`MCP server : ${BASE_URL}`);
  log(`NC user    : ${NC_USER}`);

  sep('Pre-flight — Server Reachability');
  try {
    const preflight = await fetch(`${BASE_URL}/.well-known/oauth-authorization-server`, {
      signal: AbortSignal.timeout(5000),
    });
    if (preflight.status === 404) {
      log('ERROR: OAuth metadata endpoint returned 404 — is MCP_AUTH_ENABLED=true?');
      process.exit(1);
    }
    if (!preflight.ok) throw new Error(`HTTP ${preflight.status}`);
    log('Server reachable, OAuth enabled ✓');
  } catch (err) {
    log(`ERROR: Server not reachable at ${BASE_URL}: ${err}`);
    log('Is the MCP server running with OAuth enabled? (make up)');
    process.exit(1);
  }

  const accessToken = await getAccessToken();
  const mcp = new McpClient(BASE_URL, accessToken);
  const passed = await runMistralLoop(mcp);

  sep('Summary');
  if (passed) {
    log('  ✓ PASS — Mistral drove the AIquila MCP tools successfully');
    process.exit(0);
  } else {
    log('  ✗ FAIL — see output above for details');
    process.exit(1);
  }
}

main().catch((err: Error) => {
  log(`\nFatal error: ${err.message ?? String(err)}`);
  process.exit(1);
});
