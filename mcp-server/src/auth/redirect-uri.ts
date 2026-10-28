// SPDX-License-Identifier: MIT

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

/** True for an http(s) redirect URI on a loopback host (RFC 8252 §7.3). */
export function isLoopbackRedirect(url: URL): boolean {
  return (
    (url.protocol === 'http:' || url.protocol === 'https:') && LOOPBACK_HOSTS.has(url.hostname)
  );
}

/**
 * Compares a requested redirect_uri with a registered one. Exact string match
 * (RFC 6749 §3.1.2.3), except that a loopback redirect may use any port: native
 * clients take an ephemeral port from the OS at request time, and RFC 8252 §7.3
 * says the authorization server MUST allow any port for them.
 */
export function redirectUriMatches(requested: string, registered: string): boolean {
  if (requested === registered) return true;
  let req: URL, reg: URL;
  try {
    req = new URL(requested);
    reg = new URL(registered);
  } catch {
    return false;
  }
  if (!isLoopbackRedirect(req) || !isLoopbackRedirect(reg)) return false;
  return (
    req.protocol === reg.protocol &&
    req.hostname === reg.hostname &&
    req.pathname === reg.pathname &&
    req.search === reg.search
  );
}

/** True when `requested` matches any of the client's registered redirect URIs. */
export function isRegisteredRedirectUri(
  requested: string,
  registered: readonly unknown[]
): boolean {
  return registered.some((uri) => redirectUriMatches(requested, String(uri)));
}
