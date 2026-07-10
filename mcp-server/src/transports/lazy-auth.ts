// SPDX-License-Identifier: MIT

/**
 * Lazy authentication (mixed auth) for the Streamable HTTP transport.
 *
 * Unauthenticated clients may complete the MCP handshake, enumerate the server's
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
 */
const PUBLIC_METHODS: ReadonlySet<string> = new Set([
  'initialize',
  'notifications/initialized',
  'notifications/cancelled',
  'ping',
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
