<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\MistralProvider;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LLMProviderFactoryTest extends TestCase {
    private $config;
    private $anthropic;
    private $mistral;
    private LLMProviderFactory $factory;

    protected function setUp(): void {
        $this->config    = $this->createMock(IConfig::class);
        $this->anthropic = $this->createMock(ClaudeSDKService::class);
        $this->mistral   = $this->createMock(MistralProvider::class);
        $this->anthropic->method('getId')->willReturn('anthropic');
        $this->mistral->method('getId')->willReturn('mistral');

        $this->factory = new LLMProviderFactory($this->config, $this->anthropic, $this->mistral);
    }

    public function testDefaultsToAnthropic(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);

        $this->assertSame('anthropic', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->anthropic, $this->factory->getProvider('u'));
    }

    public function testAdminDefaultProviderHonored(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('mistral');

        $this->assertSame('mistral', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->mistral, $this->factory->getProvider('u'));
    }

    public function testUserOverrideWinsOverAdminDefault(): void {
        $this->config->method('getUserValue')->willReturn('mistral');
        $this->config->method('getAppValue')->willReturn('anthropic');

        $this->assertSame('mistral', $this->factory->getActiveProviderId('u'));
    }

    public function testUnknownProviderFallsBackToAnthropic(): void {
        $this->config->method('getUserValue')->willReturn('bogus');
        $this->config->method('getAppValue')->willReturn('also-bogus');

        $this->assertSame('anthropic', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->anthropic, $this->factory->getProviderById('nope'));
    }

    public function testDescribeProviders(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->anthropic->method('getLabel')->willReturn('Claude (Anthropic)');
        $this->mistral->method('getLabel')->willReturn('Mistral');
        $this->anthropic->method('isConfigured')->willReturn(true);
        $this->mistral->method('isConfigured')->willReturn(false);

        $described = $this->factory->describeProviders('u');
        $this->assertCount(2, $described);
        $this->assertSame('anthropic', $described[0]['id']);
        $this->assertTrue($described[0]['configured']);
        $this->assertSame('mistral', $described[1]['id']);
        $this->assertFalse($described[1]['configured']);
    }
}
