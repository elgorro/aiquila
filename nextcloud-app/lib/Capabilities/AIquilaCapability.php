<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Capabilities;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\SearchSettings;
use OCA\AIquila\TaskProcessing\ProviderRegistry;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IUserSession;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Container\ContainerInterface;

class AIquilaCapability implements ICapability {
    private const APP_ID = 'aiquila';

    public function __construct(
        private LLMProviderFactory $providerFactory,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private SearchSettings $searchSettings,
    ) {
    }

    /**
     * `provider`/`model`/`api_configured` describe the instance default the
     * factory resolves, not the Claude provider's own `model` key: with
     * another provider as the default, that key is a leftover nobody uses.
     * `user_provider`/`user_model` are what serves the signed-in user, which
     * a `user_provider` override can make differ from the default.
     *
     * @return array{aiquila: array{version: string, provider: string|null, model: string|null, user_provider: string|null, user_model: string|null, providers: list<string>, api_configured: bool, search_enabled: bool}}
     */
    public function getCapabilities(): array {
        $default = $this->resolve(null);
        $user = $this->userSession->getUser();
        $mine = $user !== null ? $this->resolve($user->getUID()) : null;

        return [
            self::APP_ID => [
                'version'        => $this->appManager->getAppVersion(self::APP_ID),
                'provider'       => $default['provider'] ?? null,
                'model'          => $default['model'] ?? null,
                'user_provider'  => $mine['provider'] ?? null,
                'user_model'     => $mine['model'] ?? null,
                'providers'      => $this->taskTypeIds(),
                'api_configured' => $default['configured'] ?? false,
                'search_enabled' => $this->searchSettings->isEnabled(),
            ],
        ];
    }

    /**
     * The provider that serves `$userId` (null: the instance default), or
     * null when every provider is denied.
     *
     * @return array{provider: string, model: string, configured: bool}|null
     */
    private function resolve(?string $userId): ?array {
        try {
            $provider = $this->providerFactory->getProvider($userId);
        } catch (NoPermittedProviderException) {
            return null;
        }
        return [
            'provider'   => $provider->getId(),
            'model'      => $provider->getModel($userId),
            'configured' => $provider->isConfigured($userId),
        ];
    }

    /**
     * The TaskProcessing task-type ids AIquila registers a provider for,
     * sorted and de-duplicated.
     *
     * @return list<string>
     */
    private function taskTypeIds(): array {
        $ids = [];
        foreach (ProviderRegistry::PROVIDERS as $class) {
            $provider = $this->container->get($class);
            assert($provider instanceof ISynchronousProvider);
            $ids[] = $provider->getTaskTypeId();
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
}
