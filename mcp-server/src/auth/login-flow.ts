// SPDX-License-Identifier: MIT

// Nextcloud Login Flow v2: the user signs in on Nextcloud's own pages (with
// 2FA, SSO, WebAuthn — whatever the instance requires), grants access, and
// Nextcloud issues an app password for this server. That app password is what
// every tool call of the grant then acts with.
// https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html#login-flow-v2

import { nextcloudBaseUrl } from '../tools/types.js';

const TIMEOUT_MS = 10_000;

/**
 * The Nextcloud origin browsers use: NEXTCLOUD_PUBLIC_URL, or NEXTCLOUD_URL
 * when that is already the public address. They differ when this server
 * reaches Nextcloud over an internal name (`http://nc` in Docker).
 */
export function nextcloudPublicUrl(): string {
  const raw = process.env.NEXTCLOUD_PUBLIC_URL;
  if (!raw) return nextcloudBaseUrl();
  const url = raw.replace(/\/+$/, '');
  if (!URL.canParse(url) || !/^https?:$/.test(new URL(url).protocol)) {
    throw new Error('NEXTCLOUD_PUBLIC_URL must be an http(s) URL');
  }
  return url;
}

/**
 * Why the sign-in link would point where no browser can follow it, or null.
 * Without NEXTCLOUD_PUBLIC_URL the link is built on NEXTCLOUD_URL, and a
 * single-label host there (`http://nc`, `http://nextcloud`) is a container
 * name only other containers resolve. Checked at startup, so the operator
 * reads it in the log rather than from a user stuck on an unreachable page.
 */
export function publicUrlProblem(): string | null {
  if (process.env.NEXTCLOUD_PUBLIC_URL || !process.env.NEXTCLOUD_URL) return null;
  const { hostname } = new URL(nextcloudBaseUrl());
  const ip = /^[\d.]+$/.test(hostname) || hostname.startsWith('[');
  if (ip || hostname.includes('.') || hostname === 'localhost') return null;
  return (
    `NEXTCLOUD_URL host "${hostname}" looks internal and NEXTCLOUD_PUBLIC_URL is not set: ` +
    'the Nextcloud sign-in link will point to it, which browsers cannot open. ' +
    'Set NEXTCLOUD_PUBLIC_URL to the address users open Nextcloud on.'
  );
}

/** A started flow: where to send the user, and how to collect the result. */
export interface StartedFlow {
  /** The Nextcloud sign-in page, on the public origin. */
  loginUrl: string;
  pollToken: string;
  /** Path of the poll endpoint (with Nextcloud's webroot), called on the internal origin. */
  pollPath: string;
}

/** What the poll endpoint answers once the user has granted access. */
export interface GrantedFlow {
  loginName: string;
  appPassword: string;
}

/**
 * Nextcloud builds the URLs it returns from its own idea of its host, which
 * can be the internal or the public one. Either is accepted; anything else is
 * refused, so the browser is never sent to a host the operator did not name.
 */
function ownUrl(raw: unknown, what: string): URL {
  if (typeof raw !== 'string' || !URL.canParse(raw)) {
    throw new Error(`Nextcloud returned no valid ${what}`);
  }
  const url = new URL(raw);
  const allowed = new Set([
    new URL(nextcloudBaseUrl()).origin,
    new URL(nextcloudPublicUrl()).origin,
  ]);
  if (!allowed.has(url.origin)) {
    throw new Error(`Nextcloud returned a ${what} on an unexpected origin: ${url.origin}`);
  }
  return url;
}

/**
 * The browser-facing form of a URL Nextcloud built on its internal address:
 * the internal base URL (webroot included) swapped for the public one. A URL
 * already on the public origin is left as it is.
 */
function toPublic(url: URL): URL {
  const pub = new URL(nextcloudPublicUrl());
  if (url.origin === pub.origin) return url;
  const internalRoot = new URL(nextcloudBaseUrl()).pathname.replace(/\/+$/, '');
  const path = url.pathname.startsWith(`${internalRoot}/`)
    ? url.pathname.slice(internalRoot.length)
    : url.pathname;
  return new URL(`${pub.pathname.replace(/\/+$/, '')}${path}${url.search}${url.hash}`, pub.origin);
}

