<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\CredentialService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class UserSettings implements ISettings {
    private CredentialService $credentials;
    private ?string $userId;

    public function __construct(CredentialService $credentials, ?string $userId) {
        $this->credentials = $credentials;
        $this->userId = $userId;
    }

    public function getForm(): TemplateResponse {
        $hasKey = $this->credentials->hasApiKey($this->userId);
        return new TemplateResponse('aiquila', 'user', [
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
