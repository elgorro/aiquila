<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Capabilities\AIquilaCapability;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class AIquilaCapabilityTest extends TestCase {
    private IConfig $config;
    private CredentialService $credentialService;
    private IAppManager $appManager;
    private AIquilaCapability $capability;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->credentialService = $this->createMock(CredentialService::class);
        $this->appManager = $this->createMock(IAppManager::class);

        // Providers only need their DI deps to run tasks; getTaskTypeId() touches none.
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor()
        );

        $this->capability = new AIquilaCapability(
            $this->config,
            $this->credentialService,
            $this->appManager,
            $container,
        );
    }

    public function testReturnsCorrectStructure(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertArrayHasKey('aiquila', $result);
        $aiquila = $result['aiquila'];
        $this->assertArrayHasKey('version', $aiquila);
        $this->assertArrayHasKey('model', $aiquila);
        $this->assertArrayHasKey('providers', $aiquila);
        $this->assertArrayHasKey('api_configured', $aiquila);
        $this->assertArrayHasKey('search_enabled', $aiquila);
    }

    public function testVersionFromAppManager(): void {
        $this->appManager->method('getAppVersion')
            ->with('aiquila')
            ->willReturn('2.5.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertEquals('2.5.0', $result['aiquila']['version']);
    }

    public function testModelReflectsConfig(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['aiquila', 'model', ClaudeModels::DEFAULT_MODEL, ClaudeModels::OPUS_4_7],
                ['aiquila', 'search_enabled', '1', '1'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertEquals(ClaudeModels::OPUS_4_7, $result['aiquila']['model']);
    }

    public function testApiConfiguredTrueWhenKeyExists(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')
            ->with(null)
            ->willReturn('sk-ant-test-key');

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['aiquila']['api_configured']);
    }

    public function testApiConfiguredFalseWhenKeyEmpty(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')
            ->with(null)
            ->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertFalse($result['aiquila']['api_configured']);
    }

    public function testSearchEnabledReflectsConfig(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['aiquila', 'model', ClaudeModels::DEFAULT_MODEL, ClaudeModels::DEFAULT_MODEL],
                ['aiquila', 'search_enabled', '1', '0'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertFalse($result['aiquila']['search_enabled']);
    }

    public function testSearchEnabledDefaultsToTrue(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')
            ->willReturnMap([
                ['aiquila', 'model', ClaudeModels::DEFAULT_MODEL, ClaudeModels::DEFAULT_MODEL],
                ['aiquila', 'search_enabled', '1', '1'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

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
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        return $this->capability->getCapabilities()['aiquila']['providers'];
    }
}
