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

// Hosts of the MCP clients this server is used with out of the box.
const BUILTIN_REDIRECT_HOSTS = ['claude.ai', 'claude.com'];

// Schemes that never reach a native app and must never be a redirect target.
const FORBIDDEN_SCHEMES = new Set([
  'javascript:',
  'data:',
  'vbscript:',
  'file:',
  'blob:',
  'about:',
  'ws:',
  'wss:',
  'ftp:',
]);

/**
 * Which redirect URIs a dynamically registered client may use (RFC 7591 §5:
 * a TLS-protected site, a loopback HTTP URI, or an app-specific scheme).
 * Without a check, anyone could register a redirect to their own host and
 * send a victim a sign-in link whose authorization code then goes to them.
 *
 * Allowed:
 * - loopback URIs on any port (RFC 8252 §7.3)
 * - https on claude.ai, claude.com, the NEXTCLOUD_URL host (the AIquila
 *   Nextcloud app registers itself) and each host in MCP_REDIRECT_ALLOWLIST;
 *   `*` there allows any https host
 * - private-use schemes containing a dot (RFC 8252 §7.1, §8.4), and those
 *   listed in MCP_REDIRECT_ALLOWLIST as `scheme:`
 */
export class RedirectUriPolicy {
  private readonly hosts: Set<string>;
  private readonly schemes: Set<string>;
  private readonly anyHttpsHost: boolean;

  constructor(entries: string[] = []) {
    this.hosts = new Set(BUILTIN_REDIRECT_HOSTS);
    this.schemes = new Set();
    this.anyHttpsHost = false;
    for (const raw of entries) {
      const entry = raw.trim().toLowerCase();
      if (!entry) continue;
      if (entry === '*') this.anyHttpsHost = true;
      else if (entry.endsWith(':')) this.schemes.add(entry);
      else this.hosts.add(entry);
    }
  }

  /** Why `uri` may not be registered, or undefined when it may. */
  reject(uri: string): string | undefined {
    let url: URL;
    try {
      url = new URL(uri);
    } catch {
      return `${uri} is not an absolute URI`;
    }
    // RFC 6749 §3.1.2: the redirection endpoint URI MUST NOT include a fragment.
    if (url.hash || uri.includes('#')) return `${uri} has a fragment`;
    if (isLoopbackRedirect(url)) return undefined;
    if (url.protocol === 'https:') {
      if (url.username || url.password) return `${uri} carries credentials`;
      if (this.anyHttpsHost || this.hosts.has(url.hostname)) return undefined;
      return `${url.hostname} is not an allowed redirect host`;
    }
    if (url.protocol === 'http:') return `${uri} is not https and not a loopback address`;
    if (FORBIDDEN_SCHEMES.has(url.protocol)) return `${url.protocol} is not a redirect scheme`;
    if (url.protocol.includes('.') || this.schemes.has(url.protocol)) return undefined;
    return `${url.protocol} is not an allowed redirect scheme`;
  }

  /**
   * The policy from MCP_REDIRECT_ALLOWLIST, plus the NEXTCLOUD_URL host —
   * when Nextcloud is reached over an internal address, list its public
   * host in MCP_REDIRECT_ALLOWLIST instead.
   */
  static fromEnv(): RedirectUriPolicy {
    const entries = (process.env.MCP_REDIRECT_ALLOWLIST ?? '').split(',');
    const ncUrl = process.env.NEXTCLOUD_URL;
    if (ncUrl && URL.canParse(ncUrl)) entries.push(new URL(ncUrl).hostname);
    return new RedirectUriPolicy(entries);
  }
}
