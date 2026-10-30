// SPDX-License-Identifier: MIT

import { AsyncLocalStorage } from 'node:async_hooks';

/**
 * Whose Nextcloud account a request acts as.
 *
 * With OAuth on, every request to /mcp runs inside one of these, set by the
 * HTTP transport before the MCP SDK sees the request:
 * - `user`: an OAuth bearer token. Tools act as the signed-in user, with the
 *   app password Nextcloud issued to them through Login Flow v2. Nextcloud
 *   decides what that user may do.
 * - `service`: MCP_INTERNAL_TOKEN. Tools act as NEXTCLOUD_USER (deprecated).
 * - `anonymous`: a lazy-auth request without a token. Nothing may reach
 *   Nextcloud on its behalf.
 *
 * stdio, and HTTP with auth off, run outside any context and use the
 * NEXTCLOUD_USER account from the environment, as they always have.
 */
export type RequestIdentity =
  { kind: 'user'; user: NextcloudUserCredential } | { kind: 'service' } | { kind: 'anonymous' };

/** A user's Nextcloud credential, as Login Flow v2 returned it. */
export interface NextcloudUserCredential {
  /** The uid: what DAV paths and grants are keyed by. */
  userId: string;
  /** The name to authenticate with. Can differ from the uid (an email, an LDAP name). */
  loginName: string;
  /** The app password Nextcloud issued for this grant. */
  appPassword: string;
  /** The OAuth grant the credential belongs to. */
  grantId: string;
}

const storage = new AsyncLocalStorage<RequestIdentity>();

// Set by the HTTP transport when OAuth is on. From then on, Nextcloud is only
// reached inside a context: code that runs outside one has a bug, and must
// fail rather than quietly use the service account.
let identityRequired = false;

export function requireIdentityContext(required = true): void {
  identityRequired = required;
}

export function isIdentityRequired(): boolean {
  return identityRequired;
}

/** Runs `fn` as `identity`. The context follows every await inside `fn`. */
export function runAs<T>(identity: RequestIdentity, fn: () => T): T {
  return storage.run(identity, fn);
}

/** The identity of the request being served, if any. */
export function currentIdentity(): RequestIdentity | undefined {
  return storage.getStore();
}

// Called when a tool of a signed-in user fails. Set by the HTTP transport, so
// the grant's app password is checked again at once: a device removed in
// Nextcloud then ends the grant on the next request, not minutes later.
let toolFailureHook: ((user: NextcloudUserCredential) => void) | undefined;

export function onUserToolFailure(hook: (user: NextcloudUserCredential) => void): void {
  toolFailureHook = hook;
}

/** Reports a failed tool call of the current request's user, if any. */
export function reportToolFailure(): void {
  const identity = storage.getStore();
  if (identity?.kind === 'user') toolFailureHook?.(identity.user);
}

/** Thrown when Nextcloud is about to be called without a usable identity. */
export class SignInRequiredError extends Error {
  constructor(message = 'Sign in to Nextcloud to use this tool') {
    super(message);
    this.name = 'SignInRequiredError';
  }
}
