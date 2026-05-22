<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

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

    /** Opus 4.7 – most capable frontier model (adaptive thinking, 128K output, 1M context, xhigh effort) */
    public const OPUS_4_7   = 'claude-opus-4-7';

    /** Opus 4.6 – frontier model (adaptive thinking, 128K output) */
    public const OPUS_4_6   = 'claude-opus-4-6';

    /** Sonnet 4.6 – adaptive thinking, 64K output */
    public const SONNET_4_6 = 'claude-sonnet-4-6';

    /** Sonnet 4.5 – recommended default: fast, capable, cost-effective */
    public const SONNET_4_5 = 'claude-sonnet-4-5-20250929';

    /** Haiku 4.5 – fastest, most economical */
    public const HAIKU_4_5  = 'claude-haiku-4-5-20251001';

    /** Opus 4.5 */
    public const OPUS_4_5   = 'claude-opus-4-5-20251101';

    /** Sonnet 4 — retired June 15 2026; resolveModel() transparently maps this to SONNET_4_6. */
    public const SONNET_4   = 'claude-sonnet-4-20250514';

    /** Opus 4 — retired June 15 2026; resolveModel() transparently maps this to OPUS_4_7. */
    public const OPUS_4     = 'claude-opus-4-20250514';

    // ── Deprecated-model migration map ────────────────────────────────────

    private const DEPRECATED_MAP = [
        self::SONNET_4 => self::SONNET_4_6,
        self::OPUS_4   => self::OPUS_4_7,
    ];

    /**
     * Return the active replacement for a deprecated model ID, or the model
     * itself if it is already active. Call this whenever a model ID is read
     * from stored user/admin config before it is used in an API request.
     */
    public static function resolveModel(string $model): string {
        return self::DEPRECATED_MAP[$model] ?? $model;
    }

    // ── Application defaults ───────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::SONNET_4_6;
    public const DEFAULT_MAX_TOKENS = 16384;

    // ── Per-model output token ceilings ────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::OPUS_4_7   => 128000,
        self::OPUS_4_6   => 128000,
        self::SONNET_4_6 => 64000,
    ];

    // ── Capability flags ───────────────────────────────────────────────────

    private const SUPPORTS_THINKING = [
        self::OPUS_4_7   => true,
        self::OPUS_4_6   => true,
        self::SONNET_4_6 => true,
    ];

    private const SUPPORTS_EFFORT = [
        self::OPUS_4_7   => true,
        self::OPUS_4_6   => true,
        self::SONNET_4_6 => true,
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
     * SONNET_4 / OPUS_4 are intentionally omitted — Anthropic deprecated them
     * and the SDK removed them from its typed Model enum in 0.15.0.
     */
    public static function getAllModels(): array {
        return [
            self::OPUS_4_7,
            self::OPUS_4_6,
            self::SONNET_4_6,
            self::SONNET_4_5,
            self::HAIKU_4_5,
            self::OPUS_4_5,
        ];
    }

    // ── Per-model effort level (app-level policy) ────────────────────────

    public const EFFORT_LEVEL = [
        self::OPUS_4_7   => 'xhigh',
        self::OPUS_4_6   => 'high',
        self::SONNET_4_6 => 'medium',
    ];

    /**
     * Default effort level for a model (app-level policy, not SDK data).
     */
    public static function getEffortLevel(string $model): string {
        return self::EFFORT_LEVEL[$model] ?? 'medium';
    }
}
