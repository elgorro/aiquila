<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderAccessService;
use OCA\AIquila\Service\Provider\ProviderProbe;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCA\AIquila\Service\Provider\ThinkingProfileInterface;
use OCP\ICache;
use OCP\ICacheFactory;
use OCA\AIquila\Service\AppSettings;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProviderSettingsServiceTest extends TestCase {
    private $config;
    private $credentials;
    private $cacheFactory;
    private $cache;
    private $access;
    private $userManager;
    private $groupManager;
    private $providerFactory;
    private ProviderSettingsService $service;

    protected function setUp(): void {
        $this->config = $this->createStub(AppSettings::class);
        $this->credentials = $this->createStub(CredentialService::class);
        $this->cacheFactory = $this->createStub(ICacheFactory::class);
        $this->cache = $this->createStub(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturnCallback(fn () => $this->cache);

        $this->access = $this->createStub(ProviderAccessService::class);
        $this->access->method('getLists')->willReturn([
            'allowed_users' => [],
            'allowed_groups' => [],
            'blocked_users' => [],
            'blocked_groups' => [],
        ]);
        $this->userManager = $this->createStub(IUserManager::class);
        $this->groupManager = $this->createStub(IGroupManager::class);
        $this->providerFactory = $this->createStub(LLMProviderFactory::class);
        $this->providerFactory->method('getProviderIds')->willReturn(['testprovider']);

        $this->buildService();
    }

    private function buildService(): void {
        $this->service = new ProviderSettingsService(
            $this->config,
            $this->credentials,
            $this->access,
            $this->userManager,
            $this->groupManager,
            $this->cacheFactory,
            $this->providerFactory,
            $this->createStub(LoggerInterface::class),
        );
    }

    /**
     * A stand-in provider carrying one field of every scope, so the tests
     * exercise the scope rules rather than any single real provider's schema.
     *
     * @param list<array<string, mixed>> $schema
     */
    private function provider(array $schema, bool $mock = false): LLMProviderInterface {
        $provider = $mock ? $this->createMock(LLMProviderInterface::class) : $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('testprovider');
        $provider->method('getLabel')->willReturn('Test provider');
        $provider->method('getSettingsSchema')->willReturn($schema);
        return $provider;
    }

    /** @return list<array<string, mixed>> */
    private function mixedScopeSchema(): array {
        return [
            ProviderSettingsSchema::baseUrl('test_base_url', 'Endpoint URL', 'Admin only.'),
            ProviderSettingsSchema::model('model_test', 'user_model_test', 'default-model'),
            ProviderSettingsSchema::maxTokens('max_tokens_test', 4096),
        ];
    }

    // ── Scope enforcement (the SSRF guard) ──────────────────────────────

    public function testUserWriteRejectsAdminOnlyEndpointField(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        // The endpoint decides where the server sends outbound requests; a
        // non-admin must never be able to move it.
        $this->config->expects($this->never())->method('setAppValue');
        $this->config->expects($this->never())->method('setUserValue');

        $rejected = $this->service->writeUser($provider, 'alice', [
            'test_base_url' => 'http://169.254.169.254/latest/meta-data',
        ]);

        $this->assertSame(['test_base_url'], $rejected);
    }

    public function testUserWriteAcceptsBothScopedField(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'aiquila', 'user_model_test', 'some-model');

        $rejected = $this->service->writeUser($provider, 'alice', ['model' => 'some-model']);

        $this->assertSame([], $rejected);
    }

    public function testUserWriteRejectsUnknownField(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->never())->method('setUserValue');

        $rejected = $this->service->writeUser($provider, 'alice', ['not_a_field' => 'x']);

        $this->assertSame(['not_a_field'], $rejected);
    }

    public function testUserWriteRejectsAdminOnlyMaxTokens(): void {
        $provider = $this->provider($this->mixedScopeSchema());

        $rejected = $this->service->writeUser($provider, 'alice', ['max_tokens' => '999999']);

        $this->assertSame(['max_tokens'], $rejected);
    }

    public function testEmptyUserValueClearsTheOverride(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('alice', 'aiquila', 'user_model_test');
        $this->config->expects($this->never())->method('setUserValue');

        $this->service->writeUser($provider, 'alice', ['model' => '']);
    }

    public function testPersonalDescriptionOmitsAdminOnlyFields(): void {
        $provider = $this->provider($this->mixedScopeSchema());
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities());
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getModel')->willReturn('default-model');
        $this->cache->method('get')->willReturn(['default-model']);

        $described = $this->service->describe($provider, 'alice', admin: false);
        $fieldIds = array_column($described['fields'], 'id');

        $this->assertNotContains('test_base_url', $fieldIds);
        $this->assertNotContains('max_tokens', $fieldIds);
        $this->assertContains('model', $fieldIds);
    }

    // ── Per-model thinking warnings ─────────────────────────────────────

    /**
     * @param array<string, string> $appValues
     */
    private function describeWithProfile(array $profile, array $appValues): array {
        $provider = $this->createStubForIntersectionOfInterfaces([LLMProviderInterface::class, ThinkingProfileInterface::class]);
        $provider->method('getId')->willReturn('testprovider');
        $provider->method('getLabel')->willReturn('Test provider');
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities());
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('getModel')->willReturn($profile['model']);
        $provider->method('getThinkingProfile')->willReturn($profile);
        $provider->method('getSettingsSchema')->willReturn([
            ProviderSettingsSchema::select('effort', 'effort', 'Effort', '', ['', 'low', 'high', 'xhigh', 'max']),
            ProviderSettingsSchema::select('thinking', 'thinking', 'Thinking', '', ['', 'on', 'off']),
        ]);
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default = '') => $appValues[$key] ?? $default);

        return $this->service->describe($provider, null, admin: true);
    }

    private static function profile(string $model, string $thinking, bool $canDisable, ?string $offMax, array $efforts): array {
        return [
            'model' => $model,
            'thinking' => $thinking,
            'can_disable' => $canDisable,
            'off_max_effort' => $offMax,
            'efforts' => $efforts,
            'default_effort' => 'xhigh',
        ];
    }

    public function testThinkingOffWarnsOnAlwaysOnModel(): void {
        $described = $this->describeWithProfile(
            self::profile('claude-opus-5-5', 'always_on', false, null, ['low', 'high', 'xhigh', 'max']),
            ['thinking' => 'off'],
        );
        $fields = array_column($described['fields'], null, 'id');
        $this->assertStringContainsString('always thinks', $fields['thinking']['warning']);
        $this->assertSame('always_on', $described['modelProfile']['thinking']);
    }

    public function testThinkingOffWarnsAboutEffortCap(): void {
        $described = $this->describeWithProfile(
            self::profile('claude-opus-5', 'adaptive_by_default', true, 'high', ['low', 'high', 'xhigh', 'max']),
            ['thinking' => 'off'],
        );
        $fields = array_column($described['fields'], null, 'id');
        $this->assertStringContainsString('at most at effort "high"', $fields['thinking']['warning']);
    }

    public function testUnsupportedEffortWarns(): void {
        $described = $this->describeWithProfile(
            self::profile('claude-sonnet-4-6', 'off_by_default', true, null, ['low', 'high', 'max']),
            ['effort' => 'xhigh'],
        );
        $fields = array_column($described['fields'], null, 'id');
        $this->assertStringContainsString('does not accept "xhigh"', $fields['effort']['warning']);
        $this->assertArrayNotHasKey('warning', $fields['thinking']);
    }

    public function testNoProfileWithoutInterface(): void {
        $provider = $this->provider($this->mixedScopeSchema());
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities());
        $provider->method('getModel')->willReturn('default-model');
        $this->cache->method('get')->willReturn(['default-model']);

        $this->assertNull($this->service->describe($provider, null, admin: true)['modelProfile']);
    }

    // ── Value validation ────────────────────────────────────────────────

    public function testAdminWriteRejectsNonHttpEndpoint(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->never())->method('setAppValue');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeAdmin($provider, ['test_base_url' => 'file:///etc/passwd']);
    }

    public function testAdminWriteRejectsNonNumericMaxTokens(): void {
        $provider = $this->provider($this->mixedScopeSchema());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeAdmin($provider, ['max_tokens' => 'lots']);
    }

    public function testAdminWriteIsAllOrNothing(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        // The valid field must not be written when a later one fails
        // validation, otherwise a rejected save leaves half its changes behind.
        $this->config->expects($this->never())->method('setAppValue');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeAdmin($provider, [
            'model' => 'a-model',
            'max_tokens' => 'nope',
        ]);
    }

    public function testAdminWriteAcceptsValidEndpoint(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with('aiquila', 'test_base_url', 'http://localhost:11434');

        $rejected = $this->service->writeAdmin($provider, ['test_base_url' => 'http://localhost:11434']);

        $this->assertSame([], $rejected);
    }

    // ── Checkbox storage ────────────────────────────────────────────────

    public function testCheckboxStoresProviderSpecificRepresentation(): void {
        $provider = $this->provider([
            ProviderSettingsSchema::checkbox('yes_flag', 'yes_flag', 'Yes flag', ''),
            ProviderSettingsSchema::checkbox(
                'bool_flag',
                'bool_flag',
                'Bool flag',
                '',
                storage: ProviderSettingsSchema::STORAGE_BOOL,
            ),
        ]);

        $written = [];
        $this->config->method('setAppValue')
            ->willReturnCallback(function ($app, $key, $value) use (&$written): void {
                $written[$key] = $value;
            });

        $this->service->writeAdmin($provider, ['yes_flag' => true, 'bool_flag' => true]);

        $this->assertSame(['yes_flag' => 'yes', 'bool_flag' => 'true'], $written);
    }

    // ── Model list caching ──────────────────────────────────────────────

    public function testStatusPassesTheProbeThroughWithTheProviderId(): void {
        $provider = $this->provider([], mock: true);
        $provider->expects($this->once())->method('probe')->with('alice')->willReturn([
            'state' => ProviderProbe::STATE_DEGRADED,
            'reason' => ProviderProbe::REASON_MODEL_MISSING,
            'message' => 'not offered',
            'model' => 'gone-1',
        ]);

        $status = $this->service->status($provider, 'alice', admin: false);

        $this->assertSame('testprovider', $status['providerId']);
        $this->assertSame(ProviderProbe::STATE_DEGRADED, $status['state']);
        $this->assertSame('gone-1', $status['model']);
    }

    /** The admin card describes the instance, so its probe must ignore the caller. */
    public function testAdminStatusProbesWithoutAUser(): void {
        $provider = $this->provider([], mock: true);
        $provider->expects($this->once())->method('probe')->with(null)->willReturn([
            'state' => ProviderProbe::STATE_OK,
            'reason' => ProviderProbe::REASON_OK,
            'message' => 'fine',
            'model' => 'm',
        ]);

        $this->service->status($provider, 'alice', admin: true);
    }

    public function testCachedModelListSkipsTheProvider(): void {
        $provider = $this->provider([], mock: true);
        $provider->expects($this->never())->method('listModels');
        $this->cache->method('get')->willReturn(['cached-a', 'cached-b']);

        $this->assertSame(['cached-a', 'cached-b'], $this->service->listModels($provider, 'alice'));
    }

    public function testRefreshBypassesTheCacheAndStoresTheResult(): void {
        $this->cache = $this->createMock(ICache::class);
        $this->buildService();

        $provider = $this->provider([], mock: true);
        $provider->expects($this->once())->method('listModels')->willReturn(['live-a']);
        $this->cache->expects($this->never())->method('get');
        $this->cache->expects($this->once())->method('set')->with('instance', ['live-a'], 3600);

        $this->assertSame(['live-a'], $this->service->listModels($provider, 'alice', refresh: true));
    }

    public function testUnreachableProviderFallsBackAndRemembersTheFailure(): void {
        $this->cache = $this->createMock(ICache::class);
        $this->buildService();

        $provider = $this->provider([]);
        $provider->method('listModels')->willReturn(null);
        $provider->method('getModel')->willReturn('configured-tag');
        $this->cache->method('get')->willReturn(null);

        // The fallback itself is never cached — it is static anyway — but the
        // failure is, briefly, so a dead endpoint is not re-asked per page load.
        $this->cache->expects($this->once())->method('set')
            ->with('instance', ['__aiquila_failed' => true], 60);

        $this->assertSame(['configured-tag'], $this->service->listModels($provider, 'alice'));
    }

    public function testCachedFailureServesTheFallbackWithoutCallingTheProvider(): void {
        $provider = $this->provider([], mock: true);
        $provider->expects($this->never())->method('listModels');
        $provider->method('getModel')->willReturn('configured-tag');
        $this->cache->method('get')->willReturn(['__aiquila_failed' => true]);

        $this->assertSame(['configured-tag'], $this->service->listModels($provider, 'alice'));
    }

    public function testRefreshBypassesACachedFailure(): void {
        $this->cache = $this->createMock(ICache::class);
        $this->buildService();

        $provider = $this->provider([], mock: true);
        $provider->expects($this->once())->method('listModels')->willReturn(['live-a']);
        $this->cache->expects($this->never())->method('get');

        $this->assertSame(['live-a'], $this->service->listModels($provider, 'alice', refresh: true));
    }

    /**
     * A personal key talks to the provider as that user and can see a different
     * line-up, so it must not share the instance entry.
     */
    public function testPersonalKeyGetsItsOwnCacheEntry(): void {
        $provider = $this->provider([]);
        $provider->method('listModels')->willReturn(['live-a']);
        $this->credentials->method('hasApiKey')
            ->willReturnCallback(static fn (?string $uid): bool => $uid === 'alice');
        $this->cache->method('get')->willReturn(null);

        $keys = [];
        $this->cache->method('set')->willReturnCallback(
            function (string $key) use (&$keys): bool {
                $keys[] = $key;
                return true;
            }
        );

        $this->service->listModels($provider, 'alice');
        $this->service->listModels($provider, 'bob');
        $this->service->listModels($provider, null);

        $this->assertSame(['user-alice', 'instance', 'instance'], $keys);
    }

    public function testForgetModelsClearsEveryScopeOfTheProvider(): void {
        $this->cache = $this->createMock(ICache::class);
        $this->buildService();

        // remove() cannot drop the per-user entries, so each provider owns a
        // cache namespace and the sweep clears the whole thing.
        $this->cache->expects($this->once())->method('clear');
        $this->cache->expects($this->never())->method('remove');

        $this->service->forgetModels('testprovider');
    }

    public function testStatusIsReusedWithinTheThrottleWindow(): void {
        $this->cache = $this->createMock(ICache::class);
        $this->buildService();

        $provider = $this->provider([], mock: true);
        $provider->method('getId')->willReturn('testprovider');
        $provider->expects($this->once())->method('probe')->willReturn(
            ProviderProbe::unconfigured('no key', 'some-model')
        );
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('set')
            ->with('status-admin', $this->anything(), 30);

        $this->service->status($provider, 'alice', admin: true);
    }

    // ── Named secrets and formats (#446) ────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function secretSchema(): array {
        return [
            ProviderSettingsSchema::secretTextarea(
                'extra_headers',
                'test_extra_headers',
                'Additional request headers',
                '',
                format: ProviderSettingsSchema::FORMAT_HEADERS,
            ),
            ProviderSettingsSchema::text(
                'auth_header',
                'auth_header',
                'Header name',
                '',
                format: ProviderSettingsSchema::FORMAT_HEADER_NAME,
            ),
            ProviderSettingsSchema::text(
                'ca_bundle',
                'ca_bundle',
                'CA bundle path',
                '',
                format: ProviderSettingsSchema::FORMAT_FILE_PATH,
            ),
        ];
    }

    public function testNamedSecretGoesToTheCredentialStoreNotConfig(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->buildService();

        $provider = $this->provider($this->secretSchema());

        $this->config->expects($this->never())->method('setAppValue');
        $this->credentials->expects($this->once())
            ->method('setSecret')
            ->with('test_extra_headers', 'X-Tenant: acme');

        $this->service->writeAdmin($provider, ['extra_headers' => 'X-Tenant: acme']);
    }

    public function testEmptyNamedSecretClearsIt(): void {
        $this->credentials = $this->createMock(CredentialService::class);
        $this->buildService();

        $provider = $this->provider($this->secretSchema());

        $this->credentials->expects($this->once())->method('deleteSecret')->with('test_extra_headers');
        $this->credentials->expects($this->never())->method('setSecret');

        $this->service->writeAdmin($provider, ['extra_headers' => '']);
    }

    public function testNamedSecretIsNeverReturnedToTheClient(): void {
        $provider = $this->provider($this->secretSchema());
        $this->credentials->method('hasSecret')->willReturnCallback(
            fn(string $name) => $name === 'test_extra_headers'
        );

        $described = $this->service->describe($provider, null, true);
        $field = $this->fieldById($described, 'extra_headers');

        $this->assertTrue($field['hasValue']);
        $this->assertArrayNotHasKey('value', $field);
    }

    public function testNamedSecretIsNotOfferedOnThePersonalPage(): void {
        // It is SCOPE_ADMIN, and the local provider's headers are exactly the
        // thing a user must not be able to point at their own endpoint.
        $provider = $this->provider($this->secretSchema());

        $described = $this->service->describe($provider, 'alice', false);
        $ids = array_column($described['fields'], 'id');

        $this->assertNotContains('extra_headers', $ids);
    }

    public function testMalformedHeaderBlockIsRejectedBeforeStorage(): void {
        $this->credentials = $this->createMock(CredentialService::class);
        $this->buildService();

        $provider = $this->provider($this->secretSchema());

        $this->credentials->expects($this->never())->method('setSecret');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('cannot be overridden');
        $this->service->writeAdmin($provider, ['extra_headers' => 'Authorization: Bearer x']);
    }

    public function testInvalidHeaderNameIsRejected(): void {
        $provider = $this->provider($this->secretSchema());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('not a valid header name');
        $this->service->writeAdmin($provider, ['auth_header' => 'X API Key']);
    }

    public function testRelativeCertificatePathIsRejected(): void {
        $provider = $this->provider($this->secretSchema());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('absolute path');
        $this->service->writeAdmin($provider, ['ca_bundle' => 'certs/ca.pem']);
    }

    public function testUnreadableCertificatePathIsRejected(): void {
        $provider = $this->provider($this->secretSchema());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('not readable by the web server');
        $this->service->writeAdmin($provider, ['ca_bundle' => '/nope/ca.pem']);
    }

    public function testReadableCertificatePathIsAccepted(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider($this->secretSchema());
        $path = tempnam(sys_get_temp_dir(), 'aiquila-ca');

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with('aiquila', 'ca_bundle', $path);

        try {
            $this->assertSame([], $this->service->writeAdmin($provider, ['ca_bundle' => $path]));
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $described */
    private function fieldById(array $described, string $id): array {
        foreach ($described['fields'] as $field) {
            if ($field['id'] === $id) {
                return $field;
            }
        }
        $this->fail('no such field: ' . $id);
    }

    // ── Cloud endpoint formats ──────────────────────────────────────────

    public function testCloudEndpointPartsAreValidatedBeforeStorage(): void {
        $this->config = $this->createMock(AppSettings::class);
        $this->buildService();

        $provider = $this->provider([
            ProviderSettingsSchema::text('region', 'region_test', 'AWS region', '', format: ProviderSettingsSchema::FORMAT_AWS_REGION),
            ProviderSettingsSchema::text('resource', 'resource_test', 'Foundry resource', '', format: ProviderSettingsSchema::FORMAT_AZURE_RESOURCE),
            ProviderSettingsSchema::text('model', 'model_test', 'Model', '', format: ProviderSettingsSchema::FORMAT_MODEL_ID),
        ]);
        $this->config->expects($this->never())->method('setAppValue');

        foreach ([
            ['region' => 'us-east-1.evil.example'],
            ['resource' => 'evil.example/x'],
            ['model' => '../other'],
        ] as $values) {
            try {
                $this->service->writeAdmin($provider, $values);
                $this->fail('accepted ' . json_encode($values));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testServiceAccountKeyMustBeJson(): void {
        $this->credentials = $this->createMock(CredentialService::class);
        $this->buildService();

        $provider = $this->provider([
            ProviderSettingsSchema::secretTextarea('service_account', 'test_sa', 'Key', '', format: ProviderSettingsSchema::FORMAT_JSON_OBJECT),
        ]);
        $this->credentials->expects($this->never())->method('setSecret');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->writeAdmin($provider, ['service_account' => 'not json']);
    }
}
