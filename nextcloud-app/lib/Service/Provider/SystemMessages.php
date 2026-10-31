<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Mid-conversation system messages: `{"role": "system", ...}` entries inside
 * the `messages` array.
 *
 * On the Claude models and platforms that accept them, such a message adds an
 * operator instruction without touching the top-level `system` field, so the
 * cached prefix ahead of it stays valid. Everywhere else it is folded into the
 * neighbouring user turn as a `<system-reminder>` block, which keeps the
 * conversation valid and deterministic (the same history always renders the
 * same request, so caching still works there too).
 *
 * The API only accepts one where it immediately follows a `user` turn and is
 * either last or immediately followed by an `assistant` turn; any other
 * position is a 400. A message in any other position is folded even on a model
 * that supports the role.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/mid-conversation-system-messages
 */
final class SystemMessages {
    /**
     * @param array $messages Provider messages, as the providers receive them
     * @param bool $native Whether the target model and platform accept the role
     * @return array<int, array<string, mixed>>
     */
    public static function prepare(array $messages, bool $native): array {
        $messages = array_values($messages);
        if (!self::contains($messages)) {
            return $messages;
        }

        $messages = self::mergeRuns($messages);
        $out = [];
        $pending = [];
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) !== 'system') {
                if ($pending !== [] && ($message['role'] ?? null) === 'user') {
                    // A system message with no user turn before it: fold forward.
                    $message['content'] = self::withReminders($message['content'] ?? '', $pending, false);
                    $pending = [];
                }
                $out[] = $message;
                continue;
            }

            $text = (string)$message['content'];
            if ($text === '') {
                continue;
            }

            $prev = $out === [] ? null : $out[array_key_last($out)];
            $next = $messages[$i + 1] ?? null;
            $placeable = $prev !== null && ($prev['role'] ?? null) === 'user'
                && ($next === null || ($next['role'] ?? null) === 'assistant');
            if ($native && $placeable) {
                $out[] = ['role' => 'system', 'content' => $text];
                continue;
            }

            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && ($out[$last]['role'] ?? null) === 'user') {
                $out[$last]['content'] = self::withReminders($out[$last]['content'] ?? '', [$text], true);
            } else {
                $pending[] = $text;
            }
        }

        return $out;
    }

    /**
     * Collapse each run of consecutive system messages into one plain-text
     * message, so a run is placed (or folded) as a unit.
     *
     * @param list<mixed> $messages
     * @return array<int, array<string, mixed>>
     */
    private static function mergeRuns(array $messages): array {
        $out = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'system') {
                $out[] = $message;
                continue;
            }
            $text = self::text($message['content'] ?? '');
            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && ($out[$last]['role'] ?? null) === 'system') {
                $out[$last]['content'] = trim($out[$last]['content'] . "\n\n" . $text);
            } else {
                $out[] = ['role' => 'system', 'content' => $text];
            }
        }
        return $out;
    }

    public static function contains(array $messages): bool {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $e is the API refusing the role on this model. Unsupported models
     * answer with a 400 naming the role.
     */
    public static function isRejection(\Throwable $e): bool {
        return preg_match("/role\\W{0,3}system\\W{0,3}(?:is )?not supported/i", $e->getMessage()) === 1;
    }

    /**
     * @param string|list<array<string, mixed>> $content
     * @param list<string> $texts
     * @return list<array<string, mixed>>
     */
    private static function withReminders(string|array $content, array $texts, bool $append): array {
        $blocks = is_string($content) ? ($content === '' ? [] : [['type' => 'text', 'text' => $content]]) : $content;
        $reminders = array_map(
            static fn (string $t): array => ['type' => 'text', 'text' => "<system-reminder>\n" . $t . "\n</system-reminder>"],
            $texts,
        );
        return $append ? array_merge($blocks, $reminders) : array_merge($reminders, $blocks);
    }

    /** @param string|list<array<string, mixed>> $content */
    private static function text(string|array $content): string {
        if (is_string($content)) {
            return trim($content);
        }
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
        return trim(implode("\n\n", $parts));
    }
}
