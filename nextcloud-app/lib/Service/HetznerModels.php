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

    /** DeepSeek-V4-Flash MoE, 304B total / 13B active, text only, 512k context. */
    public const DEEPSEEK_V4_FLASH = 'DeepSeek-V4-Flash-0731';

    /** GLM-5.2 MoE (NVFP4), 744B total / 40B active, text only, 512k context. */
    public const GLM_5_2_NVFP4 = 'GLM-5.2-NVFP4';

    /** Kimi K2.7 Code MoE, 1T total / 32B active, text + vision, 262k context. */
    public const KIMI_K2_7_CODE = 'Kimi-K2.7-Code';

    // ── Application defaults ────────────────────────────────────────────────

    public const DEFAULT_MODEL      = self::QWEN3_6_35B;
    public const DEFAULT_MAX_TOKENS = 8192;

    // ── Per-model output token ceilings ─────────────────────────────────────

    private const MAX_TOKENS_CEILING = [
        self::QWEN3_6_35B       => 32768,
        self::DEEPSEEK_V4_FLASH => 65536,
        self::GLM_5_2_NVFP4     => 65536,
        self::KIMI_K2_7_CODE    => 32768,
    ];

    /**
     * Models known to be text-only. Everything else is treated as vision
     * capable, so a model added to the service after this release is not
     * crippled by a registry that has not caught up with it.
     *
     * @var list<string>
     */
    private const TEXT_ONLY = [
        self::DEEPSEEK_V4_FLASH,
        self::GLM_5_2_NVFP4,
    ];

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
            self::KIMI_K2_7_CODE,
            self::DEEPSEEK_V4_FLASH,
            self::GLM_5_2_NVFP4,
        ];
    }
}
