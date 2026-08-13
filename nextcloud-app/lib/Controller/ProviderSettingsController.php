<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LocalProvider;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Schema-driven read/write of provider configuration for the settings pages.
 *
 * Both the admin and the personal page render whatever
 * LLMProviderInterface::getSettingsSchema() describes, so this controller never
 * names a provider or a config key — adding a provider requires no change here.
 *
 * The admin/user split is enforced twice over: the admin routes carry no
 * #[NoAdminRequired] (so Nextcloud requires an admin), and
 * ProviderSettingsService::writeUser() additionally drops any field whose scope
 * is not user-writable. That second check is what keeps endpoint URLs — which
 * decide where the server sends outbound requests — out of reach of the
 * personal page even if a route were ever mis-annotated.
 */
class ProviderSettingsController extends Controller {
    use RequiresUserIdTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ?string $userId,
        private readonly IConfig $config,
        private readonly LLMProviderFactory $providerFactory,
        private readonly ProviderSettingsService $providerSettings,
        private readonly CredentialService $credentials,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List providers with their user-writable settings
     *
     * Returns one entry per registered provider: label, capability flags, model
     * list, and the user-scope fields with their current values. API keys are
     * reported as `hasValue` booleans only — the key itself never leaves the
     * credential manager.
     *
     * @param string|null $refresh '1' to bypass the cached model lists and re-query every provider
     *
     * 200: Provider settings for the current user
     *
     * @return JSONResponse<Http::STATUS_OK, array{defaultProvider: string, userProvider: string, adminProvider: string, providers: list<array<string, mixed>>}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(?string $refresh = null): JSONResponse {
        return new JSONResponse([
            'defaultProvider' => $this->providerFactory->getActiveProviderId($this->userId),
            'userProvider' => $this->config->getUserValue((string)$this->userId, $this->appName, 'user_provider', ''),
            'adminProvider' => $this->config->getAppValue($this->appName, 'provider', LLMProviderFactory::DEFAULT_PROVIDER),
            'providers' => $this->describeAll(admin: false, refresh: $refresh === '1'),
        ]);
    }

