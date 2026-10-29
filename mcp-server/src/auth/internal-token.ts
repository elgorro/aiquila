// SPDX-License-Identifier: MIT

import { createHash, timingSafeEqual } from 'node:crypto';
import { BlockList, isIP } from 'node:net';
import { logger } from '../logger.js';

// Loopback and private ranges: RFC 1918, RFC 4193 (unique local) and
// RFC 6598 (shared address space, used by some container networks).
const DEFAULT_NETWORKS = [
  '127.0.0.0/8',
  '10.0.0.0/8',
  '172.16.0.0/12',
  '192.168.0.0/16',
  '100.64.0.0/10',
  '::1/128',
  'fc00::/7',
];

// Headers a reverse proxy adds. Their presence means the request did not come
// straight from a container on the internal network.
const FORWARDING_HEADERS = ['forwarded', 'x-forwarded-for', 'x-real-ip'];

/** True when `token` is MCP_INTERNAL_TOKEN. Constant-time. */
export function isInternalToken(token: string): boolean {
  const internal = process.env.MCP_INTERNAL_TOKEN;
  if (!internal) return false;
  const hash = (s: string) => createHash('sha256').update(s).digest();
  return timingSafeEqual(hash(token), hash(internal));
}

/**
 * The networks MCP_INTERNAL_TOKEN is accepted from: MCP_INTERNAL_TOKEN_NETWORKS
 * (comma-separated CIDRs or addresses), or loopback and the private ranges.
 */
export function internalNetworks(): BlockList {
  const list = new BlockList();
  const raw = process.env.MCP_INTERNAL_TOKEN_NETWORKS;
  const entries = raw
    ? raw
        .split(',')
        .map((e) => e.trim())
        .filter(Boolean)
    : DEFAULT_NETWORKS;
  for (const entry of entries) {
    const [address, prefix] = entry.split('/');
    const family = isIP(address);
    if (!family) {
      logger.warn({ entry }, '[config] Ignoring malformed MCP_INTERNAL_TOKEN_NETWORKS entry');
      continue;
    }
    const type = family === 6 ? 'ipv6' : 'ipv4';
    if (prefix === undefined) list.addAddress(address, type);
    else list.addSubnet(address, Number(prefix), type);
  }
  return list;
}

/**
 * Whether a request may use MCP_INTERNAL_TOKEN: it must reach this server
 * directly — no proxy forwarding headers — from an internal address. The
 * socket address is used, never X-Forwarded-For, which the caller controls.
 */
export function internalTokenAllowed(
  req: { headers: Record<string, unknown>; socket?: { remoteAddress?: string } },
  networks: BlockList
): boolean {
  if (FORWARDING_HEADERS.some((h) => req.headers[h] !== undefined)) return false;
  let address = req.socket?.remoteAddress;
  if (!address) return false;
  // An IPv4 client on a dual-stack socket appears as ::ffff:a.b.c.d.
  const mapped = address.match(/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i);
  if (mapped) address = mapped[1];
  const family = isIP(address);
  if (!family) return false;
  return networks.check(address, family === 6 ? 'ipv6' : 'ipv4');
}
