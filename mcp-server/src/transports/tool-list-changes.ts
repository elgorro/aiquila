// SPDX-License-Identifier: MIT

/**
 * `notifications/tools/list_changed` for `subscriptions/listen` streams.
 *
 * MCP 2026-07-28, Tools: `listChanged` "indicates whether the server will emit
 * notifications when the list of available tools changes", and Subscriptions:
 * `list_changed` goes to streams that opted in with `toolsListChanged: true`.
 *
 * Tool lists differ per account (app detection runs as the signed-in user), so
 * the SDK's default bus, which delivers every event to every stream, would
 * tell one user about another's change. This bus remembers whose stream each
 * listener belongs to: the SDK registers it while serving that user's
 * `subscriptions/listen` request, inside the request's identity context.
 *
 * A user's sequences are part of their tool list too (`seq_*` tools): when
 * the tag of their sequences in Nextcloud changes, the same event goes out.
 *
 * App detection and sequences are cached per user and re-checked only when a
 * request needs them. So that a client holding nothing but a listen stream
 * still learns of a change, each signed-in user with an open stream has both
 * checked every minute while the stream is open; the caches decide whether
 * that reaches Nextcloud.
 */

import type { ServerEvent, ServerEventBus } from '@modelcontextprotocol/server';
import { currentIdentity, runAs, type RequestIdentity } from '../nc-context.js';
import { getFilteredToolSets, onToolSetChange, toolSetKey } from '../tool-registry.js';
import { onSequencesChange } from '../sequences/store.js';
import { refreshSequences } from '../sequences/tools.js';
import { logger } from '../logger.js';

type Listener = (event: ServerEvent) => void;

interface Watched {
  listeners: Set<Listener>;
  identity: RequestIdentity | undefined;
  timer?: ReturnType<typeof setInterval>;
}

export class IdentityScopedEventBus implements ServerEventBus {
  private readonly byKey = new Map<string, Watched>();
  private readonly stopWatching: (() => void)[];

  constructor(private readonly refreshMs = 60_000) {
    const changed = (key: string) => this.publishTo(key, { kind: 'tools_list_changed' });
    this.stopWatching = [onToolSetChange(changed), onSequencesChange(changed)];
  }

  /** Every listener, whoever it belongs to. */
  publish(event: ServerEvent): void {
    for (const key of this.byKey.keys()) this.publishTo(key, event);
  }

  /** Only the listeners of the account `key` names (see `toolSetKey`). */
  publishTo(key: string, event: ServerEvent): void {
    const watched = this.byKey.get(key);
    if (!watched) return;
    for (const listener of [...watched.listeners]) {
      try {
        listener(event);
      } catch (err) {
        logger.warn({ err: err instanceof Error ? err.message : String(err) }, '[mcp] Listener');
      }
    }
  }

  subscribe(listener: Listener): () => void {
    const identity = currentIdentity();
    const key = toolSetKey(identity);
    let watched = this.byKey.get(key);
    if (!watched) {
      watched = { listeners: new Set(), identity };
      this.byKey.set(key, watched);
    }
    // The newest stream's credential is the one most likely still valid.
    watched.identity = identity;
    watched.listeners.add(listener);
    if (identity?.kind === 'user' && !watched.timer) {
      const w = watched;
      w.timer = setInterval(() => {
        void runAs(w.identity!, async () => {
          await getFilteredToolSets();
          await refreshSequences();
        }).catch((err: unknown) =>
          logger.debug(
            { err: err instanceof Error ? err.message : String(err) },
            '[mcp] Tool list refresh failed'
          )
        );
      }, this.refreshMs);
      w.timer.unref?.();
    }

    let done = false;
    return () => {
      if (done) return;
      done = true;
      const current = this.byKey.get(key);
      if (!current) return;
      current.listeners.delete(listener);
      if (current.listeners.size === 0) {
        if (current.timer) clearInterval(current.timer);
        this.byKey.delete(key);
      }
    };
  }

  /** Number of open listeners, per key — for tests. */
  listenerCount(key: string): number {
    return this.byKey.get(key)?.listeners.size ?? 0;
  }

  close(): void {
    for (const stop of this.stopWatching) stop();
    for (const w of this.byKey.values()) if (w.timer) clearInterval(w.timer);
    this.byKey.clear();
  }
}
