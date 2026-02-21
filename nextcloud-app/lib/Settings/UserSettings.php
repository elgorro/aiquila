<?php

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class UserSettings implements ISettings {
    private IConfig $config;
    private ?string $userId;

    public function __construct(IConfig $config, ?string $userId) {
        $this->config = $config;
        $this->userId = $userId;
    }

    public function getForm(): TemplateResponse {
        $apiKey   = $this->config->getUserValue($this->userId, 'aiquila', 'api_key', '');
        $userModel = $this->config->getUserValue($this->userId, 'aiquila', 'model', '');
        return new TemplateResponse('aiquila', 'user', [
            'api_key'          => $apiKey ? '********' : '',
            'has_key'          => !empty($apiKey),
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
