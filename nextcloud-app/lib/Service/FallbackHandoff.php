<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Server-side fallback (`fallbacks: "default"`, beta
 * `server-side-fallback-2026-07-01`) marks each point where a declining
 * model hands the turn to a fallback model with a `fallback` content block.
 *
 * What may be echoed on the next request depends on which side of the
 * *final* `fallback` block a content block sits:
 *
 *   - `fallback`: kept, exactly where it appeared. The API uses its position
 *     to validate the thinking blocks around it.
 *   - `text`: kept on either side.
 *   - `thinking`, `redacted_thinking`, client `tool_use` before the final
 *     `fallback`: dropped. A tool call from the model that declined is not
 *     part of the turn and must not be run either.
 *
 * Only streaming responses carry blocks before the handoff. A non-streaming
 * response omits the declined model's partial output and starts with the
 * `fallback` block.
 *
 * The GA SDK types have no `fallback` variant and coerce the block into
 * another class with `type` still set to `fallback`, so blocks are told
 * apart by `type` alone and read back through their raw properties.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/refusals-and-fallback#continuing-the-conversation
 */
final class FallbackHandoff {
    private const DROPPED_BEFORE_HANDOFF = ['thinking', 'redacted_thinking', 'tool_use'];

    /**
     * $blocks with everything the declined model produced before the final
     * handoff removed, except text. Keys are reindexed; order is kept.
     *
     * @template T of object|array
     * @param array<array-key, T> $blocks response content blocks (SDK objects or arrays)
     * @return list<T>
     */
    public static function afterHandoff(array $blocks): array {
        $blocks = array_values($blocks);
        $last = null;
        foreach ($blocks as $i => $block) {
            if (self::type($block) === 'fallback') {
                $last = $i;
            }
        }
        if ($last === null) {
            return $blocks;
        }
        $kept = [];
        foreach ($blocks as $i => $block) {
            if ($i < $last && in_array(self::type($block), self::DROPPED_BEFORE_HANDOFF, true)) {
                continue;
            }
            $kept[] = $block;
        }
        return $kept;
    }

    /**
     * A `fallback` block as a request param, or null for any other block.
     *
     * @return array{type: 'fallback', from: mixed, to: mixed, trigger: mixed}|null
     */
    public static function toParam(object|array $block): ?array {
        if (self::type($block) !== 'fallback') {
            return null;
        }
        $raw = self::raw($block);
        return [
            'type' => 'fallback',
            'from' => $raw['from'] ?? null,
            'to' => $raw['to'] ?? null,
            'trigger' => $raw['trigger'] ?? null,
        ];
    }

    /**
     * The model a `fallback` block handed the turn to, or null.
     */
    public static function servedBy(object|array $block): ?string {
        $param = self::toParam($block);
        $to = $param['to'] ?? null;
        $model = is_array($to) ? ($to['model'] ?? null) : null;
        return is_string($model) ? $model : null;
    }

    /**
     * Whether a fallback model served $response: a `fallback_message` entry
     * in `usage.iterations` (the documented signal, which also covers sticky
     * routing, where no `fallback` block appears), or a `fallback` block in a
     * response assembled from a stream.
     */
    public static function servedByFallback(object $response): bool {
        $usage = $response->usage ?? null;
        $iterations = is_object($usage) ? (self::raw($usage)['iterations'] ?? null) : null;
        if (is_array($iterations)) {
            foreach ($iterations as $entry) {
                if (self::type(is_object($entry) || is_array($entry) ? $entry : []) === 'fallback_message') {
                    return true;
                }
            }
        }
        $content = $response->content ?? null;
        foreach (is_iterable($content) ? $content : [] as $block) {
            if ((is_object($block) || is_array($block)) && self::type($block) === 'fallback') {
                return true;
            }
        }
        return false;
    }

    public static function type(object|array $block): ?string {
        $type = is_array($block) ? ($block['type'] ?? null) : ($block->type ?? null);
        return is_string($type) ? $type : null;
    }

    /**
     * Raw properties of a block, including keys the SDK class does not
     * declare.
     *
     * @return array<string, mixed>
     */
    private static function raw(object|array $block): array {
        if (is_array($block)) {
            return $block;
        }
        if (method_exists($block, 'toProperties')) {
            /** @var array<string, mixed> */
            return $block->toProperties();
        }
        /** @var array<string, mixed> */
        return json_decode((string)json_encode($block), true) ?: [];
    }
}
