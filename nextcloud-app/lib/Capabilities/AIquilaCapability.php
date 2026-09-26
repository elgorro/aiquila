<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Capabilities;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\TaskProcessing\ProviderRegistry;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Container\ContainerInterface;

class AIquilaCapability implements ICapability {
    private const APP_ID = 'aiquila';

    public function __construct(
        private IConfig $config,
        private CredentialService $credentialService,
        private IAppManager $appManager,
        private ContainerInterface $container,
    ) {
    }

    /**
     * @return array{aiquila: array{version: string, model: string, providers: list<string>, api_configured: bool, search_enabled: bool}}
     */
    public function getCapabilities(): array {
        return [
            self::APP_ID => [
                'version'        => $this->appManager->getAppVersion(self::APP_ID),
                'model'          => $this->config->getAppValue(self::APP_ID, 'model', ClaudeModels::DEFAULT_MODEL),
                'providers'      => $this->taskTypeIds(),
                'api_configured' => $this->credentialService->getApiKey(null) !== '',
                'search_enabled' => $this->config->getAppValue(self::APP_ID, 'search_enabled', '1') !== '0',
            ],
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