    /**
     * Save user-scope settings for one provider
     *
     * Field values are `{fieldId: value}` pairs matching the schema returned by
     * the index endpoint. An empty value clears the user override so the
     * instance default applies again. Fields that are not user-writable are
     * reported back in `rejected` and left untouched.
     *
     * @param string $providerId Provider to configure
     * @param array<string, mixed> $values Field id => value pairs
     * @param string|null $makeDefault '1' to also make this the user's default provider
     *
     * 200: Settings saved
     * 400: Unknown provider or invalid value
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string, rejected: list<string>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(string $providerId, array $values = [], ?string $makeDefault = null): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return $this->unknownProvider($providerId);
        }

        $userId = $this->requireUserId();

        try {
            $rejected = $this->providerSettings->writeUser(
                $this->providerFactory->getProviderById($providerId),
                $userId,
                $values,
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($makeDefault === '1') {
            $this->config->setUserValue($userId, $this->appName, 'user_provider', $providerId);
        } elseif ($makeDefault === '') {
            // Explicit "follow the instance default" rather than "leave alone".
            $this->config->deleteUserValue($userId, $this->appName, 'user_provider');
        }

        return new JSONResponse(['status' => 'ok', 'rejected' => $rejected]);
    }

    /**
     * List providers with their full settings schema and instance values
     *
     * @param string|null $refresh '1' to bypass the cached model lists
     *
     * 200: Provider settings for the instance
     *
     * @return JSONResponse<Http::STATUS_OK, array{defaultProvider: string, providers: list<array<string, mixed>>}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminIndex(?string $refresh = null): JSONResponse {
        return new JSONResponse([
            'defaultProvider' => $this->config->getAppValue($this->appName, 'provider', LLMProviderFactory::DEFAULT_PROVIDER),
            'providers' => $this->describeAll(admin: true, refresh: $refresh === '1'),
        ]);
    }

    /**
     * Save instance-scope settings for one provider
     *
     * @param string $providerId Provider to configure
     * @param array<string, mixed> $values Field id => value pairs
     * @param string|null $makeDefault '1' to also make this the instance default provider
     *
     * 200: Settings saved
     * 400: Unknown provider or invalid value
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string, rejected: list<string>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminUpdate(string $providerId, array $values = [], ?string $makeDefault = null): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return $this->unknownProvider($providerId);
        }

        try {
            $rejected = $this->providerSettings->writeAdmin(
                $this->providerFactory->getProviderById($providerId),
                $values,
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($makeDefault === '1') {
            $this->config->setAppValue($this->appName, 'provider', $providerId);
        }

        return new JSONResponse(['status' => 'ok', 'rejected' => $rejected]);
    }

    /**
     * Send a live request to a provider to confirm it is reachable and authorised
     *
     * Optionally accepts a key that has not been saved yet, so an admin can
     * verify one before committing it. The stored key is restored afterwards
     * either way.
     *
     * @param string $providerId Provider to test
     * @param string $api_key Key to test with; the saved key is used when empty
     *
     * 200: The provider answered
     * 400: No key available, or the provider rejected the request
     *
     * @return JSONResponse<Http::STATUS_OK, array{success: bool, message: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{success: bool, message: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function test(string $providerId, string $api_key = ''): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return new JSONResponse(
                ['success' => false, 'message' => 'Unknown provider: ' . $providerId],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $service = $this->providerFactory->getProviderById($providerId);
        $testKey = $api_key !== '' ? $api_key : $this->credentials->getApiKey(null, $providerId);

        // Local endpoints (Ollama, llama-server without --api-key) authenticate
        // with nothing at all, so a missing key is only fatal elsewhere.
        if ($testKey === '' && !($service instanceof LocalProvider)) {
            return new JSONResponse(['success' => false, 'message' => 'No API key provided'], Http::STATUS_BAD_REQUEST);
        }
        if ($testKey === '' && !$service->isConfigured(null)) {
            return new JSONResponse(
                ['success' => false, 'message' => 'No local model endpoint configured'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $originalKey = $this->credentials->getApiKey(null, $providerId);

        try {
            if ($testKey !== '') {
                $this->credentials->setApiKey(null, $testKey, $providerId);
            }

            $result = $service->ask('Test: Respond with "OK" if you receive this.', '', null);

            $this->restoreKey($providerId, $originalKey);

            if (isset($result['error'])) {
                return new JSONResponse(['success' => false, 'message' => $result['error']], Http::STATUS_BAD_REQUEST);
            }

            return new JSONResponse(['success' => true, 'message' => $result['response'] ?? '']);
        } catch (\Throwable $e) {
            $this->restoreKey($providerId, $originalKey);
            return new JSONResponse(
                ['success' => false, 'message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function describeAll(bool $admin, bool $refresh): array {
        $out = [];
        foreach ($this->providerFactory->getProviderIds() as $id) {
            $out[] = $this->providerSettings->describe(
                $this->providerFactory->getProviderById($id),
                $this->userId,
                $admin,
                $refresh,
            );
        }
        return $out;
    }

    /** @return JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}> */
    private function unknownProvider(string $providerId): JSONResponse {
        return new JSONResponse(
            ['status' => 'error', 'message' => 'Unknown provider: ' . $providerId],
            Http::STATUS_BAD_REQUEST,
        );
    }

    /**
     * Put back whatever key was stored before a test overwrote it. An empty
     * original means there was none, so the slot is cleared rather than left
     * holding the test key.
     */
    private function restoreKey(string $providerId, string $originalKey): void {
        if ($originalKey !== '') {
            $this->credentials->setApiKey(null, $originalKey, $providerId);
        } else {
            $this->credentials->deleteApiKey(null, $providerId);
        }
    }
}
