<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * DeepSeek model registry.
 *
 * Static fallback list + per-model limits, mirroring MistralModels.
 * DeepSeekProvider prefers the live `GET /models` listing, whose entries carry
 * modalities and limits, and only falls back here.
 *
 * The line-up per https://api-docs.deepseek.com/quick_start/pricing and the
 * change log (https://api-docs.deepseek.com/updates): `deepseek-flash`
 * (DeepSeek-V4.1-Flash, image input) and `deepseek-v4-pro` (text only). Both
 * think by default. The legacy `deepseek-chat` / `deepseek-reasoner` names were
 * discontinued on 2026-07-24; stored values are resolved to `deepseek-flash`.
 */
class DeepSeekModels {
    // ── Model ID constants ──────────────────────────────────────────────────

    /** DeepSeek-V4.1-Flash: 1M context, image input, thinking by default. */
    public const FLASH = 'deepseek-flash';

    /** DeepSeek-V4-Pro: 1M context, text only, thinking by default. */
    public const V4_PRO = 'deepseek-v4-pro';

    /** Discontinued 2026-07-24; pointed at V4 Flash's non-thinking mode until then. */
    public const LEGACY_CHAT = 'deepseek-chat';

    /** Discontinued 2026-07-24; pointed at V4 Flash's thinking mode until then. */
    public const LEGACY_REASONER = 'deepseek-reasoner';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::FLASH;
    public const DEFAULT_MAX_TOKENS = 8192;

    /** Discontinued names and the model they resolve to. */
    private const DISCONTINUED = [
        self::LEGACY_CHAT     => self::FLASH,
        self::LEGACY_REASONER => self::FLASH,
    ];

    // ── Per-model output token ceilings ─────────────────────────────────────

    /** `max_output_tokens` from the documented list-models response. */
    private const MAX_TOKENS_CEILING = [
        self::FLASH  => 393216,
        self::V4_PRO => 393216,
    ];

    private const VISION = [
        self::FLASH => true,
    ];

    /** Models whose thinking mode is on unless the request turns it off. */
    private const THINKS_BY_DEFAULT = [
        self::FLASH  => true,
        self::V4_PRO => true,
    ];

    /**
     * The model to call for a stored id: discontinued names map to their
     * successor, anything else is returned as is.
     */
    public static function resolveModel(string $model): string {
        return self::DISCONTINUED[$model] ?? $model;
    }

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Falls back to the app default for unknown IDs.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[self::resolveModel($model)] ?? self::DEFAULT_MAX_TOKENS;
    }

    /** Whether a model takes image input. Unknown ids are text-only. */
    public static function supportsVision(string $model): bool {
        return self::VISION[self::resolveModel($model)] ?? false;
    }

    /**
     * Whether a model thinks by default. Sampling params (temperature, top_p)
     * are not sent to those: thinking models are documented with fixed
     * sampling, and leaving them out never fails a request.
     */
    public static function isReasoner(string $model): bool {
        return isset(self::THINKS_BY_DEFAULT[self::resolveModel($model)]) || str_contains($model, 'reasoner');
    }

    /**
     * Ordered model list for the settings UI. Used as a fallback when the live
     * /models call fails.
     *
     * @return list<string>
     */
    public static function getAllModels(): array {
        return [
            self::FLASH,
            self::V4_PRO,
        ];
    }
}
