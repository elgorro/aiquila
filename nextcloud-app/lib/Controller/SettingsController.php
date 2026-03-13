<?php

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;
    private ClaudeSDKService $claudeService;

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
     * Get current user settings and available Claude models
     *
     * 200: User settings and available models
     *
     * @return JSONResponse<Http::STATUS_OK, array{hasUserKey: bool, userModel: string, availableModels: list<array{id: string, name: string}>}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
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
     * Save user-level API key and model preference
     *
     * @param string $api_key Personal Anthropic API key (leave empty to clear)
     * @param string $model   Preferred Claude model ID (leave empty to use admin default)
     *
     * 200: Settings saved successfully
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function save(string $api_key = '', string $model = ''): JSONResponse {
        $this->config->setUserValue($this->userId, $this->appName, 'api_key', $api_key);

        if ($model !== '') {
            $this->config->setUserValue($this->userId, $this->appName, 'model', $model);
        } else {
            $this->config->deleteUserValue($this->userId, $this->appName, 'model');
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Save admin-level settings (API key, model, token limits, timeout)
     *
     * @param string $api_key    Anthropic API key for the instance
     * @param string $model      Default Claude model ID
     * @param string $max_tokens Maximum tokens per response (1-100000)
     * @param string $api_timeout HTTP timeout in seconds (10-1800)
     *
     * 200: Admin settings saved successfully
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function saveAdmin(string $api_key = '', string $model = '', string $max_tokens = '', string $api_timeout = ''): JSONResponse {
        if (!empty($api_key)) {
            $this->config->setAppValue($this->appName, 'api_key', $api_key);
        }
        if (!empty($model)) {
            $this->config->setAppValue($this->appName, 'model', $model);
        }
        if (!empty($max_tokens)) {
            $maxTokensInt = (int)$max_tokens;
            if ($maxTokensInt >= 1 && $maxTokensInt <= 100000) {
                $this->config->setAppValue($this->appName, 'max_tokens', (string)$maxTokensInt);
            }
        }
        if (!empty($api_timeout)) {
            $apiTimeoutInt = (int)$api_timeout;
            if ($apiTimeoutInt >= 10 && $apiTimeoutInt <= 1800) {
                $this->config->setAppValue($this->appName, 'api_timeout', (string)$apiTimeoutInt);
            }
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Test the current or provided admin configuration by sending a live request to Claude
     *
     * @param string $api_key    API key to test (uses saved key if empty)
     * @param string $model      Model to test (uses saved model if empty)
     * @param string $max_tokens Max tokens for the test request
     * @param string $timeout    HTTP timeout for the test request
     *
     * 200: Test request completed; see success field for result
     * 400: No API key available or the test request failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function testConfig(string $api_key = '', string $model = '', string $max_tokens = '', string $timeout = ''): JSONResponse {
        $testApiKey = !empty($api_key) ? $api_key : $this->config->getAppValue($this->appName, 'api_key', '');
        $testModel = !empty($model) ? $model : $this->config->getAppValue($this->appName, 'model', ClaudeModels::DEFAULT_MODEL);
        $testMaxTokens = !empty($max_tokens) ? (int)$max_tokens : (int)$this->config->getAppValue($this->appName, 'max_tokens', '4096');
        $testTimeout = !empty($timeout) ? (int)$timeout : (int)$this->config->getAppValue($this->appName, 'api_timeout', '30');

        if (empty($testApiKey)) {
            return new JSONResponse(['success' => false, 'message' => 'No API key provided'], 400);
        }

        $originalConfig = [
            'api_key'     => $this->config->getAppValue($this->appName, 'api_key', ''),
            'model'       => $this->config->getAppValue($this->appName, 'model', ''),
            'max_tokens'  => $this->config->getAppValue($this->appName, 'max_tokens', ''),
            'api_timeout' => $this->config->getAppValue($this->appName, 'api_timeout', ''),
        ];

        try {
            $this->config->setAppValue($this->appName, 'api_key', $testApiKey);
            $this->config->setAppValue($this->appName, 'model', $testModel);
            $this->config->setAppValue($this->appName, 'max_tokens', (string)$testMaxTokens);
            $this->config->setAppValue($this->appName, 'api_timeout', (string)$testTimeout);

            $result = $this->claudeService->ask('Test: Respond with "OK" if you receive this.', '', null);

            foreach ($originalConfig as $key => $value) {
                if (!empty($value)) {
                    $this->config->setAppValue($this->appName, $key, $value);
                }
            }

            if (isset($result['error'])) {
                return new JSONResponse(['success' => false, 'message' => $result['error']], 400);
            }

            return new JSONResponse(['success' => true, 'message' => $result['response']]);

        } catch (\Throwable $e) {
            foreach ($originalConfig as $key => $value) {
                if (!empty($value)) {
                    $this->config->setAppValue($this->appName, $key, $value);
                }
            }
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
