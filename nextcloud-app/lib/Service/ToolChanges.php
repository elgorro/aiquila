<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Change a conversation's tools between turns without rebuilding its cache.
 *
 * `tools` renders first in the prompt, so adding or removing one changes
 * the prefix of the whole conversation and every turn after it is billed
 * again in full. With mid-conversation tool changes (beta
 * `mid-conversation-tool-changes-2026-07-01`) the `tools` a conversation was
 * first sent stays byte-identical, and the changes are appended instead:
 *
 *  - a tool that appears later is added to the end of `tools` with
 *    `defer_loading: true`, which keeps it out of the prefix, and offered from
 *    this turn on by a `tool_addition` block;
 *  - a tool that disappears stays declared (a reference must resolve) and is
 *    withdrawn by a `tool_removal` block; it can be offered again later.
 *
 * The blocks travel in a `role: "system"` message right after the user's
 * turn. The conversation keeps that message (see ConversationController), so
 * later turns replay it in place and the history stays byte-identical too.
 *
 * Only by reference: this header cannot redefine a tool. A tool whose
 * definition changed (an MCP server was updated) starts the state over, and
 * that one request misses the cache.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/mid-conversation-system-messages
 *
 * @psalm-type ToolState = array{declared: list<array<string, mixed>>, available: list<string>}
 */
final class ToolChanges {
    /**
     * Plan one turn: what to send as `tools`, which change blocks to append,
     * and the state to keep for the next turn.
     *
     * @param ToolState|null $state the conversation's state, null when it has none
     * @param list<array<string, mixed>> $current the tools available now
     * @return array{tools: list<array<string, mixed>>, blocks: list<array<string, mixed>>, state: ToolState, reset: bool}
     */
    public static function plan(?array $state, array $current): array {
        $byName = [];
        foreach ($current as $tool) {
            $byName[(string)($tool['name'] ?? '')] = $tool;
        }

        if ($state === null || self::anyRedefined($state['declared'], $byName)) {
            /** @var ToolState $fresh */
            $fresh = ['declared' => $current, 'available' => array_keys($byName)];
            return ['tools' => $current, 'blocks' => [], 'state' => $fresh, 'reset' => $state !== null];
        }

        $declared = $state['declared'];
        $available = $state['available'];
        $blocks = [];
        foreach ($available as $name) {
            if (!isset($byName[$name])) {
                $blocks[] = ['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => $name]];
            }
        }
        $isDeclared = array_fill_keys(array_map(static fn (array $t): string => (string)($t['name'] ?? ''), $declared), true);
        $isAvailable = array_fill_keys($available, true);
        foreach ($byName as $name => $tool) {
            if (isset($isAvailable[$name])) {
                continue;
            }
            if (!isset($isDeclared[$name])) {
                $declared[] = $tool + ['defer_loading' => true];
            }
            $blocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => $name]];
        }

        return [
            'tools' => $declared,
            'blocks' => $blocks,
            'state' => ['declared' => $declared, 'available' => array_keys($byName)],
            'reset' => false,
        ];
    }

    /** @return ToolState|null */
    public static function decodeState(?string $stored): ?array {
        $decoded = $stored !== null && $stored !== '' ? json_decode($stored, true) : null;
        if (!is_array($decoded) || !is_array($decoded['declared'] ?? null) || !is_array($decoded['available'] ?? null)) {
            return null;
        }
        /** @var ToolState */
        return ['declared' => array_values($decoded['declared']), 'available' => array_values(array_map('strval', $decoded['available']))];
    }

    /** @param ToolState $state */
    public static function encodeState(array $state): string {
        return (string)json_encode($state);
    }

    /** @param list<array<string, mixed>> $blocks */
    public static function encodeBlocks(array $blocks): string {
        return (string)json_encode($blocks);
    }

    /** @return list<array<string, mixed>> */
    public static function decodeBlocks(?string $stored): array {
        $decoded = $stored !== null && $stored !== '' ? json_decode($stored, true) : null;
        return is_array($decoded) ? array_values(array_filter($decoded, static fn ($b): bool => self::isChange($b))) : [];
    }

    /** @param mixed $block */
    public static function isChange($block): bool {
        return is_array($block) && in_array($block['type'] ?? null, ['tool_addition', 'tool_removal'], true);
    }

    /**
     * A change block as plain text, for a request that cannot carry it.
     *
     * @param array<string, mixed> $block
     */
    public static function describe(array $block): string {
        $name = (string)($block['tool']['name'] ?? 'a tool');
        return ($block['type'] ?? null) === 'tool_removal'
            ? sprintf('The tool %s is no longer available.', $name)
            : sprintf('The tool %s is available from here on.', $name);
    }

    /**
     * Whether a request uses the feature, so it needs the beta header: a
     * deferred tool, or a change block in a system message.
     */
    public static function inRequest(array $params): bool {
        foreach ($params['tools'] ?? [] as $tool) {
            if (is_array($tool) && !empty($tool['defer_loading'])) {
                return true;
            }
        }
        foreach ($params['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'system' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (self::isChange($block)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * $messages with every change block turned into text, for a request on a
     * model or provider without the feature.
     *
     * @param array<array-key, mixed> $messages
     * @return array<array-key, mixed>
     */
    public static function describeAll(array $messages): array {
        foreach ($messages as $i => $message) {
            if (!is_array($message) || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                if (self::isChange($block)) {
                    $messages[$i]['content'][$j] = ['type' => 'text', 'text' => self::describe($block)];
                }
            }
        }
        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $declared
     * @param array<string, array<string, mixed>> $current
     */
    private static function anyRedefined(array $declared, array $current): bool {
        foreach ($declared as $tool) {
            $name = (string)($tool['name'] ?? '');
            if (!isset($current[$name])) {
                continue;
            }
            unset($tool['defer_loading']);
            if (json_encode($tool) !== json_encode($current[$name])) {
                return true;
            }
        }
        return false;
    }
}
