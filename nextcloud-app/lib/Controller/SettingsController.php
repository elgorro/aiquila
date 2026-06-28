<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\DeepSeekModels;
use OCA\AIquila\Service\MistralModels;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;

class SettingsController extends Controller {
    private IConfig $config;
    private ?string $userId;
    private LLMProviderFactory $providerFactory;
    private CredentialService $credentials;
    private NativeMcpService $nativeMcp;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        ?string $userId,
        LLMProviderFactory $providerFactory,
        CredentialService $credentials,
        NativeMcpService $nativeMcp
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
        $this->providerFactory = $providerFactory;
        $this->credentials = $credentials;
        $this->nativeMcp = $nativeMcp;
    }

    /** Config key holding a user's preferred model for a provider. */
    private function userModelKey(string $providerId): string {
        return $providerId === 'anthropic' ? 'user_model' : 'user_model_' . $providerId;
    }

    /** Static fallback model list for a provider id. */
    private function staticModels(string $providerId): array {
        return match ($providerId) {
            'mistral' => MistralModels::getAllModels(),
            'deepseek' => DeepSeekModels::getAllModels(),
            default => ClaudeModels::getAllModels(),
        };
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
        $activeProviderId = $this->providerFactory->getActiveProviderId($this->userId);
        $userProvider     = $this->config->getUserValue($this->userId, $this->appName, 'user_provider', '');

        // Per-provider metadata: label, whether a personal key exists, the user's
        // preferred model, and the available model list (live with static fallback).
        $providers = [];
        foreach ($this->providerFactory->getProviderIds() as $id) {
            $provider = $this->providerFactory->getProviderById($id);
            $liveModels = $provider->listModels($this->userId);
            $providers[] = [
                'id'              => $id,
                'label'          => $provider->getLabel(),
                'configured'     => $provider->isConfigured($this->userId),
                'hasUserKey'     => $this->credentials->hasApiKey($this->userId, $id),
                'userModel'      => $this->config->getUserValue($this->userId, $this->appName, $this->userModelKey($id), ''),
                'availableModels' => $liveModels ?? $this->staticModels($id),
            ];
        }

        // Backward-compatible flat fields reflect the active provider.
        $active = $this->providerFactory->getProviderById($activeProviderId);
        $hasUserKey      = $this->credentials->hasApiKey($this->userId, $activeProviderId);
        $userModel       = $this->config->getUserValue($this->userId, $this->appName, $this->userModelKey($activeProviderId), '');
        $availableModels = $active->listModels($this->userId) ?? $this->staticModels($activeProviderId);

        $defaultSystemPrompt = $this->config->getUserValue($this->userId, $this->appName, 'default_system_prompt', '');
        $defaultVerbose = $this->config->getUserValue($this->userId, $this->appName, 'default_verbose', '0') === '1';

        // Native MCP connector toggle. User value is '1', '0', or '' (inherit admin).
        $userNativeMcp = $this->config->getUserValue($this->userId, $this->appName, 'native_mcp_enabled', '');
        $adminNativeMcp = $this->config->getAppValue($this->appName, 'native_mcp_enabled', '0') === '1';

        return new JSONResponse([
            'provider'            => $activeProviderId,
            'userProvider'        => $userProvider,
            'providers'           => $providers,
            'hasUserKey'          => $hasUserKey,
            'userModel'           => $userModel,
            'availableModels'     => $availableModels,
            'defaultSystemPrompt' => $defaultSystemPrompt,
            'defaultVerbose'      => $defaultVerbose,
            'nativeMcpUserOverride' => $userNativeMcp,         // '', '1', or '0'
            'nativeMcpAdminDefault' => $adminNativeMcp,
            'nativeMcpEffective'    => $this->nativeMcp->isEnabledForUser($this->userId),
        ]);
    }

    /**
     * Save user-level API key
     *
     * @param string $api_key Personal API key for the scoped provider (leave empty to clear)
     * @param string $model   Preferred model ID for the scoped provider (leave empty to use admin default)
     * @param string|null $provider Active provider override ('' clears, e.g. 'anthropic'/'mistral', null keeps unchanged). Also scopes api_key/model in this call.
     * @param string|null $default_system_prompt Default system prompt (null to keep unchanged)
     * @param string|null $default_verbose Enable verbose mode by default ('1' or null to keep unchanged)
     * @param string|null $native_mcp_enabled User override for native-MCP ('1' opt in, '0' opt out, '' clears override, null keeps unchanged)
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
        ?string $api_key = null,
        string $model = '',
        ?string $provider = null,
        ?string $default_system_prompt = null,
        ?string $default_verbose = null,
        ?string $native_mcp_enabled = null
    ): JSONResponse {
        // Provider override: '' clears (inherit admin default), non-empty sets it.
        if ($provider !== null) {
            if ($provider === '') {
                $this->config->deleteUserValue($this->userId, $this->appName, 'user_provider');
            } else {
                $this->config->setUserValue($this->userId, $this->appName, 'user_provider', $provider);
            }
        }

        // api_key and model are scoped to the provider being edited (the one named
        // in this call, falling back to the now-active provider).
        $scopeProvider = ($provider !== null && $provider !== '')
            ? $provider
            : $this->providerFactory->getActiveProviderId($this->userId);

        // null = leave the key untouched (e.g. when only switching provider/model);
        // '' = explicitly clear; non-empty = set.
        if ($api_key !== null) {
            if ($api_key !== '') {
                $this->credentials->setApiKey($this->userId, $api_key, $scopeProvider);
            } else {
                $this->credentials->deleteApiKey($this->userId, $scopeProvider);
            }
        }

        $modelKey = $this->userModelKey($scopeProvider);
        if ($model !== '') {
            $this->config->setUserValue($this->userId, $this->appName, $modelKey, $model);
        } else {
            $this->config->deleteUserValue($this->userId, $this->appName, $modelKey);
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

        if ($native_mcp_enabled !== null) {
            // '' clears the override (inherit admin); '1' / '0' explicitly opt in/out.
            if ($native_mcp_enabled === '') {
                $this->config->deleteUserValue($this->userId, $this->appName, 'native_mcp_enabled');
            } else {
                $this->config->setUserValue($this->userId, $this->appName, 'native_mcp_enabled', $native_mcp_enabled === '1' ? '1' : '0');
            }
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Save admin-level API key
     *
     * Model, max_tokens, and api_timeout are now managed by Declarative Settings.
     *
     * @param string $api_key API key for the instance, scoped to $provider (default 'anthropic')
     * @param string|null $provider Instance default provider ('anthropic'/'mistral', null keeps unchanged). Also scopes api_key in this call.
     * @param string|null $native_mcp_enabled Instance default for native-MCP ('1' enabled, '0' disabled, null keeps unchanged)
     * @param string|null $native_mcp_extra_url Optional extra MCP server URL (trimmed; null keeps unchanged)
     * @param string|null $native_mcp_extra_token Bearer token for the extra MCP server ('' clears, null keeps unchanged)
     * @param string|null $mistral_connector_ids Comma/space-separated Mistral connector IDs for the native-MCP path (trimmed; null keeps unchanged)
     *
     * 200: Admin settings saved successfully
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function saveAdmin(
        string $api_key = '',
        ?string $provider = null,
        ?string $native_mcp_enabled = null,
        ?string $native_mcp_extra_url = null,
        ?string $native_mcp_extra_token = null,
        ?string $mistral_connector_ids = null
    ): JSONResponse {
        if ($provider !== null && $provider !== '') {
            $this->config->setAppValue($this->appName, 'provider', $provider);
        }

        if (!empty($api_key)) {
            $scopeProvider = ($provider !== null && $provider !== '') ? $provider : 'anthropic';
            $this->credentials->setApiKey(null, $api_key, $scopeProvider);
        }

        if ($native_mcp_enabled !== null) {
            $this->config->setAppValue($this->appName, 'native_mcp_enabled', $native_mcp_enabled === '1' ? '1' : '0');
        }
        if ($native_mcp_extra_url !== null) {
            $this->config->setAppValue($this->appName, 'native_mcp_extra_url', trim($native_mcp_extra_url));
        }
        if ($native_mcp_extra_token !== null) {
            // Store via credential manager so it's encrypted at rest like the API key.
            if ($native_mcp_extra_token === '') {
                $this->credentials->deleteNativeMcpExtraToken();
            } else {
                $this->credentials->setNativeMcpExtraToken($native_mcp_extra_token);
            }
        }
        if ($mistral_connector_ids !== null) {
            $this->config->setAppValue($this->appName, 'mistral_connector_ids', trim($mistral_connector_ids));
        }

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Get admin native-MCP config + per-server reachability snapshot
     *
     * 200: Admin native MCP settings
     *
     * @return JSONResponse<Http::STATUS_OK, array{enabled: bool, extraUrl: string, hasExtraToken: bool, mistralConnectorIds: string, servers: list<array{id: int|null, name: string, url: string, scheme_ok: bool, http_status: int|null, reachable: bool, message: string}>}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function nativeMcpStatus(): JSONResponse {
        $enabled = $this->config->getAppValue($this->appName, 'native_mcp_enabled', '0') === '1';
        $extraUrl = $this->config->getAppValue($this->appName, 'native_mcp_extra_url', '');
        $hasExtraToken = $this->credentials->hasNativeMcpExtraToken();
        $mistralConnectorIds = $this->config->getAppValue($this->appName, 'mistral_connector_ids', '');

        $servers = $this->nativeMcp->probeAll();

        return new JSONResponse([
            'enabled' => $enabled,
            'extraUrl' => $extraUrl,
            'hasExtraToken' => $hasExtraToken,
            'mistralConnectorIds' => $mistralConnectorIds,
            'servers' => $servers,
        ]);
    }

    /**
     * Test the current configuration by sending a live request to Claude
     *
     * Uses saved model/max_tokens/api_timeout from Declarative Settings.
     * Optionally accepts an API key override for testing before saving.
     *
     * @param string $api_key API key to test (uses saved key if empty)
     * @param string|null $provider Provider to test ('anthropic'/'mistral'; null uses the active provider). Also scopes the api_key override.
     *
     * 200: Test request completed; see success field for result
     * 400: No API key available or the test request failed
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_BAD_REQUEST, array{success: bool, message: string}, array{}>
     *        |JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function testConfig(string $api_key = '', ?string $provider = null): JSONResponse {
        $providerId = ($provider !== null && $provider !== '')
            ? $provider
            : $this->providerFactory->getActiveProviderId(null);

        $testApiKey = !empty($api_key) ? $api_key : $this->credentials->getApiKey(null, $providerId);

        if (empty($testApiKey)) {
            return new JSONResponse(['success' => false, 'message' => 'No API key provided'], 400);
        }

        $originalApiKey = $this->credentials->getApiKey(null, $providerId);
        $service = $this->providerFactory->getProviderById($providerId);

        try {
            // Temporarily set the test key so the provider picks it up.
            $this->credentials->setApiKey(null, $testApiKey, $providerId);

            $result = $service->ask('Test: Respond with "OK" if you receive this.', '', null);

            $this->restoreKey($providerId, $originalApiKey);

            if (isset($result['error'])) {
                return new JSONResponse(['success' => false, 'message' => $result['error']], 400);
            }

            return new JSONResponse(['success' => true, 'message' => $result['response']]);

        } catch (\Throwable $e) {
            $this->restoreKey($providerId, $originalApiKey);
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function restoreKey(string $providerId, string $originalApiKey): void {
        if ($originalApiKey !== '') {
            $this->credentials->setApiKey(null, $originalApiKey, $providerId);
        } else {
            $this->credentials->deleteApiKey(null, $providerId);
        }
    }
}
