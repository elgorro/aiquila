<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Capabilities\AIquilaCapability;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\CredentialService;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class AIquilaCapabilityTest extends TestCase {
    private IConfig $config;
    private CredentialService $credentialService;
    private IAppManager $appManager;
    private AIquilaCapability $capability;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->credentialService = $this->createMock(CredentialService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->capability = new AIquilaCapability(
            $this->config,
            $this->credentialService,
            $this->appManager,
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
                ['aiquila', 'model', ClaudeModels::DEFAULT_MODEL, 'claude-opus-4-20250514'],
                ['aiquila', 'search_enabled', 'yes', 'yes'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertEquals('claude-opus-4-20250514', $result['aiquila']['model']);
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
                ['aiquila', 'search_enabled', 'yes', 'no'],
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
                ['aiquila', 'search_enabled', 'yes', 'yes'],
            ]);
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertTrue($result['aiquila']['search_enabled']);
    }

    public function testProvidersIncludesTextGeneration(): void {
        $this->appManager->method('getAppVersion')->willReturn('1.0.0');
        $this->config->method('getAppValue')->willReturn('');
        $this->credentialService->method('getApiKey')->willReturn('');

        $result = $this->capability->getCapabilities();

        $this->assertContains('text-generation', $result['aiquila']['providers']);
    }
}
