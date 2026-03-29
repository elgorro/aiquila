<?php

declare(strict_types=1);

namespace OCA\AIquila\Capabilities;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IConfig;

class AIquilaCapability implements ICapability {
    private const APP_ID = 'aiquila';

    public function __construct(
        private IConfig $config,
        private CredentialService $credentialService,
        private IAppManager $appManager,
    ) {
    }

    public function getCapabilities(): array {
        $providers = ['text-generation'];

        if (interface_exists(\OCP\TextProcessing\IProvider::class)) {
            $providers[] = 'summarize';
            $providers[] = 'headline';
            $providers[] = 'topics';
        }

        if (interface_exists(\OCP\TaskProcessing\ISynchronousProvider::class)) {
            $providers[] = 'image-to-text';
            $providers[] = 'analyze-images';
            $providers[] = 'translate';
            $providers[] = 'proofread';
            $providers[] = 'changetone';
            $providers[] = 'simplification';
            $providers[] = 'reformulation';
            $providers[] = 'formalization';
        }

        return [
            self::APP_ID => [
                'version'        => $this->appManager->getAppVersion(self::APP_ID),
                'model'          => $this->config->getAppValue(self::APP_ID, 'model', ClaudeModels::DEFAULT_MODEL),
                'providers'      => $providers,
                'api_configured' => $this->credentialService->getApiKey(null) !== '',
                'search_enabled' => $this->config->getAppValue(self::APP_ID, 'search_enabled', 'yes') === 'yes',
            ],
        ];
    }
}
