<?php

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;
    private string $appName = 'aiquila';

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
     * Save admin-level API key
     */
    public function saveAdmin(): JSONResponse {
        $apiKey = $this->request->getParam('api_key', '');

        if (empty($apiKey)) {
            return new JSONResponse(['status' => 'error', 'message' => 'API key cannot be empty'], 400);
        }

        $this->config->setAppValue($this->appName, 'api_key', $apiKey);
        return new JSONResponse(['status' => 'ok']);
    }
}
