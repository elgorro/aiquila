<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private CredentialService $credentials,
        private LLMProviderFactory $providerFactory,
    ) {
    }

    public function getForm(): TemplateResponse {
        $providers = [];
        foreach ($this->providerFactory->getProviderIds() as $id) {
            $provider = $this->providerFactory->getProviderById($id);
            $providers[] = [
                'id' => $id,
                'label' => $provider->getLabel(),
                'has_key' => $this->credentials->hasApiKey(null, $id),
            ];
        }

        return new TemplateResponse('aiquila', 'admin', [
            'has_key' => $this->credentials->hasApiKey(null), // anthropic, back-compat
            'provider' => $this->providerFactory->getActiveProviderId(null),
            'providers' => $providers,
        ], '');
    }

    public function getSection(): string {
        return 'aiquila';
    }

    public function getPriority(): int {
        return 10;
    }
}
