<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Hetzner Inference model registry.
 *
 * The Hetzner Experiments inference service rotates its open-weight model
 * line-up, so the authoritative list is the live `GET /models` call in
 * AbstractOpenAiCompatibleProvider::listModels(). This class only supplies the
 * fallback shown when that call fails (no key yet, endpoint down, HTTP 429), the
 * output-token ceilings used to clamp the configured max_tokens, and which of
 * the served models accept image input.
 */
class HetznerModels {

    // ── Model ID constants ──────────────────────────────────────────────────

    /** Qwen3.6 MoE, 35B total / 3B active, causal LM + vision, 262k context. */
    public const QWEN3_6_35B = 'Qwen/Qwen3.6-35B-A3B-FP8';

    /** Qwen3.8 dense, 27B, causal LM + vision, 262k context. */
    public const QWEN3_8_27B = 'Qwen3.8-27B';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::QWEN3_6_35B;
    public const DEFAULT_MAX_TOKENS = 8192;

    // ── Per-model output token ceilings ─────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::QWEN3_6_35B => 32768,
        self::QWEN3_8_27B => 32768,
    ];

    /**
     * Models known to be text-only. Everything else is treated as vision
     * capable, so a model added to the service after this release is not
     * crippled by a registry that has not caught up with it.
     *
     * Currently empty: the text-only members of the line-up (DeepSeek-V4-Flash
     * and GLM-5.2) were withdrawn from Experiments on 2026-08-19. The mechanism
     * stays because their replacements may well be text-only again.
     *
     * @var list<string>
     */
    private const TEXT_ONLY = [];

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Unknown IDs (the list changes) get the app default.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
    }

    /**
     * Whether a model accepts image input. Unknown IDs default to true: the
     * line-up changes faster than this registry, and refusing images for a
     * model we simply have not heard of would be the worse failure — the API
     * rejects an unsupported modality on its own.
     */
    public static function supportsVision(string $model): bool {
        return !in_array($model, self::TEXT_ONLY, true);
    }

    /**
     * Ordered fallback model list for the settings UI, used only when the live
     * /models call fails. The field also accepts a free-typed model id.
     *
     * @return list<string>
     */
    public static function getAllModels(): array {
        return [
            self::QWEN3_6_35B,
            self::QWEN3_8_27B,
        ];
    }
}
