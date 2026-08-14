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
 *
 * ## Access control
 *
 * Every candidate at every level is run through ProviderAccessService, so an
 * admin's per-user/group rules apply to the user override, the instance default
 * and any pinned id alike. A denied candidate falls through to the next one
 * rather than being served; if nothing is left the resolution fails closed with
 * NoPermittedProviderException instead of quietly handing back Anthropic.
 *
 * A null user id means a system context (CLI, background job, admin settings)
 * and is never restricted.
 */
class LLMProviderFactory {
    private const APP_NAME = 'aiquila';
    public const DEFAULT_PROVIDER = 'anthropic';

    public function __construct(
        private readonly IConfig $config,
        private readonly ClaudeSDKService $anthropic,
        private readonly MistralProvider $mistral,
        private readonly DeepSeekProvider $deepseek,
        private readonly HetznerProvider $hetzner,
        private readonly LocalProvider $local,
        private readonly ProviderAccessService $access,
    ) {
    }

    /** @return array<string, LLMProviderInterface> */
    private function providers(): array {
        return [
            $this->anthropic->getId() => $this->anthropic,
            $this->mistral->getId() => $this->mistral,
            $this->deepseek->getId() => $this->deepseek,
            $this->hetzner->getId() => $this->hetzner,
            $this->local->getId() => $this->local,
        ];
    }

    /**
     * Stable list of provider ids in display order.
     *
     * @return list<string>
     */
    public function getProviderIds(): array {
        return array_keys($this->providers());
    }

    /**
     * The provider ids the given user is permitted to use, in display order.
     *
     * @return list<string>
     */
    public function getProviderIdsForUser(?string $userId = null): array {
        return $this->access->filterAllowed($this->getProviderIds(), $userId);
    }

    /** Whether the user is permitted at least one provider. */
    public function hasPermittedProvider(?string $userId = null): bool {
        return $this->getProviderIdsForUser($userId) !== [];
    }

    /** Whether the given user may use the named provider. */
    public function isAllowedForUser(string $id, ?string $userId = null): bool {
        return $this->isKnownProviderId($id) && $this->access->isAllowed($id, $userId);
    }

    /**
     * The provider id that should serve the given user.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getActiveProviderId(?string $userId = null): string {
        $permitted = $this->getProviderIdsForUser($userId);
        if ($permitted === []) {
            throw new NoPermittedProviderException($userId);
        }

        if ($userId !== null) {
            $userProvider = $this->config->getUserValue($userId, self::APP_NAME, 'user_provider', '');
            if ($userProvider !== '' && in_array($userProvider, $permitted, true)) {
                return $userProvider;
            }
        }

        $adminDefault = $this->config->getAppValue(self::APP_NAME, 'provider', self::DEFAULT_PROVIDER);
        if (in_array($adminDefault, $permitted, true)) {
            return $adminDefault;
        }

        // The instance default is denied for this user (or unknown): fall
        // through to the first provider they may actually use rather than
        // handing back one they cannot.
        if (in_array(self::DEFAULT_PROVIDER, $permitted, true)) {
            return self::DEFAULT_PROVIDER;
        }
        return $permitted[0];
    }

    /**
     * The provider that should serve the given user.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getProvider(?string $userId = null): LLMProviderInterface {
        return $this->getProviderById($this->getActiveProviderId($userId));
    }

    /**
     * The provider that should serve a specific request for a user.
     *
     * Use this — not getProviderById() — wherever the id comes from a pin
     * (conversation, coworker) that was stored earlier: permissions can be
     * revoked after the pin was made, and a revoked pin has to degrade to the
     * user's current provider rather than continue to be honoured.
     *
     * @throws NoPermittedProviderException when every provider is denied
     */
    public function getProviderForUser(?string $userId, ?string $requestedId): LLMProviderInterface {
        if ($requestedId !== null && $requestedId !== '' && $this->isAllowedForUser($requestedId, $userId)) {
            return $this->getProviderById($requestedId);
        }
        return $this->getProvider($userId);
    }

    /**
     * Whether the id names a registered provider.
     *
     * Callers that accept a provider id from a request MUST check this before
     * doing anything with it — getProviderById() silently falls back to
     * Anthropic, which is right for a stale config value but would mask a bad
     * request and let an arbitrary string reach persistence.
     */
    public function isKnownProviderId(string $id): bool {
        return isset($this->providers()[$id]);
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
