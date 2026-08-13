<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\DeepSeekModels;

/**
 * DeepSeek provider (https://api.deepseek.com/v1, OpenAI-compatible).
 *
 * All wire-format handling lives in AbstractOpenAiCompatibleProvider; this class
 * only supplies identity, the base URL, and the model registry. DeepSeek
 * specifics:
 *   - No vision models, so image input is rejected (the base default).
 *   - No native MCP connector path (supportsNativeMcp() === false, the default).
 *   - The `deepseek-reasoner` model streams a separate `reasoning_content` field
 *     (dropped by the base reader) and rejects sampling params.
 */
class DeepSeekProvider extends AbstractOpenAiCompatibleProvider {
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
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_deepseek', DeepSeekModels::DEFAULT_MODEL);
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
