<?php

namespace OCA\AIquila\Tests\Unit\Cloud;

use Anthropic\Aws\Client as AwsClient;
use Anthropic\Bedrock\Client as BedrockClient;
use Anthropic\Bedrock\MantleClient;
use Anthropic\Core\BaseClient;
use Anthropic\Foundry\Client as FoundryClient;
use Anthropic\GoogleCloud\Client as GoogleCloudClient;
use Anthropic\Messages\Message;
use Anthropic\Messages\Usage;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\Cloud\AbstractCloudClaudeProvider;
use OCA\AIquila\Service\Provider\Cloud\BedrockInvokeProvider;
use OCA\AIquila\Service\Provider\Cloud\BedrockProvider;
use OCA\AIquila\Service\Provider\Cloud\ClaudePlatformAwsProvider;
use OCA\AIquila\Service\Provider\Cloud\ClaudePlatformGoogleCloudProvider;
use OCA\AIquila\Service\Provider\Cloud\CloudSdk;
use OCA\AIquila\Service\Provider\Cloud\FoundryProvider;
use OCA\AIquila\Service\Provider\Cloud\VertexProvider;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Bedrock with the transport stubbed out, so the request it would send can be inspected. */
class CapturingBedrockProvider extends BedrockProvider {
    public ?array $lastParams = null;

    protected function callCreate(BaseClient $client, array $params): Message {
        $this->lastParams = $params;
        return CloudProvidersTest::message();
    }
}

class CapturingBedrockInvokeProvider extends BedrockInvokeProvider {
    public ?array $lastParams = null;

    protected function callCreate(BaseClient $client, array $params): Message {
        $this->lastParams = $params;
        return CloudProvidersTest::message();
    }
}

class CloudProvidersTest extends TestCase {
    /** @var array<string, string> app config */
    private array $app = [];
    /** @var array<string, string> user config, keyed "uid/key" */
    private array $user = [];
    /** @var array<string, string> named secrets */
    private array $secrets = [];
    /** @var array<string, string> API key slots, keyed by provider id */
    private array $keys = [];
    private bool $sdkAvailable = true;

