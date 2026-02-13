<?php

namespace OCA\AIquila\Service;

/**
 * Claude model registry.
 *
 * Single source of truth for all Claude model IDs, their output token
 * ceilings, and the extra API parameters each model requires.
 * Add new models here; no other file needs to know about model quirks.
 */
class ClaudeModels {

    // ── Model ID constants ─────────────────────────────────────────────────

    /** Opus 4.6 – latest frontier model (adaptive thinking, 128K output) */
    public const OPUS_4_6   = 'claude-opus-4-6';

    /** Sonnet 4.5 – recommended default: fast, capable, cost-effective */
    public const SONNET_4_5 = 'claude-sonnet-4-5-20250929';

    /** Haiku 4.5 – fastest, most economical */
    public const HAIKU_4_5  = 'claude-haiku-4-5-20251001';

    /** Opus 4.5 */
    public const OPUS_4_5   = 'claude-opus-4-5-20251101';

    /** Sonnet 4 (legacy) */
    public const SONNET_4   = 'claude-sonnet-4-20250514';

    /** Opus 4 (legacy) */
    public const OPUS_4     = 'claude-opus-4-20250514';

    // ── Application defaults ───────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::SONNET_4_5;
    public const DEFAULT_MAX_TOKENS = 4096;

    // ── Per-model output token ceilings ────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::OPUS_4_6 => 128000,
    ];

    // ── Capability flags ───────────────────────────────────────────────────

    private const SUPPORTS_THINKING = [
        self::OPUS_4_6 => true,
    ];

    private const SUPPORTS_EFFORT = [
        self::OPUS_4_6 => true,
    ];

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Maximum output token ceiling for a model.
     * Use to clamp the user-configured max_tokens value.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
    }

    public static function supportsThinking(string $model): bool {
        return self::SUPPORTS_THINKING[$model] ?? false;
    }

    public static function supportsEffort(string $model): bool {
        return self::SUPPORTS_EFFORT[$model] ?? false;
    }

    /**
     * Ordered model list for the admin UI datalist (most capable first).
     */
    public static function getAllModels(): array {
        return [
            self::OPUS_4_6,
            self::SONNET_4_5,
            self::HAIKU_4_5,
            self::OPUS_4_5,
            self::SONNET_4,
            self::OPUS_4,
        ];
    }

    /**
     * Returns extra API parameters to merge into a messages->create() call.
     *
     * Returns [] for models that need no special handling, so callers can
     * always use array_merge() unconditionally.
     *
     * @param string $effort 'high' | 'medium' | 'low' (Opus 4.6 only)
     */
    public static function getModelParams(string $model, string $effort = 'high'): array {
        $params = [];

        if (self::supportsThinking($model)) {
            $params['thinking'] = ['type' => 'adaptive'];
        }

        if (self::supportsEffort($model)) {
            $params['effort'] = $effort;
        }

        return $params;
    }
}
