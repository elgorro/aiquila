<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\DeepSeekModels;

/**
 * DeepSeek provider (https://api.deepseek.com/v1, OpenAI-compatible).
 *
 * All wire-format handling lives in AbstractOpenAiCompatibleProvider; this class
 * supplies identity, the base URL, the model registry and the model catalog.
 * DeepSeek specifics:
 *   - Image input where the model takes it (deepseek-flash), per the registry.
 *   - No native MCP connector path (supportsNativeMcp() === false, the default).
 *   - Thinking models stream a separate `reasoning_content` field (dropped by
 *     the base reader) and get no sampling params.
 *   - Its /models entries carry name, modalities and limits, so it has its own
 *     catalog; the generic OpenAI-compatible listing stays ids only.
 */
class DeepSeekProvider extends AbstractOpenAiCompatibleProvider implements ModelCatalogInterface {
    private const PROVIDER_ID = 'deepseek';
    private const API_BASE = 'https://api.deepseek.com/v1';

    public function getId(): string {
        return self::PROVIDER_ID;
    }

    public function getLabel(): string {
        return 'DeepSeek';
    }

    protected function apiBase(): string {
        return self::API_BASE;
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_deepseek', '');
            if ($userModel !== '') {
                return DeepSeekModels::resolveModel($userModel);
            }
        }
        return DeepSeekModels::resolveModel($this->config->getAppValue(self::APP_NAME, 'model_deepseek', DeepSeekModels::DEFAULT_MODEL));
    }

    protected function supportsVisionInput(?string $userId = null): bool {
        return DeepSeekModels::supportsVision($this->getModel($userId));
    }

    public function listModels(?string $userId = null): ?array {
        if (!$this->isConfigured($userId)) {
            return null;
        }
        $catalog = $this->fetchModelCatalog($userId);
        $ids = $catalog->isFailure() ? [] : $catalog->ids();
        return $ids !== [] ? $ids : null;
    }

    public function fetchModelCatalog(?string $userId = null): ModelCatalog {
        if (!$this->isConfigured($userId)) {
            return ModelCatalog::failed($this->getLabel(), 'No DeepSeek API key configured.');
        }
        try {
            $items = $this->fetchModelItems($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila DeepSeek: Could not list models', ['error' => $e->getMessage()]);
            return ModelCatalog::failed($this->getLabel(), $this->errorMessage($e));
        }
        return self::deepSeekCatalog($items, $this->getLabel());
    }

    public function assessUnlistedModel(string $model): ?ModelCompatibility {
        return null;
    }

    /**
     * Verdicts from DeepSeek's list-models entries
     * (https://api-docs.deepseek.com/api/list-models): `name` labels it,
     * `output_modalities` without `text` cannot chat, `input_modalities`
     * decides image input. Every field but `id` may be omitted, so an entry
     * with no metadata falls back to the registry, or is "not verified" when
     * the registry does not know it either. Tool calling has no flag; the
     * documented models all support it.
     *
     * The discontinued names are kept findable, so a conversation pinned to
     * one says what happened rather than "not in the list".
     *
     * @param list<array<array-key, mixed>> $items
     */
    public static function deepSeekCatalog(array $items, string $providerLabel): ModelCatalog {
        $models = [];
        foreach ($items as $item) {
            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            if ($id === '') {
                continue;
            }
            $label = is_string($item['name'] ?? null) ? $item['name'] : '';
            $inputs = is_array($item['input_modalities'] ?? null) ? $item['input_modalities'] : null;
            $outputs = is_array($item['output_modalities'] ?? null) ? $item['output_modalities'] : null;
            if ($outputs !== null && !in_array('text', $outputs, true)) {
                $models[] = ModelCompatibility::cannotChat($id, $label, '', DeepSeekModels::DEFAULT_MODEL);
            } elseif ($inputs !== null) {
                $models[] = ModelCompatibility::chat($id, $label, null, in_array('image', $inputs, true));
            } elseif (in_array($id, DeepSeekModels::getAllModels(), true)) {
                $models[] = ModelCompatibility::chat($id, $label, null, DeepSeekModels::supportsVision($id));
            } else {
                $models[] = ModelCompatibility::unverified($id, $label, 'DeepSeek listed it without its modalities, and AIquila does not know it.');
            }
        }
        $listed = array_map(static fn(ModelCompatibility $m) => $m->id, $models);
        $hidden = [];
        foreach ([DeepSeekModels::LEGACY_CHAT, DeepSeekModels::LEGACY_REASONER] as $legacy) {
            if (!in_array($legacy, $listed, true)) {
                $hidden[] = ModelCompatibility::retired($legacy, '', '2026-07-24', DeepSeekModels::resolveModel($legacy));
            }
        }
        return ModelCatalog::listed($models, 'deepseek-models', $providerLabel, '', $hidden);
    }

    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_deepseek', (string)DeepSeekModels::DEFAULT_MAX_TOKENS);
        return min($stored, DeepSeekModels::getMaxTokenCeiling($this->getModel($userId)));
    }

    protected function defaultModel(): string {
        return DeepSeekModels::DEFAULT_MODEL;
    }

    protected function defaultMaxTokens(): int {
        return DeepSeekModels::DEFAULT_MAX_TOKENS;
    }

    /** Reasoning models reject sampling params; only forward them otherwise. */
    protected function applySamplingParams(array &$body, string $model, array $options): void {
        if (DeepSeekModels::isReasoner($model)) {
            return;
        }
        parent::applySamplingParams($body, $model, $options);
    }
}
