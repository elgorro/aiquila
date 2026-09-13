<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

/**
 * The flat `history` list the chat task types carry, as chat turns.
 *
 * Both core:text2text:chat and core:audio2audio:chat describe it the same way —
 * "the history of chat messages before the current message, starting with a
 * message by the user" — so the role is positional rather than labelled, and
 * both providers have to read it identically or a conversation changes meaning
 * when it switches modality.
 */
final class ChatHistory {
    /**
     * Even index is the user, odd index is the assistant. Empty entries are
     * skipped without shifting the parity of the ones after them.
     *
     * @param mixed $history
     * @return list<array{role: string, content: string}>
     */
    public static function toMessages(mixed $history): array {
        if (!is_array($history)) {
            return [];
        }

        $messages = [];
        foreach (array_values($history) as $i => $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => $entry,
            ];
        }

        return $messages;
    }
}
