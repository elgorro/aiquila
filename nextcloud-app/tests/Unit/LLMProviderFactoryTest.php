<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\Cloud\BedrockInvokeProvider;
use OCA\AIquila\Service\Provider\Cloud\BedrockProvider;
use OCA\AIquila\Service\Provider\Cloud\ClaudePlatformAwsProvider;
use OCA\AIquila\Service\Provider\Cloud\ClaudePlatformGoogleCloudProvider;
use OCA\AIquila\Service\Provider\Cloud\FoundryProvider;
use OCA\AIquila\Service\Provider\Cloud\VertexProvider;
use OCA\AIquila\Service\Provider\DeepSeekProvider;
use OCA\AIquila\Service\Provider\HetznerProvider;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LocalProvider;
use OCA\AIquila\Service\Provider\MistralProvider;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\Provider\ProviderAccessService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LLMProviderFactoryTest extends TestCase {
    private $config;
    private $anthropic;
    private $mistral;
    private $deepseek;
    private $hetzner;
    private $local;
    private $access;
    /** @var array<string, object> */
    private array $cloud = [];
    private LLMProviderFactory $factory;

    protected function setUp(): void {
        $this->config    = $this->createStub(IConfig::class);
        $this->anthropic = $this->createStub(ClaudeSDKService::class);
        $this->mistral   = $this->createStub(MistralProvider::class);
        $this->deepseek  = $this->createStub(DeepSeekProvider::class);
        $this->hetzner   = $this->createStub(HetznerProvider::class);
        $this->local     = $this->createStub(LocalProvider::class);
        $this->anthropic->method('getId')->willReturn('anthropic');
        $this->mistral->method('getId')->willReturn('mistral');
        $this->deepseek->method('getId')->willReturn('deepseek');
        $this->hetzner->method('getId')->willReturn('hetzner');
        $this->local->method('getId')->willReturn('local');
        foreach ([
            'claude_aws' => ClaudePlatformAwsProvider::class,
            'claude_gcp' => ClaudePlatformGoogleCloudProvider::class,
            'bedrock' => BedrockProvider::class,
            'bedrock_invoke' => BedrockInvokeProvider::class,
            'vertex' => VertexProvider::class,
            'foundry' => FoundryProvider::class,
        ] as $id => $class) {
            $mock = $this->createStub($class);
            $mock->method('getId')->willReturn($id);
            $this->cloud[$id] = $mock;
        }

        // Access control off by default: filterAllowed() returns everything, so
        // these tests keep asserting the plain precedence rules.
        $this->access = $this->createStub(ProviderAccessService::class);
        $this->access->method('filterAllowed')->willReturnCallback(
            static fn (array $ids, ?string $userId): array => $ids,
        );
        $this->access->method('isAllowed')->willReturn(true);

        $this->factory = new LLMProviderFactory($this->config, $this->anthropic, $this->mistral, $this->deepseek, $this->hetzner, $this->local, $this->access, ...array_values($this->cloud));
    }

    /** Rebuild the factory with only $permitted allowed for every user. */
    private function permitOnly(array $permitted): void {
        $access = $this->createStub(ProviderAccessService::class);
        $access->method('filterAllowed')->willReturnCallback(
            static fn (array $ids, ?string $userId): array => array_values(array_intersect($ids, $permitted)),
        );
        $access->method('isAllowed')->willReturnCallback(
            static fn (string $id, ?string $userId): bool => in_array($id, $permitted, true),
        );
        $this->factory = new LLMProviderFactory($this->config, $this->anthropic, $this->mistral, $this->deepseek, $this->hetzner, $this->local, $access, ...array_values($this->cloud));
    }

    public function testCloudProvidersAreRegisteredAfterAnthropic(): void {
        $ids = $this->factory->getProviderIds();
        $this->assertSame(
            ['anthropic', 'claude_aws', 'claude_gcp', 'bedrock', 'bedrock_invoke', 'vertex', 'foundry'],
            array_slice($ids, 0, 7),
        );
        $this->assertSame($this->cloud['bedrock'], $this->factory->getProviderById('bedrock'));
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

    public function testDeepSeekProviderResolves(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('deepseek');

        $this->assertSame('deepseek', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->deepseek, $this->factory->getProvider('u'));
        $this->assertContains('deepseek', $this->factory->getProviderIds());
    }

    public function testHetznerProviderResolves(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('hetzner');

        $this->assertSame('hetzner', $this->factory->getActiveProviderId('u'));
        $this->assertSame($this->hetzner, $this->factory->getProvider('u'));
        $this->assertContains('hetzner', $this->factory->getProviderIds());
    }

    public function testDescribeProviders(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->anthropic->method('getLabel')->willReturn('Claude (Anthropic)');
        $this->mistral->method('getLabel')->willReturn('Mistral');
        $this->deepseek->method('getLabel')->willReturn('DeepSeek');
        $this->hetzner->method('getLabel')->willReturn('Hetzner Inference (EU)');
        $this->local->method('getLabel')->willReturn('Local model');
        $this->anthropic->method('isConfigured')->willReturn(true);
        $this->mistral->method('isConfigured')->willReturn(false);
        $this->deepseek->method('isConfigured')->willReturn(false);
        $this->hetzner->method('isConfigured')->willReturn(false);
        $this->local->method('isConfigured')->willReturn(false);

        $described = $this->factory->describeProviders('u');
        $this->assertCount(11, $described);
        $byId = array_column($described, null, 'id');
        $this->assertSame('anthropic', $described[0]['id']);
        $this->assertTrue($described[0]['configured']);
        foreach (['mistral', 'deepseek', 'hetzner', 'local', 'bedrock', 'vertex', 'foundry'] as $id) {
            $this->assertFalse($byId[$id]['configured'], $id);
        }
        $this->assertSame(
            ['anthropic', 'claude_aws', 'claude_gcp', 'bedrock', 'bedrock_invoke', 'vertex', 'foundry', 'mistral', 'deepseek', 'hetzner', 'local'],
            array_column($described, 'id'),
        );
    }

    public function testDeniedUserOverrideFallsBackToTheInstanceDefault(): void {
        // The user picked Mistral, but the admin has since blocked it for them.
        $this->config->method('getUserValue')->willReturn('mistral');
        $this->config->method('getAppValue')->willReturn('hetzner');
        $this->permitOnly(['hetzner', 'local']);

        $this->assertSame('hetzner', $this->factory->getActiveProviderId('u'));
    }

    public function testDeniedInstanceDefaultFallsThroughToAPermittedProvider(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('anthropic');
        $this->permitOnly(['deepseek', 'local']);

        // Display order decides, so deepseek wins over local.
        $this->assertSame('deepseek', $this->factory->getActiveProviderId('u'));
    }

    public function testNoPermittedProviderFailsClosed(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->permitOnly([]);

        $this->expectException(NoPermittedProviderException::class);
        $this->factory->getActiveProviderId('u');
    }

    public function testGetProviderForUserDegradesADeniedPin(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('local');
        $this->permitOnly(['local']);

        // The conversation is pinned to Mistral, which is now blocked.
        $this->assertSame($this->local, $this->factory->getProviderForUser('u', 'mistral'));
    }

    public function testGetProviderForUserHonoursAPermittedPin(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('local');
        $this->permitOnly(['local', 'mistral']);

        $this->assertSame($this->mistral, $this->factory->getProviderForUser('u', 'mistral'));
    }

    public function testSystemContextIsUnrestricted(): void {
        $this->config->method('getAppValue')->willReturn('mistral');
        $this->assertSame('mistral', $this->factory->getActiveProviderId(null));
    }
}
