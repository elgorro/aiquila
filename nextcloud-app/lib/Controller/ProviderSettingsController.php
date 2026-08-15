<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LocalProvider;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

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
 *
 * Provider *access* is enforced on top of that: index() only describes providers
 * the current user is permitted to use, and update() refuses one they are not,
 * so the personal page can neither see nor select a provider an admin blocked.
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
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
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
        try {
            $defaultProvider = $this->providerFactory->getActiveProviderId($this->userId);
        } catch (NoPermittedProviderException $e) {
            // Every provider is blocked for this user. Report that honestly —
            // an empty list with no default — rather than naming one they
            // cannot use.
            return new JSONResponse([
                'defaultProvider' => '',
                'userProvider' => '',
                'adminProvider' => '',
                'providers' => [],
            ]);
        }

        return new JSONResponse([
            'defaultProvider' => $defaultProvider,
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
     * 403: The provider is not permitted for this user
     *
     * @return JSONResponse<Http::STATUS_OK, array{status: string, rejected: list<string>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{status: string, message: string}, array{}>
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

        // Access is checked before anything is written, and before the provider
        // can be made the user's default — the personal page never offers a
        // blocked provider, so reaching here means a hand-made request.
        if (!$this->providerFactory->isAllowedForUser($providerId, $userId)) {
            return $this->forbiddenProvider($providerId, $userId);
        }

        try {
            $rejected = $this->providerSettings->writeUser(
                $this->providerFactory->getProviderById($providerId),
                $userId,
                $values,
            );
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('AIquila: rejected user settings for ' . $providerId . ': ' . $e->getMessage(), [
                'provider' => $providerId,
                'user' => $userId,
            ]);
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($makeDefault === '1') {
            $this->config->setUserValue($userId, $this->appName, 'user_provider', $providerId);
            $this->logger->info('AIquila: user default provider set to ' . $providerId, [
                'provider' => $providerId,
                'user' => $userId,
            ]);
        } elseif ($makeDefault === '') {
            // Explicit "follow the instance default" rather than "leave alone".
            $this->config->deleteUserValue($userId, $this->appName, 'user_provider');
            $this->logger->info('AIquila: user default provider cleared', ['user' => $userId]);
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
            $this->logger->warning('AIquila: rejected instance settings for ' . $providerId . ': ' . $e->getMessage(), [
                'provider' => $providerId,
            ]);
            return new JSONResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        if ($makeDefault === '1') {
            $this->config->setAppValue($this->appName, 'provider', $providerId);
            $this->logger->info('AIquila: instance default provider set to ' . $providerId, [
                'provider' => $providerId,
            ]);
        }

        return new JSONResponse(['status' => 'ok', 'rejected' => $rejected]);
    }

    /**
     * Report whether a provider is reachable and serving the configured model
     *
     * Backs the status light on the personal settings page: the light describes
     * what *this user* gets, so the probe runs against their own key when they
     * have one and the inherited instance key otherwise.
     *
     * @param string $providerId Provider to check
     *
     * 200: Current provider health
     * 400: Unknown provider
     * 403: The provider is not permitted for this user
     *
     * @return JSONResponse<Http::STATUS_OK, array{providerId: string, state: string, reason: string, message: string, model: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{status: string, message: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function status(string $providerId): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return $this->unknownProvider($providerId);
        }

        $userId = $this->requireUserId();
        if (!$this->providerFactory->isAllowedForUser($providerId, $userId)) {
            return $this->forbiddenProvider($providerId, $userId);
        }

        return new JSONResponse($this->providerSettings->status(
            $this->providerFactory->getProviderById($providerId),
            $userId,
            admin: false,
        ));
    }

    /**
     * Report whether a provider is reachable and serving the instance model
     *
     * @param string $providerId Provider to check
     *
     * 200: Current provider health
     * 400: Unknown provider
     *
     * @return JSONResponse<Http::STATUS_OK, array{providerId: string, state: string, reason: string, message: string, model: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{status: string, message: string}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function adminStatus(string $providerId): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return $this->unknownProvider($providerId);
        }

        return new JSONResponse($this->providerSettings->status(
            $this->providerFactory->getProviderById($providerId),
            null,
            admin: true,
        ));
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
    public function test(string $providerId, #[\SensitiveParameter] string $api_key = ''): JSONResponse {
        if (!$this->providerFactory->isKnownProviderId($providerId)) {
            return new JSONResponse(
                ['success' => false, 'message' => 'Unknown provider: ' . $providerId],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $this->logger->info('AIquila: testing connection for ' . $providerId, [
            'provider' => $providerId,
            // Whether the key came from the form or from storage; never the key.
            'usingUnsavedKey' => $api_key !== '',
        ]);

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
                $this->logger->warning('AIquila: connection test for ' . $providerId . ' failed', [
                    'provider' => $providerId,
                    'error' => $result['error'],
                ]);
                return new JSONResponse(['success' => false, 'message' => $result['error']], Http::STATUS_BAD_REQUEST);
            }

            $this->logger->info('AIquila: connection test for ' . $providerId . ' succeeded', [
                'provider' => $providerId,
            ]);

            return new JSONResponse(['success' => true, 'message' => $result['response'] ?? '']);
        } catch (\Throwable $e) {
            $this->restoreKey($providerId, $originalKey);
            $this->logger->error('AIquila: connection test for ' . $providerId . ' threw', [
                'provider' => $providerId,
                'exception' => $e,
            ]);
            return new JSONResponse(
                ['success' => false, 'message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * Search users and groups for the provider access lists
     *
     * Backs the principal pickers on the admin settings page. Admin-only: the
     * personal page has no access fields, and an unprivileged user has no reason
     * to enumerate the user directory through this app.
     *
     * @param string $search Substring to match against user and group names
     * @param int $limit Maximum results per kind (clamped to 100)
     *
     * 200: Matching users and groups
     *
     * @return JSONResponse<Http::STATUS_OK, array{users: list<array{id: string, label: string}>, groups: list<array{id: string, label: string}>}, array{}>
     */
    #[OpenAPI(scope: OpenAPI::SCOPE_ADMINISTRATION)]
    public function principals(string $search = '', int $limit = 25): JSONResponse {
        $limit = max(1, min($limit, 100));

        $users = [];
        foreach ($this->userManager->searchDisplayName($search, $limit) as $user) {
            $users[] = ['id' => $user->getUID(), 'label' => $user->getDisplayName()];
        }

        $groups = [];
        foreach ($this->groupManager->search($search, $limit) as $group) {
            $groups[] = ['id' => $group->getGID(), 'label' => $group->getDisplayName()];
        }

        return new JSONResponse(['users' => $users, 'groups' => $groups]);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Describe every provider the caller may configure.
     *
     * The admin page sees all of them — an admin has to be able to edit the
     * access lists of a provider they themselves are blocked from. The personal
     * page sees only the ones the user is permitted to use.
     *
     * @return list<array<string, mixed>>
     */
    private function describeAll(bool $admin, bool $refresh): array {
        $ids = $admin
            ? $this->providerFactory->getProviderIds()
            : $this->providerFactory->getProviderIdsForUser($this->userId);

        $out = [];
        foreach ($ids as $id) {
            $out[] = $this->providerSettings->describe(
                $this->providerFactory->getProviderById($id),
                $this->userId,
                $admin,
                $refresh,
            );
        }
        return $out;
    }

    /** @return JSONResponse<Http::STATUS_FORBIDDEN, array{status: string, message: string}, array{}> */
    private function forbiddenProvider(string $providerId, string $userId): JSONResponse {
        $this->logger->warning('AIquila: denied provider access for ' . $providerId, [
            'provider' => $providerId,
            'user' => $userId,
        ]);
        return new JSONResponse(
            ['status' => 'error', 'message' => 'You are not permitted to use this provider.'],
            Http::STATUS_FORBIDDEN,
        );
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
    private function restoreKey(string $providerId, #[\SensitiveParameter] string $originalKey): void {
        if ($originalKey !== '') {
            $this->credentials->setApiKey(null, $originalKey, $providerId);
        } else {
            $this->credentials->deleteApiKey(null, $providerId);
        }
    }
}
