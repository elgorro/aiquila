<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\IConfig;

/**
 * Resolves the active LLM provider for the chat experience.
 *
 * Precedence: a per-user override (`user_provider`) wins over the admin default
 * (`provider`), which defaults to Anthropic. Unknown ids fall back to Anthropic
 * so a misconfiguration never breaks chat.
 */
class LLMProviderFactory {
    private const APP_NAME = 'aiquila';
    public const DEFAULT_PROVIDER = 'anthropic';

    public function __construct(
        private readonly IConfig $config,
        private readonly ClaudeSDKService $anthropic,
        private readonly MistralProvider $mistral,
    ) {
    }

    /** @return array<string, LLMProviderInterface> */
    private function providers(): array {
        return [
            $this->anthropic->getId() => $this->anthropic,
            $this->mistral->getId() => $this->mistral,
        ];
    }

    /** Stable list of provider ids in display order. */
    public function getProviderIds(): array {
        return array_keys($this->providers());
    }

    /**
     * The provider id that should serve the given user.
     */
    public function getActiveProviderId(?string $userId = null): string {
        $providers = $this->providers();

        if ($userId !== null) {
            $userProvider = $this->config->getUserValue($userId, self::APP_NAME, 'user_provider', '');
            if ($userProvider !== '' && isset($providers[$userProvider])) {
                return $userProvider;
            }
        }

        $adminDefault = $this->config->getAppValue(self::APP_NAME, 'provider', self::DEFAULT_PROVIDER);
        return isset($providers[$adminDefault]) ? $adminDefault : self::DEFAULT_PROVIDER;
    }

    /**
     * The provider that should serve the given user.
     */
    public function getProvider(?string $userId = null): LLMProviderInterface {
        return $this->getProviderById($this->getActiveProviderId($userId));
    }

    /**
     * Look up a specific provider by id (falls back to Anthropic).
     */
    public function getProviderById(string $id): LLMProviderInterface {
        return $this->providers()[$id] ?? $this->anthropic;
    }

    /**
     * Metadata for settings UIs: each provider's id, label, and whether a key
     * is configured (user or admin scope) for the given user.
     *
     * @return list<array{id: string, label: string, configured: bool}>
     */
    public function describeProviders(?string $userId = null): array {
        $out = [];
        foreach ($this->providers() as $provider) {
            $out[] = [
                'id' => $provider->getId(),
                'label' => $provider->getLabel(),
                'configured' => $provider->isConfigured($userId),
            ];
        }
        return $out;
    }
}