/**
 * Starts a flow. `deviceName` is sent as the User-Agent, which Nextcloud
 * shows on the grant page and as the app password's name under
 * Security → Devices & sessions.
 */
export async function startLoginFlow(deviceName: string): Promise<StartedFlow> {
  const resp = await fetch(`${nextcloudBaseUrl()}/index.php/login/v2`, {
    method: 'POST',
    headers: { 'User-Agent': deviceName, Accept: 'application/json' },
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });
  if (!resp.ok) throw new Error(`Nextcloud refused to start Login Flow v2: ${resp.status}`);
  const body = (await resp.json()) as {
    poll?: { token?: unknown; endpoint?: unknown };
    login?: unknown;
  };
  const login = ownUrl(body.login, 'login URL');
  const endpoint = ownUrl(body.poll?.endpoint, 'poll endpoint');
  if (typeof body.poll?.token !== 'string' || body.poll.token === '') {
    throw new Error('Nextcloud returned no poll token');
  }
  return {
    loginUrl: toPublic(login).href,
    pollToken: body.poll.token,
    pollPath: endpoint.pathname,
  };
}

/**
 * Polls once. Null while the user has not granted access yet (404). The
 * credentials are returned exactly once; the flow is spent afterwards.
 */
export async function pollLoginFlow(flow: StartedFlow): Promise<GrantedFlow | null> {
  // pollPath is the full path Nextcloud returned, webroot included, so it goes
  // on the origin alone (NEXTCLOUD_URL may itself carry the webroot).
  const resp = await fetch(`${new URL(nextcloudBaseUrl()).origin}${flow.pollPath}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: new URLSearchParams({ token: flow.pollToken }).toString(),
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });
  if (resp.status === 404) return null;
  if (!resp.ok) throw new Error(`Login Flow v2 poll failed: ${resp.status}`);
  const body = (await resp.json()) as { loginName?: unknown; appPassword?: unknown };
  if (typeof body.loginName !== 'string' || typeof body.appPassword !== 'string') {
    throw new Error('Login Flow v2 poll returned no credentials');
  }
  // `server` is ignored: requests go to the configured NEXTCLOUD_URL, never
  // to an address taken from a response.
  return { loginName: body.loginName, appPassword: body.appPassword };
}

function ocsHeaders(loginName: string, appPassword: string): Record<string, string> {
  return {
    Authorization: `Basic ${Buffer.from(`${loginName}:${appPassword}`).toString('base64')}`,
    'OCS-APIRequest': 'true',
    Accept: 'application/json',
  };
}

/** What Nextcloud says about an app password. */
export type CredentialCheck =
  { state: 'valid'; userId: string } | { state: 'rejected' } | { state: 'unknown' };

/**
 * Asks Nextcloud who an app password belongs to. `rejected` (401) means it was
 * revoked, or its user was disabled or deleted; `unknown` means Nextcloud
 * could not be asked, which must not end a grant.
 */
export async function checkCredential(
  loginName: string,
  appPassword: string
): Promise<CredentialCheck> {
  try {
    const resp = await fetch(`${nextcloudBaseUrl()}/ocs/v2.php/cloud/user`, {
      headers: ocsHeaders(loginName, appPassword),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (resp.status === 401) return { state: 'rejected' };
    if (!resp.ok) return { state: 'unknown' };
    const body = (await resp.json()) as { ocs?: { data?: { id?: unknown } } };
    const id = body?.ocs?.data?.id;
    return typeof id === 'string' && id !== ''
      ? { state: 'valid', userId: id }
      : { state: 'unknown' };
  } catch {
    return { state: 'unknown' };
  }
}

/**
 * Deletes the app password in Nextcloud, so the device disappears from the
 * user's list. Returns false when Nextcloud could not be reached; an app
 * password that is already gone (401) counts as deleted.
 */
export async function deleteAppPassword(loginName: string, appPassword: string): Promise<boolean> {
  try {
    const resp = await fetch(`${nextcloudBaseUrl()}/ocs/v2.php/core/apppassword`, {
      method: 'DELETE',
      headers: ocsHeaders(loginName, appPassword),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    return resp.ok || resp.status === 401;
  } catch {
    return false;
  }
}
