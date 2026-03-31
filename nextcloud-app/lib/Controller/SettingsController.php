<?php

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
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
    private CredentialService $credentials;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        ?string $userId,
        ClaudeSDKService $claudeService,
        CredentialService $credentials
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
        $this->claudeService = $claudeService;
        $this->credentials = $credentials;
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
        $hasUserKey = $this->credentials->hasApiKey($this->userId);
        $userModel  = $this->config->getUserValue($this->userId, $this->appName, 'model', '');

        $liveModels      = $this->claudeService->listModels($this->userId);
        $availableModels = $liveModels ?? ClaudeModels::getAllModels();

        $defaultSystemPrompt = $this->config->getUserValue($this->userId, $this->appName, 'default_system_prompt', '');
        $defaultVerbose = $this->config->getUserValue($this->userId, $this->appName, 'default_verbose', '0') === '1';

        return new JSONResponse([
            'hasUserKey'          => $hasUserKey,
            'userModel'           => $userModel,
            'availableModels'     => $availableModels,
            'defaultSystemPrompt' => $defaultSystemPrompt,
            'defaultVerbose'      => $defaultVerbose,
        ]);
    }

    /**
     * Save user-level API key
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
    public function save(
        string $api_key = '',
        string $model = '',
        ?string $default_system_prompt = null,
        ?string $default_verbose = null
    ): JSONResponse {
        if ($api_key !== '') {
            $this->credentials->setApiKey($this->userId, $api_key);
        } else {
            $this->credentials->deleteApiKey($this->userId);
        }

        if ($model !== '') {
            $this->config->setUserValue($this->userId, $this->appName, 'model', $model);
        } else {
            $this->config->deleteUserValue($this->userId, $this->appName, 'model');
        }

        if ($default_system_prompt !== null) {
            if ($default_system_prompt !== '') {
                $this->config->setUserValue($this->userId, $this->appName, 'default_system_prompt', $default_system_prompt);
            } else {
                $this->config->deleteUserValue($this->userId, $this->appName, 'default_system_prompt');
            }
        }

        if ($default_verbose !== null) {
            $this->config->setUserValue($this->userId, $this->appName, 'default_verbose', $default_verbose === '1' ? '1' : '0');
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Save admin-level API key
     *
     * Model, max_tokens, and api_timeout are now managed by Declarative Settings.
     *
     * @param string $api_key Anthropic API key for the instance
     *
     * 200: Admin settings saved successfully
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function saveAdmin(string $api_key = ''): JSONResponse {
        if (!empty($api_key)) {
            $this->credentials->setApiKey(null, $api_key);
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Test the current configuration by sending a live request to Claude
     *
     * Uses saved model/max_tokens/api_timeout from Declarative Settings.
     * Optionally accepts an API key override for testing before saving.
     *
     * @param string $api_key API key to test (uses saved key if empty)
     *
     * 200: Test request completed; see success field for result
     * 400: No API key available or the test request failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function testConfig(string $api_key = ''): JSONResponse {
        $testApiKey = !empty($api_key) ? $api_key : $this->credentials->getApiKey(null);

        if (empty($testApiKey)) {
            return new JSONResponse(['success' => false, 'message' => 'No API key provided'], 400);
        }

        $originalApiKey = $this->credentials->getApiKey(null);

        try {
            // Temporarily set the test key so ClaudeSDKService picks it up
            $this->credentials->setApiKey(null, $testApiKey);

            $result = $this->claudeService->ask('Test: Respond with "OK" if you receive this.', '', null);

            // Restore original key
            if ($originalApiKey !== '') {
                $this->credentials->setApiKey(null, $originalApiKey);
            } else {
                $this->credentials->deleteApiKey(null);
            }

            if (isset($result['error'])) {
                return new JSONResponse(['success' => false, 'message' => $result['error']], 400);
            }

            return new JSONResponse(['success' => true, 'message' => $result['response']]);

        } catch (\Throwable $e) {
            // Restore original key
            if ($originalApiKey !== '') {
                $this->credentials->setApiKey(null, $originalApiKey);
            } else {
                $this->credentials->deleteApiKey(null);
            }
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
