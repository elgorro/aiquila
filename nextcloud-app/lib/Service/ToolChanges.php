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
 * Redefining a tool (an MCP server was updated) needs the definition by
 * value. Where the platform takes `inline-tools-2026-09-15`, a changed tool
 * is offered as a `tool_addition` carrying its new `tool_definition`; the new
 * definition replaces the old one from that message on, and `tools` stays as
 * first sent. `current` keeps each tool's live definition where it differs
 * from the declared one, so a later re-offer sends the right one. Elsewhere,
 * or once the by-value definitions grow past the cap, the state starts over
 * and that one request misses the cache.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/mid-conversation-system-messages
 *
 * @psalm-type ToolState = array{declared: list<array<string, mixed>>, available: list<string>, current?: array<string, array<string, mixed>>}
 */
final class ToolChanges {
    /**
     * By-value definitions kept in a conversation's state before it starts
     * over. The API refuses more than 4 MiB of definitions sent after the
     * first user message, or 10,000 such tools (`available_tools_limit_exceeded`);
     * staying well inside means the conversation never gets stuck on it.
     */
    public const MAX_BY_VALUE_BYTES = 1048576;
    public const MAX_BY_VALUE_TOOLS = 1000;

    /**
     * Plan one turn: what to send as `tools`, which change blocks to append,
     * and the state to keep for the next turn.
     *
     * @param ToolState|null $state the conversation's state, null when it has none
     * @param list<array<string, mixed>> $current the tools available now
     * @param bool $byValue whether the request may carry definitions by value (inline-tools-2026-09-15)
     * @return array{tools: list<array<string, mixed>>, blocks: list<array<string, mixed>>, state: ToolState, reset: bool}
     */
    public static function plan(?array $state, array $current, bool $byValue = false): array {
        $byName = [];
        foreach ($current as $tool) {
            $byName[(string)($tool['name'] ?? '')] = $tool;
        }

        /** @var ToolState $fresh */
        $fresh = ['declared' => $current, 'available' => array_keys($byName)];
        if ($state === null) {
            return ['tools' => $current, 'blocks' => [], 'state' => $fresh, 'reset' => false];
        }
        $overrides = $state['current'] ?? [];
        $startOver = $byValue
            ? !self::withinCap($overrides, $state['declared'], $byName)
            // A history with by-value definitions cannot go to a request
            // without them, and a reference cannot redefine a tool.
            : $overrides !== [] || self::anyRedefined($state['declared'], [], $byName);
        if ($startOver) {
            return ['tools' => $current, 'blocks' => [], 'state' => $fresh, 'reset' => true];
        }

        $declared = $state['declared'];
        $available = $state['available'];
        $declaredByName = [];
        foreach ($declared as $tool) {
            $copy = $tool;
            unset($copy['defer_loading']);
            $declaredByName[(string)($tool['name'] ?? '')] = $copy;
        }
        $blocks = [];
        foreach ($available as $name) {
            if (!isset($byName[$name])) {
                $blocks[] = ['type' => 'tool_removal', 'tool' => ['type' => 'tool_reference', 'name' => $name]];
            }
        }
        $isAvailable = array_fill_keys($available, true);
        foreach ($byName as $name => $tool) {
            $live = $overrides[$name] ?? $declaredByName[$name] ?? null;
            $same = $live !== null && json_encode($live) === json_encode($tool);
            if (isset($isAvailable[$name]) && $same) {
                continue;
            }
            if ($live === null) {
                // New: declared at the end, deferred, so the prefix is untouched.
                $declared[] = $tool + ['defer_loading' => true];
                $declaredByName[$name] = $tool;
                $blocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => $name]];
            } elseif ($same && !isset($overrides[$name])) {
                $blocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => $name]];
            } else {
                // Redefined now, or earlier: a reference names the declared
                // tool, so a tool with an override is always offered by value.
                // The new definition replaces the old one from here on.
                $blocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_definition', 'definition' => $tool]];
                $overrides[$name] = $tool;
            }
            if (isset($overrides[$name]) && json_encode($overrides[$name]) === json_encode($declaredByName[$name])) {
                unset($overrides[$name]);
            }
        }

        /** @var ToolState $next */
        $next = ['declared' => $declared, 'available' => array_keys($byName)];
        if ($overrides !== []) {
            $next['current'] = $overrides;
        }
        return ['tools' => $declared, 'blocks' => $blocks, 'state' => $next, 'reset' => false];
    }

    /**
     * Whether the by-value definitions after this turn stay inside the cap:
     * the ones kept so far plus the ones this turn would add.
     *
     * @param array<string, array<string, mixed>> $overrides
     * @param list<array<string, mixed>> $declared
     * @param array<string, array<string, mixed>> $current
     */
    private static function withinCap(array $overrides, array $declared, array $current): bool {
        $changed = $overrides;
        foreach ($declared as $tool) {
            $name = (string)($tool['name'] ?? '');
            unset($tool['defer_loading']);
            $live = $overrides[$name] ?? $tool;
            if (isset($current[$name]) && json_encode($live) !== json_encode($current[$name])) {
                $changed[$name] = $current[$name];
            }
        }
        return count($changed) <= self::MAX_BY_VALUE_TOOLS
            && strlen((string)json_encode(array_values($changed))) <= self::MAX_BY_VALUE_BYTES;
    }

    /**
     * The stored state. Decoding into PHP arrays turns each `{}` in a declared
     * tool's `input_schema` into `[]`; it is restored here (see JsonShape), so
     * the declared tools resend byte-identical and anyRedefined() compares
     * them with the current ones like for like.
     *
     * @return ToolState|null
     */
    public static function decodeState(?string $stored): ?array {
        $decoded = $stored !== null && $stored !== '' ? json_decode($stored, true) : null;
        if (!is_array($decoded) || !is_array($decoded['declared'] ?? null) || !is_array($decoded['available'] ?? null)) {
            return null;
        }
        $declared = [];
        foreach ($decoded['declared'] as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            if (array_key_exists('input_schema', $tool)) {
                $tool['input_schema'] = JsonShape::toolInputSchema($tool['input_schema']);
            }
            $declared[] = $tool;
        }
        $state = ['declared' => $declared, 'available' => array_values(array_map('strval', $decoded['available']))];
        if (is_array($decoded['current'] ?? null)) {
            $current = [];
            foreach ($decoded['current'] as $name => $tool) {
                if (is_array($tool)) {
                    if (array_key_exists('input_schema', $tool)) {
                        $tool['input_schema'] = JsonShape::toolInputSchema($tool['input_schema']);
                    }
                    $current[(string)$name] = $tool;
                }
            }
            if ($current !== []) {
                $state['current'] = $current;
            }
        }
        /** @var ToolState */
        return $state;
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
        if (self::isByValue($block)) {
            return sprintf('The tool %s changed from here on.', (string)($block['tool']['definition']['name'] ?? 'a tool'));
        }
        $name = (string)($block['tool']['name'] ?? 'a tool');
        return ($block['type'] ?? null) === 'tool_removal'
            ? sprintf('The tool %s is no longer available.', $name)
            : sprintf('The tool %s is available from here on.', $name);
    }

    /** @param mixed $block */
    public static function isByValue($block): bool {
        return is_array($block)
            && ($block['type'] ?? null) === 'tool_addition'
            && ($block['tool']['type'] ?? null) === 'tool_definition';
    }

    /**
     * Whether a request carries a tool definition by value, so it needs
     * `inline-tools-2026-09-15` (which also covers the by-reference changes).
     * Later requests replay the block, so they need it too.
     */
    public static function needsInlineTools(array $params): bool {
        foreach ($params['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) !== 'system' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (self::isByValue($block)) {
                    return true;
                }
            }
        }
        return false;
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
     * Whether any tool's live definition (its override, else as declared)
     * differs from the one available now.
     *
     * @param list<array<string, mixed>> $declared
     * @param array<string, array<string, mixed>> $overrides
     * @param array<string, array<string, mixed>> $current
     */
    private static function anyRedefined(array $declared, array $overrides, array $current): bool {
        foreach ($declared as $tool) {
            $name = (string)($tool['name'] ?? '');
            if (!isset($current[$name])) {
                continue;
            }
            unset($tool['defer_loading']);
            if (json_encode($overrides[$name] ?? $tool) !== json_encode($current[$name])) {
                return true;
            }
        }
        return false;
    }
}
