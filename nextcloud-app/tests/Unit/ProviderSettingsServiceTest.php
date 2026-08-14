<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderAccessService;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
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
    private ProviderSettingsService $service;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->access = $this->createMock(ProviderAccessService::class);
        $this->access->method('getLists')->willReturn([
            'allowed_users' => [],
            'allowed_groups' => [],
            'blocked_users' => [],
            'blocked_groups' => [],
        ]);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new ProviderSettingsService(
            $this->config,
            $this->credentials,
            $this->access,
            $this->userManager,
            $this->groupManager,
            $this->cacheFactory,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * A stand-in provider carrying one field of every scope, so the tests
     * exercise the scope rules rather than any single real provider's schema.
     *
     * @param list<array<string, mixed>> $schema
     */
    private function provider(array $schema): LLMProviderInterface {
        $provider = $this->createMock(LLMProviderInterface::class);
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
        $provider = $this->provider($this->mixedScopeSchema());

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'aiquila', 'user_model_test', 'some-model');

        $rejected = $this->service->writeUser($provider, 'alice', ['model' => 'some-model']);

        $this->assertSame([], $rejected);
    }

    public function testUserWriteRejectsUnknownField(): void {
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

    // ── Value validation ────────────────────────────────────────────────

    public function testAdminWriteRejectsNonHttpEndpoint(): void {
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

    public function testCachedModelListSkipsTheProvider(): void {
        $provider = $this->provider([]);
        $provider->expects($this->never())->method('listModels');
        $this->cache->method('get')->willReturn(['cached-a', 'cached-b']);

        $this->assertSame(['cached-a', 'cached-b'], $this->service->listModels($provider, 'alice'));
    }

    public function testRefreshBypassesTheCacheAndStoresTheResult(): void {
        $provider = $this->provider([]);
        $provider->expects($this->once())->method('listModels')->willReturn(['live-a']);
        $this->cache->expects($this->never())->method('get');
        $this->cache->expects($this->once())->method('set')->with('testprovider', ['live-a'], 3600);

        $this->assertSame(['live-a'], $this->service->listModels($provider, 'alice', refresh: true));
    }

    public function testUnreachableProviderFallsBackWithoutCaching(): void {
        $provider = $this->provider([]);
        $provider->method('listModels')->willReturn(null);
        $provider->method('getModel')->willReturn('configured-tag');
        $this->cache->method('get')->willReturn(null);

        // Caching the fallback would pin a transient outage for the full TTL.
        $this->cache->expects($this->never())->method('set');

        $this->assertSame(['configured-tag'], $this->service->listModels($provider, 'alice'));
    }
}
