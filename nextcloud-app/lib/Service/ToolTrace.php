<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * The tool calls of one assistant answer, kept with the answer so that later
 * turns can see what the tools returned instead of only the prose built on it.
 *
 * A trace is the list of messages a tool loop appended between the user's
 * prompt and the final answer: assistant turns carrying `tool_use` blocks and
 * user turns carrying their `tool_result` blocks, in the canonical Anthropic
 * block shape every provider translates from (see LLMProviderInterface).
 *
 * What is kept, and why:
 *
 *  - Only `text`, `tool_use` and `tool_result` blocks. Thinking may be left
 *    out of completed earlier turns, and its signatures would not survive a
 *    model or provider change anyway; `fallback` blocks only position thinking.
 *  - Tool ids are renamed to nine alphanumerics derived from the original:
 *    Mistral accepts only that form, Anthropic and the OpenAI-compatible APIs
 *    accept it too, so the history replays on whichever provider answers next.
 *  - Each tool result is cut to MAX_RESULT_CHARS when stored. Every later
 *    request resends the trace, so this bounds what one tool call can cost
 *    from then on. The cut happens once, at storage: the replayed bytes never
 *    change afterwards, so the cached prompt prefix keeps matching.
 *
 * A request that defines no tools (plain chat, the native MCP connector)
 * cannot carry `tool_use` blocks; flatten() turns them into labelled text.
 */
final class ToolTrace {
    public const MAX_RESULT_CHARS = 10000;

    /**
     * Normalise the messages a tool loop appended, for storage. Returns null
     * when there is nothing worth keeping.
     *
     * @param array<array-key, mixed> $messages
     */
    public static function encode(array $messages): ?string {
        $ids = [];
        $kept = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (($role !== 'user' && $role !== 'assistant') || !is_array($content)) {
                continue;
            }
            $blocks = [];
            foreach ($content as $block) {
                $normalised = is_array($block) ? self::normaliseBlock($block, $ids) : null;
                if ($normalised !== null) {
                    $blocks[] = $normalised;
                }
            }
            if ($blocks !== []) {
                $kept[] = ['role' => $role, 'content' => $blocks];
            }
        }
        if (!self::hasToolUse($kept)) {
            return null;
        }
        $encoded = json_encode($kept);
        return $encoded === false ? null : $encoded;
    }

    /**
     * A stored trace, or an empty list when there is none or it is unreadable.
     *
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    public static function decode(?string $stored): array {
        if ($stored === null || $stored === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }
        $messages = [];
        foreach ($decoded as $message) {
            if (is_array($message) && is_string($message['role'] ?? null) && is_array($message['content'] ?? null)) {
                /** @var list<array<string, mixed>> $content */
                $content = array_values($message['content']);
                $messages[] = ['role' => $message['role'], 'content' => $content];
            }
        }
        return $messages;
    }

    /**
     * $messages with every `tool_use` and `tool_result` block turned into a
     * text block, for a request that defines no tools. What the tools did and
     * returned stays readable; nothing else changes.
     *
     * @param array<array-key, mixed> $messages
     * @return array<array-key, mixed>
     */
    public static function flatten(array $messages): array {
        $names = [];
        foreach ($messages as $i => $message) {
            if (!is_array($message) || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $j => $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;
                if ($type === 'tool_use') {
                    $names[(string)($block['id'] ?? '')] = (string)($block['name'] ?? 'tool');
                    $input = json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $messages[$i]['content'][$j] = ['type' => 'text', 'text' => sprintf('[Called tool %s with %s]', $names[(string)($block['id'] ?? '')], $input === false ? '{}' : $input)];
                } elseif ($type === 'tool_result') {
                    $name = $names[(string)($block['tool_use_id'] ?? '')] ?? 'tool';
                    $label = !empty($block['is_error']) ? 'Tool %s failed: %s' : 'Tool %s returned: %s';
                    $messages[$i]['content'][$j] = ['type' => 'text', 'text' => '[' . sprintf($label, $name, self::resultText($block['content'] ?? '')) . ']'];
                }
            }
        }
        return $messages;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, string> $ids original id => stored id, shared across the trace
     * @return array<string, mixed>|null
     */
    private static function normaliseBlock(array $block, array &$ids): ?array {
        switch ($block['type'] ?? null) {
            case 'text':
                $text = (string)($block['text'] ?? '');
                return $text === '' ? null : ['type' => 'text', 'text' => $text];
            case 'tool_use':
                return [
                    'type' => 'tool_use',
                    'id' => self::storedId((string)($block['id'] ?? ''), $ids),
                    'name' => (string)($block['name'] ?? ''),
                    'input' => is_array($block['input'] ?? null) && $block['input'] !== [] ? $block['input'] : new \stdClass(),
                ];
            case 'tool_result':
                $text = self::resultText($block['content'] ?? '');
                if (mb_strlen($text) > self::MAX_RESULT_CHARS) {
                    $text = mb_substr($text, 0, self::MAX_RESULT_CHARS)
                        . sprintf("\n… [%d more characters not kept]", mb_strlen($text) - self::MAX_RESULT_CHARS);
                }
                $result = [
                    'type' => 'tool_result',
                    'tool_use_id' => self::storedId((string)($block['tool_use_id'] ?? ''), $ids),
                    'content' => $text,
                ];
                if (!empty($block['is_error'])) {
                    $result['is_error'] = true;
                }
                return $result;
            default:
                return null;
        }
    }

    /**
     * Nine alphanumerics, deterministic per original id so a tool_use and its
     * tool_result still pair up.
     *
     * @param array<string, string> $ids
     */
    private static function storedId(string $original, array &$ids): string {
        return $ids[$original] ??= substr(hash('sha256', $original . "\0" . count($ids)), 0, 9);
    }

    private static function resultText(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text') {
                    $parts[] = (string)($part['text'] ?? '');
                }
            }
            return implode("\n", $parts);
        }
        return '';
    }

    /** @param list<array{role: string, content: list<array<string, mixed>>}> $messages */
    private static function hasToolUse(array $messages): bool {
        foreach ($messages as $message) {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    return true;
                }
            }
        }
        return false;
    }
}
