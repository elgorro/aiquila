// SPDX-License-Identifier: MIT

interface Window {
  count: number;
  resetAt: number;
}

export interface LoginThrottleOptions {
  // Login attempts per client IP and window, successful or not.
  perIp?: number;
  // Failed logins per username and window, from any number of IPs.
  perUser?: number;
  windowMs?: number;
}

/**
 * Brute-force protection for /auth/login. A per-IP limit alone does not slow
 * an attacker who spreads guesses for one account over many addresses, and
 * Nextcloud's own protection sees only the MCP server's address. So failed
 * logins are also counted per username: once an account reaches the limit,
 * further attempts for it are refused without asking Nextcloud until the
 * window ends. A successful login clears the account's count.
 */
export class LoginThrottle {
  private readonly ips = new Map<string, Window>();
  private readonly users = new Map<string, Window>();
  private readonly perIp: number;
  private readonly perUser: number;
  private readonly windowMs: number;

  constructor(options: LoginThrottleOptions = {}) {
    this.perIp = options.perIp ?? 10;
    this.perUser = options.perUser ?? 10;
    this.windowMs = options.windowMs ?? 15 * 60 * 1000;
  }

  /** Counts an attempt from `ip`. False once the IP is over its limit. */
  allowIp(ip: string): boolean {
    return this.hit(this.ips, ip) <= this.perIp;
  }

  /** True while `username` has too many recent failures. */
  isUserLocked(username: string): boolean {
    const entry = this.current(this.users, normalize(username));
    return entry !== undefined && entry.count >= this.perUser;
  }

  recordFailure(username: string): void {
    this.hit(this.users, normalize(username));
  }

  recordSuccess(username: string): void {
    this.users.delete(normalize(username));
  }

  private current(map: Map<string, Window>, key: string): Window | undefined {
    const entry = map.get(key);
    if (entry && Date.now() > entry.resetAt) {
      map.delete(key);
      return undefined;
    }
    return entry;
  }

  private hit(map: Map<string, Window>, key: string): number {
    const now = Date.now();
    const entry = this.current(map, key);
    if (entry) return ++entry.count;
    // Drop expired windows now and then, so the maps stay bounded.
    if (map.size > 10_000) {
      for (const [k, w] of map) if (now > w.resetAt) map.delete(k);
    }
    map.set(key, { count: 1, resetAt: now + this.windowMs });
    return 1;
  }
}

/** Nextcloud login names are case-insensitive. */
function normalize(username: string): string {
  return username.trim().toLowerCase();
}
