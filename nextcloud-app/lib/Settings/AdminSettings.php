<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\CredentialService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private CredentialService $credentials;

    public function __construct(CredentialService $credentials) {
        $this->credentials = $credentials;
    }

    public function getForm(): TemplateResponse {
        $hasKey = $this->credentials->hasApiKey(null);

        return new TemplateResponse('aiquila', 'admin', [
            'has_key' => $hasKey,
        ], '');
    }

    public function getSection(): string {
        return 'aiquila';
    }

    public function getPriority(): int {
        return 10;
    }
}
