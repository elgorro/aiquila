<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * DeepSeek model registry.
 *
 * Static fallback list + per-model output ceilings, mirroring MistralModels.
 * DeepSeekProvider prefers the live /v1/models listing and only falls back here.
 * DeepSeek exposes two models: a general chat model and a reasoning model that
 * streams a separate `reasoning_content` field. Neither supports image input.
 */
class DeepSeekModels {

    // ── Model ID constants ──────────────────────────────────────────────────

    /** General-purpose chat model (DeepSeek-V3 family). */
    public const CHAT = 'deepseek-chat';

    /** Reasoning model that emits `reasoning_content` (DeepSeek-R1 family). */
    public const REASONER = 'deepseek-reasoner';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::CHAT;
    public const DEFAULT_MAX_TOKENS = 8192;

    // ── Per-model output token ceilings ─────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::CHAT     => 8192,
        self::REASONER => 8192,
    ];

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Falls back to the app default for unknown IDs.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Whether a model is a reasoning model. Reasoning models stream a separate
     * `reasoning_content` delta and do not accept sampling params like
     * temperature/top_p.
     */
    public static function isReasoner(string $model): bool {
        return $model === self::REASONER || str_contains($model, 'reasoner');
    }

    /**
     * Ordered model list for the settings UI. Used as a fallback when the live
     * /v1/models call fails.
     *
     * @return list<string>
     */
    public static function getAllModels(): array {
        return [
            self::CHAT,
            self::REASONER,
        ];
    }
}
