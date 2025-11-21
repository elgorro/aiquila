<?php

namespace OCA\NextClaude\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        $apiKey = $this->config->getAppValue('nextclaude', 'api_key', '');
        return new TemplateResponse('nextclaude', 'admin', [
            'api_key' => $apiKey ? '********' : '',
            'has_key' => !empty($apiKey),
        ], '');
    }

    public function getSection(): string {
        return 'nextclaude';
    }

    public function getPriority(): int {
        return 10;
    }
}
