<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Command;

use OCA\AIquila\Command\ConfigureCommand;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderAccessService;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `aiquila:configure` provider targeting: a second provider can be set up
 * without becoming the instance default, and provider settings go through the
 * same schema validation as the admin page.
 */
class ConfigureCommandTest extends TestCase {
    /** @var array<string, string> app config, keyed by config key */
    private array $appConfig = [];
    /** @var array<string, string> api keys, keyed by provider id */
    private array $apiKeys = [];
    private CommandTester $tester;

    protected function setUp(): void {
        $config = $this->createStub(AppSettings::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => $this->appConfig[$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appConfig[$key] = $value;
            }
        );

        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('setApiKey')->willReturnCallback(
            function (?string $userId, string $key, string $provider = 'anthropic'): void {
                $this->apiKeys[$provider] = $key;
            }
        );
        $credentials->method('hasApiKey')->willReturnCallback(
            fn(?string $userId, string $provider = 'anthropic') => isset($this->apiKeys[$provider])
        );

        $providers = [
            'anthropic' => $this->provider('anthropic', 'model'),
            'local' => $this->provider('local', 'model_local', [
                ProviderSettingsSchema::baseUrl('local_base_url', 'Endpoint URL', ''),
                ProviderSettingsSchema::timeout('local_timeout', 600),
                ProviderSettingsSchema::checkbox('local_vision', 'local_vision', 'Vision', ''),
                ProviderSettingsSchema::action('local_pull', 'Pull', 'Pull'),
            ]),
        ];

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderIds')->willReturn(array_keys($providers));
        $factory->method('getProviderById')->willReturnCallback(fn(string $id) => $providers[$id]);
        $factory->method('getActiveProviderId')->willReturnCallback(
            fn() => $this->appConfig['provider'] ?? 'anthropic'
        );

        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createStub(ICache::class));

        $settings = new ProviderSettingsService(
            $config,
            $credentials,
            $this->createStub(ProviderAccessService::class),
            $this->createStub(IUserManager::class),
            $this->createStub(IGroupManager::class),
            $cacheFactory,
            $factory,
            $this->createStub(LoggerInterface::class),
        );

        $this->tester = new CommandTester(new ConfigureCommand($config, $credentials, $factory, $settings));
    }

    /** @param list<array<string, mixed>> $extra */
    private function provider(string $id, string $modelKey, array $extra = []): LLMProviderInterface {
        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getLabel')->willReturn(ucfirst($id));
        $provider->method('getSettingsSchema')->willReturn(array_merge([
            ProviderSettingsSchema::apiKey('API key', optional: $id === 'local'),
            ProviderSettingsSchema::model($modelKey, 'user_' . $modelKey, 'default-model'),
        ], $extra));
        $provider->method('getModel')->willReturn('default-model');
        return $provider;
    }

    public function testTargetProviderLeavesTheDefaultAlone(): void {
        $this->appConfig['provider'] = 'anthropic';

        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-key' => 'llama-token',
            '--provider-model' => 'qwen3',
        ]);

        $this->assertSame(0, $code, $this->tester->getDisplay());
        $this->assertSame('anthropic', $this->appConfig['provider']);
        $this->assertSame('llama-token', $this->apiKeys['local']);
        $this->assertSame('qwen3', $this->appConfig['model_local']);
        $this->assertArrayNotHasKey('model', $this->appConfig);
    }

    public function testProviderAloneStillTargetsAndActivates(): void {
        $code = $this->tester->execute([
            '--provider' => 'local',
            '--provider-model' => 'qwen3',
        ]);

        $this->assertSame(0, $code, $this->tester->getDisplay());
        $this->assertSame('local', $this->appConfig['provider']);
        $this->assertSame('qwen3', $this->appConfig['model_local']);
    }

    public function testProviderSettingsAreValidatedAndStored(): void {
        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-setting' => ['local_base_url=http://llama:8080', 'timeout=900', 'local_vision=on'],
        ]);

        $this->assertSame(0, $code, $this->tester->getDisplay());
        $this->assertSame('http://llama:8080', $this->appConfig['local_base_url']);
        $this->assertSame('900', $this->appConfig['local_timeout']);
        $this->assertSame('yes', $this->appConfig['local_vision']);
        $this->assertArrayNotHasKey('provider', $this->appConfig);
    }

    public function testCheckboxRejectsAnUnknownWord(): void {
        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-setting' => ['local_vision=enabled'],
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('takes on or off', $this->tester->getDisplay());
        $this->assertSame([], $this->appConfig);
    }

    public function testSettingAcceptsTheAppConfigKey(): void {
        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-setting' => ['local_timeout=900'],
        ]);

        $this->assertSame(0, $code, $this->tester->getDisplay());
        $this->assertSame('900', $this->appConfig['local_timeout']);
    }

    public function testInvalidSettingChangesNothing(): void {
        $code = $this->tester->execute([
            '--provider' => 'local',
            '--provider-model' => 'qwen3',
            '--provider-setting' => ['local_base_url=llama:8080'],
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('http://', $this->tester->getDisplay());
        // Validation runs before any write, and activation comes last.
        $this->assertSame([], $this->appConfig);
    }

    public function testUnknownSettingListsTheValidIds(): void {
        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-setting' => ['local_pull=1'],
        ]);

        $this->assertSame(1, $code);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Unknown setting "local_pull"', $display);
        $this->assertStringContainsString('local_base_url', $display);
        $this->assertStringContainsString('allowed_users', $display);
    }

    public function testMalformedSettingIsRejected(): void {
        $code = $this->tester->execute([
            '--target-provider' => 'local',
            '--provider-setting' => ['local_timeout'],
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('expects id=value', $this->tester->getDisplay());
    }

    public function testUnknownTargetProviderIsRejected(): void {
        $code = $this->tester->execute(['--target-provider' => 'nope', '--provider-model' => 'x']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown provider "nope"', $this->tester->getDisplay());
    }

    public function testAnthropicKeyFormatIsStillChecked(): void {
        $code = $this->tester->execute(['--provider-key' => 'not-a-claude-key']);

        $this->assertSame(1, $code);
        $this->assertSame([], $this->apiKeys);
    }

    public function testShowTargetProviderListsSettingIdsWithoutSecrets(): void {
        $this->apiKeys['local'] = 'secret-token-value';
        $this->appConfig['local_base_url'] = 'http://llama:8080';

        $code = $this->tester->execute(['--show' => true, '--target-provider' => 'local']);

        $this->assertSame(0, $code);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('local_base_url', $display);
        $this->assertStringContainsString('http://llama:8080', $display);
        $this->assertStringContainsString('(configured)', $display);
        $this->assertStringNotContainsString('secret-token-value', $display);
        $this->assertStringNotContainsString('local_pull', $display);
    }
}
