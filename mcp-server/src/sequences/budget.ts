// SPDX-License-Identifier: MIT

/**
 * Charges the steps of a sequence to the caller's tool-call rate limit.
 *
 * The HTTP transport counts each `tools/call` message once, before the SDK
 * sees it. A sequence turns one call into several requests to Nextcloud, so
 * the transport also hands the request a charge function, and the runner
 * spends one call of the same budget on every step after the first. stdio has
 * no rate limit and sets none.
 */

import { AsyncLocalStorage } from 'node:async_hooks';

const storage = new AsyncLocalStorage<() => boolean>();

/** Runs `fn` with `charge` as the request's step budget. */
export function withToolCallCharge<T>(charge: () => boolean, fn: () => T): T {
  return storage.run(charge, fn);
}

/** Charges one tool call; false when the rate limit refuses it. */
export function chargeToolCall(): boolean {
  return storage.getStore()?.() ?? true;
}
