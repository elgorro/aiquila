// SPDX-License-Identifier: MIT

/**
 * Lazy authentication (mixed auth) for the Streamable HTTP transport.
 *
 * Unauthenticated clients may discover the server (the 2025-era `initialize`
 * handshake or the 2026-07-28 `server/discover` RPC), enumerate its
 * capabilities, and call the tools listed in PUBLIC_TOOLS.  Anything else is
 * refused at the HTTP layer with a 401 + WWW-Authenticate challenge, which is
 * what makes clients render an inline "Connect" card and retry the same call
 * once the user has authorised.
 *
 * The refusal has to be an HTTP status, so the check must run *before* the
 * JSON-RPC message reaches the MCP SDK: once a tool handler is executing, its
 * return value is already destined to be wrapped in a 200 response, and a 200
 * carrying `isError: true` reads as an application-level failure, not an auth
 * challenge.
 *
 * @see https://claude.com/docs/connectors/building/lazy-authentication
 */

/**
 * JSON-RPC methods reachable without a token.  These describe the server rather
 * than touching the Nextcloud instance behind it, so they leak nothing.
 *
 * The method is read from the body, which the 2026-07-28 Streamable HTTP binding
 * makes the source of truth: the SDK rejects a request whose `Mcp-Method` header
 * disagrees with it (HeaderMismatch, -32020), so the header cannot be used to
 * smuggle a protected call past this check.
 *
 * `subscriptions/listen` is deliberately absent: it holds a stream open for the
 * life of the subscription, which an anonymous caller has no use for.
 */
const PUBLIC_METHODS: ReadonlySet<string> = new Set([
  // 2025-era handshake and housekeeping
  'initialize',
  'notifications/initialized',
  'notifications/cancelled',
  'ping',
  // 2026-07-28 replacement for the handshake: versions, capabilities, identity
  'server/discover',
  'tools/list',
  'resources/list',
  'resources/templates/list',
  'prompts/list',
]);

/**
 * Tools reachable without a token.  Every other AIquila tool proxies to
 * Nextcloud, so this set is deliberately tiny.
 */
const PUBLIC_TOOLS: ReadonlySet<string> = new Set(['get_local_time']);

function isPublicMessage(msg: unknown): boolean {
  if (!msg || typeof msg !== 'object') return false;

  const method = (msg as { method?: unknown }).method;
  if (typeof method !== 'string') return false;

  if (method === 'tools/call') {
    const name = (msg as { params?: { name?: unknown } }).params?.name;
    return typeof name === 'string' && PUBLIC_TOOLS.has(name);
  }

  return PUBLIC_METHODS.has(method);
}

/**
 * True when every message in the request body is safe to serve anonymously.
 *
 * A JSON-RPC batch is a single HTTP request and therefore gets a single status
 * code, so one protected message taints the whole batch.  An empty or
 * non-JSON-RPC body (a GET for the SSE stream, a DELETE to end a session) is
 * never public — those still require a bearer token.
 */
export function isPublicRequest(body: unknown): boolean {
  const messages = Array.isArray(body) ? body : [body];
  if (messages.length === 0) return false;
  return messages.every(isPublicMessage);
}
