<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Mistral model registry.
 *
 * Static fallback list + per-model output ceilings, mirroring ClaudeModels.
 * MistralProvider prefers the live /v1/models listing and only falls back here.
 *
 * ## Why dated IDs, not `-latest` aliases
 *
 * This registry used to pin `mistral-large-latest`, `pixtral-large-latest` and
 * friends on the assumption that aliases stay current by themselves. They do
 * not survive a family being retired: `pixtral-large-2411` was retired on
 * 2026-05-31 and `ministral-8b-2410` on 2025-12-31, which left the vision
 * fallback pointing at a model the API no longer serves. Dated IDs fail loudly
 * against Mistral's published deprecation table instead of silently, and the
 * live /v1/models call already covers "what is actually available today".
 *
 * ## Reasoning
 *
 * Mistral Small 4 and Medium 3.5 are hybrid reasoning models driven by the
 * `reasoning_effort` request parameter (`none` / `high`) — see ALLOWED_EFFORTS.
 * There is no thinking *budget*, so those models map onto the app's `effort`
 * capability, not `thinking`/`thinkingBudget`.
 */
class MistralModels {

    // ── Model ID constants ──────────────────────────────────────────────────

    /** Mistral Large 3 — flagship, 256k context, multimodal. */
    public const LARGE = 'mistral-large-2512';

    /** Mistral Medium 3.5 — frontier-class multimodal, hybrid reasoning. */
    public const MEDIUM = 'mistral-medium-3505';

    /** Mistral Small 4 — cost-effective multimodal, hybrid reasoning. */
    public const SMALL = 'mistral-small-2603';

    /** Ministral 3 14B — compact multimodal. */
    public const MINISTRAL_14B = 'ministral-3-14b-2512';

    /** Ministral 3 8B — efficient multimodal. */
    public const MINISTRAL_8B = 'ministral-3-8b-2512';

    /** Ministral 3 3B — smallest multimodal. */
    public const MINISTRAL_3B = 'ministral-3-3b-2512';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL = self::SMALL;
    public const DEFAULT_MAX_TOKENS = 8192;

    /**
     * Model used when the configured one cannot accept image input. Every
     * current generalist model is multimodal, so this fallback rarely fires;
     * it exists for models the live listing surfaces that we do not know.
     */
    public const VISION_MODEL = self::SMALL;

    // ── Per-model output token ceilings ─────────────────────────────────────

    /**
     * Mistral publishes context windows, not output ceilings, so these stay at
     * the conservative values the app has always used rather than being
     * invented from the context size.
     */
    private const MAX_TOKENS_CEILING = [
        self::LARGE         => 131072,
        self::MEDIUM        => 131072,
        self::SMALL         => 32768,
        self::MINISTRAL_14B => 32768,
        self::MINISTRAL_8B  => 32768,
        self::MINISTRAL_3B  => 32768,
    ];

    /** Models capable of image (vision) input. */
    private const VISION_MODELS = [
        self::LARGE         => true,
        self::MEDIUM        => true,
        self::SMALL         => true,
        self::MINISTRAL_14B => true,
        self::MINISTRAL_8B  => true,
        self::MINISTRAL_3B  => true,
    ];

    /**
     * Families whose members are all multimodal. Lets a newer dated ID coming
     * back from the live /v1/models listing be recognised before this registry
     * catches up.
     */
    private const VISION_PREFIXES = [
        'mistral-large-',
        'mistral-medium-',
        'mistral-small-',
        'ministral-3-',
    ];

    // ── Reasoning effort ────────────────────────────────────────────────────

    /**
     * Every `reasoning_effort` value any model accepts; used for settings
     * validation. `high` returns a thinking chunk ahead of the answer, `none`
     * suppresses it.
     */
    public const ALL_EFFORTS = ['none', 'high'];

    /** Models that accept `reasoning_effort`; others reject it with a 400. */
    private const ALLOWED_EFFORTS = [
        self::SMALL  => self::ALL_EFFORTS,
        self::MEDIUM => self::ALL_EFFORTS,
    ];

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Falls back to the app default for unknown IDs.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Whether a model accepts image input.
     */
    public static function supportsVision(string $model): bool {
        if (self::VISION_MODELS[$model] ?? false) {
            return true;
        }
        foreach (self::VISION_PREFIXES as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * `reasoning_effort` values the API accepts for a model; empty if the model
     * has no reasoning mode.
     *
     * @return list<string>
     */
    public static function getAllowedEfforts(string $model): array {
        return self::ALLOWED_EFFORTS[$model] ?? [];
    }

    public static function isAllowedEffort(string $model, string $effort): bool {
        return in_array($effort, self::getAllowedEfforts($model), true);
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
            self::MEDIUM,
            self::SMALL,
            self::MINISTRAL_14B,
            self::MINISTRAL_8B,
            self::MINISTRAL_3B,
        ];
    }
}
