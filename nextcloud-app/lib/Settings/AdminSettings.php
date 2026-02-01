<?php

namespace OCA\AIquila\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        $apiKey = $this->config->getAppValue('aiquila', 'api_key', '');
        $model = $this->config->getAppValue('aiquila', 'model', 'claude-sonnet-4-20250514');
        $maxTokens = $this->config->getAppValue('aiquila', 'max_tokens', '4096');
        $apiTimeout = $this->config->getAppValue('aiquila', 'api_timeout', '30');

        return new TemplateResponse('aiquila', 'admin', [
            'api_key' => $apiKey ? '********' : '',
            'has_key' => !empty($apiKey),
            'model' => $model,
            'max_tokens' => $maxTokens,
            'api_timeout' => $apiTimeout,
        ], '');
    }

    public function getSection(): string {
        return 'aiquila';
    }

    public function getPriority(): int {
        return 10;
    }
}
