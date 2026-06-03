<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Mistral model registry.
 *
 * Static fallback list + per-model output ceilings, mirroring ClaudeModels.
 * MistralProvider prefers the live /v1/models listing and only falls back here.
 * Mistral models have no "thinking"/"effort" knobs, so those capabilities are
 * intentionally absent.
 */
class MistralModels {

    // ── Model ID constants (latest aliases stay current automatically) ──────

    /** Most capable text model. */
    public const LARGE = 'mistral-large-latest';

    /** Cost-effective general model. */
    public const SMALL = 'mistral-small-latest';

    /** Multimodal (vision) model. */
    public const PIXTRAL = 'pixtral-large-latest';

    /** Fast/cheap model. */
    public const MINISTRAL = 'ministral-8b-latest';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::SMALL;
    public const DEFAULT_MAX_TOKENS = 8192;

    // ── Per-model output token ceilings ─────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::LARGE     => 131072,
        self::SMALL     => 32768,
        self::PIXTRAL   => 131072,
        self::MINISTRAL => 32768,
    ];

    /** Models capable of image (vision) input. */
    private const VISION_MODELS = [
        self::PIXTRAL => true,
    ];

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Falls back to the app default for unknown IDs.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Whether a model accepts image input. Latest-suffixed pixtral variants and
     * any model whose id contains "pixtral" are treated as vision-capable.
     */
    public static function supportsVision(string $model): bool {
        return (self::VISION_MODELS[$model] ?? false) || str_contains($model, 'pixtral');
    }

    /**
     * Ordered model list for the settings UI (most capable first). Used as a
     * fallback when the live /v1/models call fails.
     *
     * @return list<string>
     */
    public static function getAllModels(): array {
        return [
            self::LARGE,
            self::PIXTRAL,
            self::SMALL,
            self::MINISTRAL,
        ];
    }
}
