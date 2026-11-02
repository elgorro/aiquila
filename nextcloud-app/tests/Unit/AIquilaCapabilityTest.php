<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Capabilities\AIquilaCapability;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\SearchSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use OCA\AIquila\Service\AppSettings;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class AIquilaCapabilityTest extends TestCase {
    private AppSettings $config;
    private LLMProviderFactory $factory;
    private IUserSession $userSession;
    private IAppManager $appManager;
    private AIquilaCapability $capability;

    protected function setUp(): void {
        $this->config = $this->createStub(AppSettings::class);
        $this->factory = $this->createStub(LLMProviderFactory::class);
        $this->userSession = $this->createStub(IUserSession::class);
        $this->appManager = $this->createStub(IAppManager::class);
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');

        $this->buildCapability();
    }

    private function buildCapability(): void {
        // Providers only need their DI deps to run tasks; getTaskTypeId() touches none.
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor()
        );

        $this->capability = new AIquilaCapability(
            $this->factory,
            $this->userSession,
            $this->appManager,
            $container,
            new SearchSettings($this->config),
        );
    }

    private function provider(string $id, string $model, bool $configured): LLMProviderInterface {
        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getModel')->willReturn($model);
        $provider->method('isConfigured')->willReturn($configured);
        return $provider;
    }

    private function signIn(string $uid): void {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testReturnsCorrectStructure(): void {
        $result = $this->capability->getCapabilities();

        $this->assertArrayHasKey('aiquila', $result);
        $this->assertSame(
            ['version', 'provider', 'model', 'user_provider', 'user_model', 'providers', 'api_configured', 'search_enabled'],
            array_keys($result['aiquila'])
        );
    }

    public function testVersionFromAppManager(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->buildCapability();

        $this->appManager->expects($this->once())->method('getAppVersion')
            ->with('aiquila')
            ->willReturn('2.5.0');

        $result = $this->capability->getCapabilities();

        $this->assertEquals('2.5.0', $result['aiquila']['version']);
    }

    public function testModelIsTheEffectiveDefaultProviders(): void {
        // A non-Claude default: the Claude `model` key must not leak through.
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default): string => $key === 'model' ? 'claude-sonnet-5' : $default
        );
        $this->factory->method('getProvider')->willReturnMap([
            [null, $this->provider('mistral', 'mistral-large-latest', true)],
        ]);

        $aiquila = $this->capability->getCapabilities()['aiquila'];

        $this->assertSame('mistral', $aiquila['provider']);
        $this->assertSame('mistral-large-latest', $aiquila['model']);
        $this->assertTrue($aiquila['api_configured']);
    }

    public function testApiConfiguredFollowsTheDefaultProvider(): void {
        $this->factory->method('getProvider')->willReturn($this->provider('hetzner', 'm', false));

        $this->assertFalse($this->capability->getCapabilities()['aiquila']['api_configured']);
    }

    public function testUserFieldsReflectTheSignedInUsersOverride(): void {
        $this->signIn('alice');
        $this->factory->method('getProvider')->willReturnMap([
            [null, $this->provider('anthropic', 'claude-opus-5', true)],
            ['alice', $this->provider('deepseek', 'deepseek-chat', true)],
        ]);

        $aiquila = $this->capability->getCapabilities()['aiquila'];

        $this->assertSame('anthropic', $aiquila['provider']);
        $this->assertSame('claude-opus-5', $aiquila['model']);
        $this->assertSame('deepseek', $aiquila['user_provider']);
        $this->assertSame('deepseek-chat', $aiquila['user_model']);
    }

    public function testUserFieldsAreNullWithoutASignedInUser(): void {
        $this->factory->method('getProvider')->willReturn($this->provider('anthropic', 'claude-opus-5', true));

        $aiquila = $this->capability->getCapabilities()['aiquila'];

        $this->assertNull($aiquila['user_provider']);
        $this->assertNull($aiquila['user_model']);
    }

    public function testEveryProviderDeniedReportsNothingConfigured(): void {
        $this->signIn('bob');
        $this->factory->method('getProvider')->willThrowException(new NoPermittedProviderException('bob'));

        $aiquila = $this->capability->getCapabilities()['aiquila'];

        $this->assertNull($aiquila['provider']);
        $this->assertNull($aiquila['model']);
        $this->assertNull($aiquila['user_provider']);
        $this->assertFalse($aiquila['api_configured']);
    }

    public function testSearchEnabledReflectsConfig(): void {
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['aiquila', SearchSettings::KEY, SearchSettings::DEFAULT, '0'],
            ]);

        $result = $this->capability->getCapabilities();

        $this->assertFalse($result['aiquila']['search_enabled']);
    }

    public function testSearchEnabledWhenStoredOn(): void {
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['aiquila', SearchSettings::KEY, SearchSettings::DEFAULT, '1'],
            ]);

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['aiquila']['search_enabled']);
    }

    public function testSearchEnabledDefaultsToTrueWhenUnset(): void {
        // An unset app value hands back whatever default the reader passed.
        $this->config->method('getAppValue')
            ->willReturnCallback(fn (string $app, string $key, string $default): string => $default);

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['aiquila']['search_enabled']);
    }

    public function testProvidersMatchRegisteredSet(): void {
        $registered = [];
        $context = $this->createStub(IRegistrationContext::class);
        $context->method('registerTaskProcessingProvider')->willReturnCallback(
            function (string $class) use (&$registered): void {
                $registered[] = $class;
            }
        );
        (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);

        $expected = [];
        foreach ($registered as $class) {
            $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->assertInstanceOf(ISynchronousProvider::class, $provider);
            $expected[] = $provider->getTaskTypeId();
        }
        $expected = array_values(array_unique($expected));
        sort($expected);

        $this->assertNotEmpty($registered);
        $this->assertSame($expected, $this->providers());
    }

    public function testProvidersAreSortedUniqueTaskTypeIds(): void {
        $providers = $this->providers();

        foreach ($providers as $id) {
            $this->assertMatchesRegularExpression('/^core:[a-z0-9:-]+$/', $id);
        }
        $sorted = $providers;
        sort($sorted);
        $this->assertSame($sorted, $providers);
        $this->assertSame(array_values(array_unique($providers)), $providers);

        $this->assertContains(TextToText::ID, $providers);
        $this->assertContains(TextToTextSummary::ID, $providers);
        $this->assertContains(AudioToText::ID, $providers);
    }

    /** @return list<string> */
    private function providers(): array {
        return $this->capability->getCapabilities()['aiquila']['providers'];
    }
}
