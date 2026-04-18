<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        $providers = [
            'text-generation',
            'summarize',
            'headline',
            'topics',
            'image-to-text',
            'analyze-images',
            'translate',
            'proofread',
            'changetone',
            'simplification',
            'reformulation',
            'formalization',
        ];

        return [
            self::APP_ID => [
                'version'        => $this->appManager->getAppVersion(self::APP_ID),
                'model'          => $this->config->getAppValue(self::APP_ID, 'model', ClaudeModels::DEFAULT_MODEL),
                'providers'      => $providers,
                'api_configured' => $this->credentialService->getApiKey(null) !== '',
                'search_enabled' => $this->config->getAppValue(self::APP_ID, 'search_enabled', '1') !== '0',
            ],
        ];
    }
}
