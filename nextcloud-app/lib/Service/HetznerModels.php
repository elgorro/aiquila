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
 * fallback shown when that call fails (no key yet, endpoint down) and the
 * output-token ceilings used to clamp the configured max_tokens.
 */
class HetznerModels {

    // ── Model ID constants ──────────────────────────────────────────────────

    /** Qwen3.6 MoE, causal LM + vision, 262k context. */
    public const QWEN3_6_35B = 'Qwen/Qwen3.6-35B-A3B-FP8';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::QWEN3_6_35B;
    public const DEFAULT_MAX_TOKENS = 8192;

    // ── Per-model output token ceilings ─────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::QWEN3_6_35B => 32768,
    ];

    /**
     * Maximum output token ceiling for a model; used to clamp the configured
     * max_tokens value. Unknown IDs (the list changes) get the app default.
     */
    public static function getMaxTokenCeiling(string $model): int {
        return self::MAX_TOKENS_CEILING[$model] ?? self::DEFAULT_MAX_TOKENS;
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
        ];
    }
}