    private IConfig $config;
    private CredentialService $credentials;
    private CloudSdk $sdk;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->app[$key] ?? $default,
        );
        $this->config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, $default = '') => $this->user[$uid . '/' . $key] ?? $default,
        );

        $this->credentials = $this->createMock(CredentialService::class);
        $this->credentials->method('getApiKey')->willReturnCallback(
            fn (?string $uid, string $provider = 'anthropic') => $uid === null ? ($this->keys[$provider] ?? '') : 'sk-ant-personal',
        );
        $this->credentials->method('getSecret')->willReturnCallback(
            fn (string $name) => $this->secrets[$name] ?? '',
        );

        $this->sdk = $this->createMock(CloudSdk::class);
        $this->sdk->method('isAvailable')->willReturnCallback(fn () => $this->sdkAvailable);
        $this->sdk->method('require')->willReturnCallback(function (string $package, string $what): void {
            if (!$this->sdkAvailable) {
                throw new \RuntimeException($what . ' requires the ' . $package . ' package.');
            }
        });
    }

    /**
     * @template T of AbstractCloudClaudeProvider
     * @param class-string<T> $class
     * @return T
     */
    private function provider(string $class): AbstractCloudClaudeProvider {
        $cache = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        $metadata = $this->createMock(RequestMetadataService::class);
        $metadata->method('hashUserId')->willReturn('hashed');
        return new $class($this->config, $this->createMock(LoggerInterface::class), $this->credentials, $cacheFactory, $metadata, $this->sdk);
    }

    public static function message(): Message {
        $text = new \stdClass();
        $text->type = 'text';
        $text->text = 'ok';
        return Message::with('msg', null, [$text], null, 'm', null, 'end_turn', null, Usage::with(null, null, null, null, 1, 1, null, null, null, null));
    }

    private static function baseUrl(BaseClient $client): string {
        return (string)(new \ReflectionProperty(BaseClient::class, 'baseUrl'))->getValue($client);
    }

    private function client(AbstractCloudClaudeProvider $provider): BaseClient {
        $m = new \ReflectionMethod($provider, 'getClient');
        return $m->invoke($provider, null);
    }

    // ── Configuration ───────────────────────────────────────────────────────

    public function testSettingsAreNamespacedPerProvider(): void {
        $this->app['model'] = 'claude-opus-4-6';
        $this->app['model_bedrock'] = 'eu.anthropic.claude-sonnet-5';
        $this->user['alice/user_model_bedrock'] = 'anthropic.claude-opus-5-5';

        $bedrock = $this->provider(BedrockProvider::class);
        $this->assertSame('eu.anthropic.claude-sonnet-5', $bedrock->getModel());
        $this->assertSame('anthropic.claude-opus-5-5', $bedrock->getModel('alice'));
        $this->assertSame('claude-sonnet-5', $this->provider(VertexProvider::class)->getModel(), 'default, not the Anthropic card\'s model');
    }

    public function testAnInvalidStoredModelFallsBackToTheDefault(): void {
        $this->app['model_vertex'] = '../../projects/other';
        $this->assertSame('claude-sonnet-5', $this->provider(VertexProvider::class)->getModel());
    }

    public function testAPersonalAnthropicKeyNeverReachesACloudProvider(): void {
        $this->app['region_bedrock'] = 'us-east-1';
        $bedrock = $this->provider(BedrockProvider::class);
        $this->assertSame('', $bedrock->getApiKey('alice'));
        $this->assertFalse($bedrock->isConfigured('alice'));

        $this->keys['bedrock'] = 'bedrock-key';
        $this->assertSame('bedrock-key', $bedrock->getApiKey('alice'));
        $this->assertTrue($bedrock->isConfigured('alice'));
    }

    public function testAnEndpointValueWrittenPastTheSettingsApiIsRefused(): void {
        $this->app['region_bedrock'] = 'us-east-1.evil.example/';
        $this->keys['bedrock'] = 'bedrock-key';
        $bedrock = $this->provider(BedrockProvider::class);

        $this->assertFalse($bedrock->isConfigured());
        $this->expectException(\RuntimeException::class);
        $this->client($bedrock);
    }

    public static function schemaProviders(): array {
        return [
            'bedrock' => [BedrockProvider::class],
            'bedrock_invoke' => [BedrockInvokeProvider::class],
            'claude_aws' => [ClaudePlatformAwsProvider::class],
            'claude_gcp' => [ClaudePlatformGoogleCloudProvider::class],
            'vertex' => [VertexProvider::class],
            'foundry' => [FoundryProvider::class],
        ];
    }

    /** @param class-string<AbstractCloudClaudeProvider> $class */
    #[DataProvider('schemaProviders')]
    public function testSchemaIsAdminOnlyAndNamespaced(string $class): void {
        $provider = $this->provider($class);
        $id = $provider->getId();
        $ids = [];
        foreach ($provider->getSettingsSchema() as $field) {
            $ids[] = $field['id'];
            if (in_array($field['id'], ['effort', 'thinking'], true)) {
                // Inherited from the Claude API card; users may still pick their own.
                $this->assertSame(ProviderSettingsSchema::SCOPE_BOTH, $field['scope']);
                $this->assertStringEndsWith('_' . $id, $field['user_key']);
            } else {
                $this->assertSame(ProviderSettingsSchema::SCOPE_ADMIN, $field['scope'] ?? ProviderSettingsSchema::SCOPE_ADMIN, $field['id']);
            }
            if (isset($field['key'])) {
                $this->assertStringEndsWith('_' . $id, $field['key'], $field['id']);
            }
            if (isset($field['secret'])) {
                $this->assertStringStartsWith($id . '_', $field['secret'], $field['id']);
            }
        }
        foreach (['auth_mode', 'model', 'effort', 'thinking', 'max_tokens'] as $expected) {
            $this->assertContains($expected, $ids);
        }
        foreach (['service_tier', 'speed_fast', 'batch_output_300k', RequestMetadataService::ENABLED_KEY] as $absent) {
            $this->assertNotContains($absent, $ids);
        }
    }

    // ── Clients ─────────────────────────────────────────────────────────────

    public function testBedrockBuildsAMantleClientForTheRegion(): void {
        $this->app['region_bedrock'] = 'eu-central-1';
        $this->keys['bedrock'] = 'bedrock-key';
        $client = $this->client($this->provider(BedrockProvider::class));
        $this->assertInstanceOf(MantleClient::class, $client);
        $this->assertSame('https://bedrock-mantle.eu-central-1.api.aws/anthropic', self::baseUrl($client));
    }

    public function testBedrockInvokeUsesBedrockRuntime(): void {
        $this->app['region_bedrock_invoke'] = 'us-west-2';
        $this->keys['bedrock_invoke'] = 'bedrock-key';
        $client = $this->client($this->provider(BedrockInvokeProvider::class));
        $this->assertInstanceOf(BedrockClient::class, $client);
        $this->assertSame('https://bedrock-runtime.us-west-2.amazonaws.com', self::baseUrl($client));
    }

    /**
     * Bedrock serves the SDK's 2026-10-21 only for Claude 4.7 and later, so the
     * InvokeModel route for older models has to send the Bedrock version.
     */
    public function testBedrockInvokeSendsTheBedrockApiVersion(): void {
        $this->app['region_bedrock_invoke'] = 'us-west-2';
        $this->keys['bedrock_invoke'] = 'bedrock-key';
        $client = $this->client($this->provider(BedrockInvokeProvider::class));

        $transport = new class implements \Psr\Http\Client\ClientInterface {
            public ?\Psr\Http\Message\RequestInterface $request = null;
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $this->request = $request;
                return new \Nyholm\Psr7\Response(200, ['Content-Type' => 'application/json'], (string)json_encode([
                    'id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'stop_reason' => 'end_turn', 'stop_sequence' => null,
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]));
            }
        };

        $client->messages->create(
            maxTokens: 16,
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'global.anthropic.claude-opus-4-6-v1',
            tools: [['name' => 't', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]]],
            requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
        );

        $this->assertNotNull($transport->request);
        $this->assertSame('/model/global.anthropic.claude-opus-4-6-v1/invoke', $transport->request->getUri()->getPath());
        $body = (string)$transport->request->getBody();
        $this->assertSame(BedrockInvokeProvider::ANTHROPIC_VERSION, json_decode($body, true)['anthropic_version'] ?? null);
        $this->assertStringContainsString('"properties":{}', $body);
    }

    public function testClaudePlatformAwsNeedsTheWorkspace(): void {
        $this->app['region_claude_aws'] = 'us-west-2';
        $this->keys['claude_aws'] = 'aws-key';
        $provider = $this->provider(ClaudePlatformAwsProvider::class);
        $this->assertFalse($provider->isConfigured());

        $this->app['workspace_id_claude_aws'] = 'wrkspc_01Ab';
        $client = $this->client($this->provider(ClaudePlatformAwsProvider::class));
        $this->assertInstanceOf(AwsClient::class, $client);
        $this->assertSame('https://aws-external-anthropic.us-west-2.api.aws', self::baseUrl($client));
    }

    public function testClaudePlatformGoogleCloudTakesAPastedTokenWithoutGoogleAuth(): void {
        $this->sdkAvailable = false;
        $this->app += [
            'project_claude_gcp' => 'my-project-1',
            'workspace_id_claude_gcp' => 'wrkspc_01Ab',
            'auth_mode_claude_gcp' => AbstractCloudClaudeProvider::AUTH_ACCESS_TOKEN,
        ];
        $this->secrets['claude_gcp_access_token'] = 'ya29.token';
        $client = $this->client($this->provider(ClaudePlatformGoogleCloudProvider::class));
        $this->assertInstanceOf(GoogleCloudClient::class, $client);
        $this->assertSame(
            'https://claude.googleapis.com/v1/projects/my-project-1/locations/global/workspaces/wrkspc_01Ab/invoke',
            self::baseUrl($client),
        );
    }

    public function testFoundryBuildsTheResourceUrl(): void {
        $this->app['resource_foundry'] = 'my-res';
        $this->keys['foundry'] = 'azure-key';
        $client = $this->client($this->provider(FoundryProvider::class));
        $this->assertInstanceOf(FoundryClient::class, $client);
        $this->assertSame('https://my-res.services.ai.azure.com/anthropic', self::baseUrl($client));
    }

    public function testSigV4WithoutTheAwsSdkSaysWhatToInstall(): void {
        $this->sdkAvailable = false;
        $this->app += [
            'region_bedrock' => 'us-east-1',
            'auth_mode_bedrock' => AbstractCloudClaudeProvider::AUTH_DEFAULT_CHAIN,
        ];
        $this->expectExceptionMessage(CloudSdk::AWS);
        $this->client($this->provider(BedrockProvider::class));
    }

    public function testVertexNeedsGoogleAuthEvenForAPastedToken(): void {
        $this->sdkAvailable = false;
        $this->app += [
            'project_vertex' => 'my-project-1',
            'auth_mode_vertex' => AbstractCloudClaudeProvider::AUTH_ACCESS_TOKEN,
        ];
        $this->secrets['vertex_access_token'] = 'ya29.token';
        $this->expectExceptionMessage(CloudSdk::GOOGLE);
        $this->client($this->provider(VertexProvider::class));
    }

    public function testAMissingSdkShowsAsUnconfiguredOnTheStatusLight(): void {
        $this->sdkAvailable = false;
        $this->app += [
            'project_vertex' => 'my-project-1',
            'auth_mode_vertex' => AbstractCloudClaudeProvider::AUTH_ACCESS_TOKEN,
        ];
        $this->secrets['vertex_access_token'] = 'ya29.token';

        $probe = $this->provider(VertexProvider::class)->probe();

        $this->assertSame(\OCA\AIquila\Service\Provider\ProviderProbe::REASON_NOT_CONFIGURED, $probe['reason']);
        $this->assertStringContainsString(CloudSdk::GOOGLE, $probe['message']);
    }

    // ── Features ────────────────────────────────────────────────────────────

    public function testMessagesOnlyPlatformsSwitchOffTheExtras(): void {
        foreach ([BedrockProvider::class, BedrockInvokeProvider::class, VertexProvider::class, FoundryProvider::class] as $class) {
            $provider = $this->provider($class);
            $this->assertFalse($provider->supportsBatch(), $class);
            $this->assertFalse($provider->supportsFilesApi(), $class);
            $this->assertFalse($provider->supportsNativeMcp(), $class);
            $this->assertFalse($provider->getCapabilities()['native_mcp'], $class);
            $this->assertFalse($provider->getCapabilities()['fast_mode'], $class);
            $this->assertNull($provider->listModels(), $class);
            $this->assertTrue($provider->getCapabilities()['thinking'], $class);
        }
    }

    public function testAnthropicOperatedPlatformsKeepBatchesAndModels(): void {
        foreach ([ClaudePlatformAwsProvider::class, ClaudePlatformGoogleCloudProvider::class] as $class) {
            $provider = $this->provider($class);
            $this->assertTrue($provider->supportsBatch(), $class);
            $this->assertTrue($provider->supportsNativeMcp(), $class);
            $this->assertTrue($provider->hasFeature(ClaudeSDKService::FEATURE_MODELS_API), $class);
            // Uploads go through the Claude API provider's key, which these workspaces cannot read.
            $this->assertFalse($provider->supportsFilesApi(), $class);
        }
        $this->assertFalse($this->provider(ClaudePlatformAwsProvider::class)->getCapabilities()['fast_mode']);
        $this->assertTrue($this->provider(ClaudePlatformGoogleCloudProvider::class)->getCapabilities()['fast_mode']);
    }

    public function testUploadFileIsRefusedWithoutAFilesApi(): void {
        $this->expectException(\RuntimeException::class);
        $this->provider(BedrockProvider::class)->uploadFile('bytes', 'a.pdf', 'application/pdf', 'alice');
    }

    public function testBedrockRequestCarriesNoClaudeApiOnlyFields(): void {
        $this->app += [
            'region_bedrock' => 'us-east-1',
            'service_tier_bedrock' => 'auto',
            'speed_fast_bedrock' => 'true',
        ];
        $this->keys['bedrock'] = 'bedrock-key';
        $bedrock = $this->provider(CapturingBedrockProvider::class);

        // Multi-turn, which is what makes chat() ask for the automatic breakpoint.
        $result = $bedrock->chat([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello'], ['role' => 'user', 'content' => 'again']], 'system', 'alice');

        $this->assertSame('ok', $result['response'] ?? null, json_encode($result));
        $params = $bedrock->lastParams;
        $this->assertSame('anthropic.claude-sonnet-5', $params['model']);
        $this->assertArrayNotHasKey('metadata', $params);
        $this->assertArrayNotHasKey('service_tier', $params);
        $this->assertArrayNotHasKey('speed', $params);
        $this->assertSame(['type' => 'ephemeral'], $params['cache_control'] ?? null);
    }

    public function testInvokeModelDropsAutomaticCaching(): void {
        $this->app['region_bedrock_invoke'] = 'us-east-1';
        $this->keys['bedrock_invoke'] = 'bedrock-key';
        $provider = $this->provider(CapturingBedrockInvokeProvider::class);

        $provider->chat([['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello'], ['role' => 'user', 'content' => 'again']], 'system');

        $this->assertArrayNotHasKey('cache_control', $provider->lastParams);
        // Explicit breakpoints still go out.
        $this->assertSame(['type' => 'ephemeral'], $provider->lastParams['system'][0]['cache_control']);
    }

    public function testFileIdsAreIgnoredWithoutAFilesApi(): void {
        $this->app['region_bedrock'] = 'us-east-1';
        $this->keys['bedrock'] = 'bedrock-key';
        $bedrock = $this->provider(CapturingBedrockProvider::class);

        $bedrock->askWithImage('what is this', 'aW1n', 'image/png', null, 'file_abc');

        $source = $bedrock->lastParams['messages'][0]['content'][0]['source'];
        $this->assertSame('base64', $source['type']);
    }

    public function testAPinnedModelIsValidated(): void {
        $this->app['region_bedrock'] = 'us-east-1';
        $this->keys['bedrock'] = 'bedrock-key';
        $result = $this->provider(CapturingBedrockProvider::class)
            ->chat([['role' => 'user', 'content' => 'hi']], null, null, ['model' => 'x?y=1']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCapabilitiesComeFromTheStaticTablesUnderPlatformIds(): void {
        $invoke = $this->provider(BedrockInvokeProvider::class);
        $this->assertSame(
            ClaudeModels::getAllowedEfforts(ClaudeModels::OPUS_4_6),
            $invoke->getThinkingProfile('global.anthropic.claude-opus-4-6-v1')['efforts'],
        );
        $profile = $this->provider(BedrockProvider::class)->getThinkingProfile('anthropic.claude-opus-5-5');
        $this->assertSame(ClaudeModels::getAllowedEfforts(ClaudeModels::OPUS_5_5), $profile['efforts']);
        $this->assertSame(ClaudeSDKService::THINKING_ALWAYS_ON, $profile['thinking']);

        // An admin's max_tokens above the model ceiling is clamped to it.
        $this->app['model_bedrock'] = 'eu.anthropic.claude-sonnet-4-6';
        $this->app['max_tokens_bedrock'] = '999999';
        $this->assertSame(ClaudeModels::getMaxTokenCeiling(ClaudeModels::SONNET_4_6), $this->provider(BedrockProvider::class)->getMaxTokens());
    }

    public function testFoundryBaseModelDrivesCapabilitiesForACustomDeploymentName(): void {
        $foundry = $this->provider(FoundryProvider::class);
        $this->assertSame([], $foundry->getAllowedEfforts('my-claude'));

        $this->app['base_model_foundry'] = ClaudeModels::OPUS_5_5;
        $foundry = $this->provider(FoundryProvider::class);
        $this->assertSame(ClaudeModels::getAllowedEfforts(ClaudeModels::OPUS_5_5), $foundry->getAllowedEfforts('my-claude'));
        $this->assertSame('my-claude', $foundry->getThinkingProfile('my-claude')['model']);
    }
}
