<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;
    protected string $appName = 'aiquila';

    public function __construct(string $appName, IRequest $request, IConfig $config, ?string $userId) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function get(): JSONResponse {
        $userKey = $this->config->getUserValue($this->userId, $this->appName, 'api_key', '');
        return new JSONResponse([
            'hasUserKey' => !empty($userKey),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function save(): JSONResponse {
        $apiKey = $this->request->getParam('api_key', '');
        $this->config->setUserValue($this->userId, $this->appName, 'api_key', $apiKey);
        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Save admin-level settings
     */
    public function saveAdmin(): JSONResponse {
        $apiKey = $this->request->getParam('api_key', '');
        $model = $this->request->getParam('model', '');
        $maxTokens = $this->request->getParam('max_tokens', '');
        $apiTimeout = $this->request->getParam('api_timeout', '');

        // Save API key if provided
        if (!empty($apiKey)) {
            $this->config->setAppValue($this->appName, 'api_key', $apiKey);
        }

        // Save model if provided
        if (!empty($model)) {
            $this->config->setAppValue($this->appName, 'model', $model);
        }

        // Save max tokens if provided
        if (!empty($maxTokens)) {
            $maxTokensInt = (int)$maxTokens;
            if ($maxTokensInt >= 1 && $maxTokensInt <= 100000) {
                $this->config->setAppValue($this->appName, 'max_tokens', (string)$maxTokensInt);
            }
        }

        // Save timeout if provided
        if (!empty($apiTimeout)) {
            $apiTimeoutInt = (int)$apiTimeout;
            if ($apiTimeoutInt >= 10 && $apiTimeoutInt <= 1800) {
                $this->config->setAppValue($this->appName, 'api_timeout', (string)$apiTimeoutInt);
            }
        }

        return new JSONResponse(['status' => 'ok']);
    }
}
