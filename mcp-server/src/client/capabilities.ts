// SPDX-License-Identifier: MIT

import { fetchOCS } from './ocs.js';
import { logger } from '../logger.js';
import { currentIdentity, SignInRequiredError } from '../nc-context.js';

/**
 * What the Nextcloud server behind a request supports.
 *
 * One read of /cloud/capabilities, shared by app detection and by the tools
 * that gate a version-dependent parameter. The payload carries every app's
 * capabilities, Talk's included (`capabilities.spreed`), and depends on the
 * account: an app can be enabled for some groups only.
 */
export interface NextcloudCapabilities {
  version?: { major: number; minor: number; micro: number; string: string };
  capabilities: Record<string, unknown>;
}

/** A condition a parameter needs from the server. */
export interface CapabilityGate {
  /** What to name in the error, e.g. 'Talk 25 (Nextcloud 35)'. */
  requires: string;
  isMet: (caps: NextcloudCapabilities) => boolean;
}

/** A Talk feature flag, from spreed's `features` list. */
export function talkFeature(feature: string, requires: string): CapabilityGate {
  return {
    requires,
    isMet: (caps) => {
      const spreed = caps.capabilities.spreed as { features?: unknown } | undefined;
      return Array.isArray(spreed?.features) && spreed.features.includes(feature);
    },
  };
}

/** A minimum Nextcloud major version. */
export function nextcloudMajor(major: number): CapabilityGate {
  return {
    requires: `Nextcloud ${major}`,
    isMet: (caps) => (caps.version?.major ?? 0) >= major,
  };
}

// A success expires too, so a server upgraded under a running MCP server is
// noticed. A failure is retried soon: it says nothing about the server.
const CACHE_TTL_MS = 10 * 60 * 1000;
const FAILURE_TTL_MS = 60 * 1000;
const MAX_CACHED_ACCOUNTS = 500;

interface CacheEntry {
  result: Promise<NextcloudCapabilities | null>;
  expiresAt: number;
}

const cache = new Map<string, CacheEntry>();

async function probe(): Promise<NextcloudCapabilities | null> {
  try {
    const response = await fetchOCS<NextcloudCapabilities>('/ocs/v2.php/cloud/capabilities');
    const { version, capabilities } = response.ocs.data;
    return { version, capabilities: capabilities ?? {} };
  } catch (err) {
    logger.warn(
      { err: err instanceof Error ? err.message : String(err) },
      '[capabilities] Could not read Nextcloud capabilities'
    );
    return null;
  }
}

/**
 * The capabilities visible to the account of the request being served, or
 * null when the probe failed. Concurrent callers share one request.
 */
export async function getCapabilities(): Promise<NextcloudCapabilities | null> {
  const identity = currentIdentity();
  if (identity?.kind === 'anonymous') throw new SignInRequiredError();
  const key = identity?.kind === 'user' ? `user:${identity.user.userId}` : 'env';

  const cached = cache.get(key);
  if (cached && cached.expiresAt > Date.now()) {
    // Re-inserted, so the map's insertion order is least recently used first.
    cache.delete(key);
    cache.set(key, cached);
    return cached.result;
  }

  cache.delete(key);
  if (cache.size >= MAX_CACHED_ACCOUNTS) {
    const oldest = cache.keys().next().value;
    if (oldest !== undefined) cache.delete(oldest);
  }
  const entry: CacheEntry = { result: probe(), expiresAt: Date.now() + CACHE_TTL_MS };
  cache.set(key, entry);
  const caps = await entry.result;
  if (!caps) entry.expiresAt = Date.now() + FAILURE_TTL_MS;
  return caps;
}

/**
 * Checks the gated parameters a caller actually passed. Returns the message
 * for the tool to report, or null when the request can go ahead.
 *
 * A parameter is refused, not dropped: the caller asked for it, and a request
 * that quietly loses a `classified` preset or a share password option does
 * something else than was asked. For the same reason a failed probe refuses
 * the parameter: unknown is not yes. Calls that pass no gated parameter need
 * no capabilities, and make no request.
 */
export async function checkGatedParams(
  args: Record<string, unknown>,
  gates: Record<string, CapabilityGate>
): Promise<string | null> {
  const used = Object.keys(gates).filter((param) => args[param] !== undefined);
  if (used.length === 0) return null;

  const caps = await getCapabilities();
  if (!caps) {
    return (
      `Could not read this Nextcloud server's capabilities, so nothing was sent. ` +
      `Unchecked: ${used.join(', ')}. Try again, or leave them out.`
    );
  }
  const unmet = used.filter((param) => !gates[param].isMet(caps));
  if (unmet.length === 0) return null;
  const reasons = unmet.map((param) => `${param} requires ${gates[param].requires}`);
  return `Not supported by this Nextcloud server, so nothing was sent: ${reasons.join('; ')}.`;
}

/** Reset cached state — for testing only. */
export function _resetCapabilitiesCache(): void {
  cache.clear();
}
