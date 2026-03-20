<?php

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class UserSettings implements ISettings {
    private IConfig $config;
    private CredentialService $credentials;
    private ?string $userId;

    public function __construct(IConfig $config, CredentialService $credentials, ?string $userId) {
        $this->config = $config;
        $this->credentials = $credentials;
        $this->userId = $userId;
    }

    public function getForm(): TemplateResponse {
        $hasKey    = $this->credentials->hasApiKey($this->userId);
        $userModel = $this->config->getUserValue($this->userId, 'aiquila', 'model', '');
        return new TemplateResponse('aiquila', 'user', [
            'api_key'          => $hasKey ? '********' : '',
            'has_key'          => $hasKey,
            'user_model'       => $userModel,
            'available_models' => ClaudeModels::getAllModels(),
        ], '');
    }

    public function getSection(): string {
        return 'aiquila';
    }

    public function getPriority(): int {
        return 10;
    }
}
