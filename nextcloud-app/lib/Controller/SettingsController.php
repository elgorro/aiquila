<?php

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;
    private ClaudeSDKService $claudeService;
    protected $appName = 'aiquila';

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        ?string $userId,
        ClaudeSDKService $claudeService
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
        $this->claudeService = $claudeService;
    }

    /**
     * @NoAdminRequired
     */
    public function get(): JSONResponse {
        $userKey   = $this->config->getUserValue($this->userId, $this->appName, 'api_key', '');
        $userModel = $this->config->getUserValue($this->userId, $this->appName, 'model',   '');

        $liveModels      = $this->claudeService->listModels($this->userId);
        $availableModels = $liveModels ?? ClaudeModels::getAllModels();

        return new JSONResponse([
            'hasUserKey'      => !empty($userKey),
            'userModel'       => $userModel,
            'availableModels' => $availableModels,
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function save(): JSONResponse {
        $apiKey = $this->request->getParam('api_key', '');
        $model  = $this->request->getParam('model', '');

        $this->config->setUserValue($this->userId, $this->appName, 'api_key', $apiKey);

        if ($model !== '') {
            $this->config->setUserValue($this->userId, $this->appName, 'model', $model);
        } else {
            $this->config->deleteUserValue($this->userId, $this->appName, 'model');
        }

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

    /**
     * Test configuration with provided settings
     */
    public function testConfig(): JSONResponse {
        $apiKey = $this->request->getParam('api_key', '');
        $model = $this->request->getParam('model', '');
        $maxTokens = $this->request->getParam('max_tokens', '');
        $timeout = $this->request->getParam('timeout', '');

        // Use provided values or fall back to saved config
        $testApiKey = !empty($apiKey) ? $apiKey : $this->config->getAppValue($this->appName, 'api_key', '');
        $testModel = !empty($model) ? $model : $this->config->getAppValue($this->appName, 'model', ClaudeModels::DEFAULT_MODEL);
        $testMaxTokens = !empty($maxTokens) ? (int)$maxTokens : (int)$this->config->getAppValue($this->appName, 'max_tokens', '4096');
        $testTimeout = !empty($timeout) ? (int)$timeout : (int)$this->config->getAppValue($this->appName, 'api_timeout', '30');

        if (empty($testApiKey)) {
            return new JSONResponse([
                'success' => false,
                'message' => 'No API key provided'
            ], 400);
        }

        // Temporarily save test config
        $originalConfig = [
            'api_key' => $this->config->getAppValue($this->appName, 'api_key', ''),
            'model' => $this->config->getAppValue($this->appName, 'model', ''),
            'max_tokens' => $this->config->getAppValue($this->appName, 'max_tokens', ''),
            'api_timeout' => $this->config->getAppValue($this->appName, 'api_timeout', ''),
        ];

        try {
            // Set test configuration
            $this->config->setAppValue($this->appName, 'api_key', $testApiKey);
            $this->config->setAppValue($this->appName, 'model', $testModel);
            $this->config->setAppValue($this->appName, 'max_tokens', (string)$testMaxTokens);
            $this->config->setAppValue($this->appName, 'api_timeout', (string)$testTimeout);

            // Send test request
            $result = $this->claudeService->ask(
                'Test: Respond with "OK" if you receive this.',
                '',
                null
            );

            // Restore original configuration
            foreach ($originalConfig as $key => $value) {
                if (!empty($value)) {
                    $this->config->setAppValue($this->appName, $key, $value);
                }
            }

            // Return result directly
            if (isset($result['error'])) {
                return new JSONResponse([
                    'success' => false,
                    'message' => $result['error']
                ], 400);
            }

            return new JSONResponse([
                'success' => true,
                'message' => $result['response']
            ]);

        } catch (\Exception $e) {
            // Restore original configuration
            foreach ($originalConfig as $key => $value) {
                if (!empty($value)) {
                    $this->config->setAppValue($this->appName, $key, $value);
                }
            }

            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
