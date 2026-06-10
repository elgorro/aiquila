<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\ErrorType;
use Anthropic\Messages\RefusalStopDetails;
use Anthropic\Models\ModelInfo;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests dynamic model capability resolution in ClaudeSDKService.
 */
class CapabilityTestableService extends ClaudeSDKService {
    private ?ModelInfo $retrieveResult = null;
    private ?\Exception $retrieveException = null;
    public ?array $lastCreateParams = null;

    public function setRetrieveModelInfo(ModelInfo $info): void {
        $this->retrieveResult = $info;
    }

    public function throwOnRetrieveModel(\Exception $e): void {
        $this->retrieveException = $e;
    }

    protected function callRetrieveModel(Client $client, string $modelId): ModelInfo {
        if ($this->retrieveException !== null) throw $this->retrieveException;
        return $this->retrieveResult ?? ModelInfo::with($modelId, null, new \DateTime(), 'Test', null, null);
    }

    protected function callCreate(Client $client, array $params): \Anthropic\Messages\Message {
        $this->lastCreateParams = $params;
        $stub = (new \ReflectionClass(\Anthropic\Messages\Message::class))->newInstanceWithoutConstructor();
        $ref  = new \ReflectionClass($stub);

        $textObj = new \stdClass();
        $textObj->type = 'text';
        $textObj->text = 'ok';

        foreach (['content' => [$textObj], 'stopReason' => 'end_turn'] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($stub, $val);
        }
        $usage = \Anthropic\Messages\Usage::with(null, null, null, null, 10, 20, null, null, null);
        $p = $ref->getProperty('usage');
        $p->setValue($stub, $usage);
        return $stub;
    }

    protected function getClient(?string $userId = null): Client {
        $apiKey = $this->getApiKey($userId);
        if (!$apiKey) throw new \RuntimeException('No API key configured');
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }
}

class ClaudeSDKServiceCapabilityTest extends TestCase {
    private IConfig $config;
    private LoggerInterface $logger;
    private CredentialService $credentials;
    private ICache $cache;
    private ICacheFactory $cacheFactory;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->credentials->method('getApiKey')->willReturn('test-key');

        $this->cache = $this->createMock(ICache::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::OPUS_4_6,
                'max_tokens' => '4096',
                default => $default,
            });
    }

    public function testDynamicCapabilitiesFromModelInfo(): void {
        // Cache miss → dynamic resolution
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->atLeastOnce())->method('set');

        $info = ModelInfo::with(
            ClaudeModels::OPUS_4_6,
            [
                'batch' => ['supported' => true],
                'citations' => ['supported' => false],
                'code_execution' => ['supported' => false],
                'context_management' => ['supported' => false, 'strategies' => []],
                'effort' => [
                    'supported' => true,
                    'high' => ['supported' => true],
                    'low' => ['supported' => true],
                    'max' => ['supported' => true],
                    'medium' => ['supported' => true],
                ],
                'image_input' => ['supported' => true],
                'pdf_input' => ['supported' => true],
                'structured_outputs' => ['supported' => false],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ],
            new \DateTime(),
            'Claude Opus 4.6',
            200000,
            128000
        );

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->setRetrieveModelInfo($info);

        // Trigger buildRequestParams via ask()
        $result = $service->ask('test', '', 'testuser');
        $params = $service->lastCreateParams;

        // Thinking is opt-in (default off) — must be omitted without an override
        $this->assertArrayNotHasKey('thinking', $params);
        $this->assertArrayHasKey('outputConfig', $params);
        $this->assertEquals('high', $params['outputConfig']['effort']);
    }

    public function testContextWindowResolvedFromModelInfo(): void {
        $this->cache->method('get')->willReturn(null);
        $capturedCaps = null;
        $this->cache->method('set')->willReturnCallback(function ($key, $value) use (&$capturedCaps) {
            $capturedCaps = $value;
            return true;
        });

        $info = ModelInfo::with(
            ClaudeModels::OPUS_4_6,
            [
                'batch' => ['supported' => true],
                'citations' => ['supported' => false],
                'code_execution' => ['supported' => false],
                'context_management' => ['supported' => false, 'strategies' => []],
                'effort' => [
                    'supported' => true,
                    'high' => ['supported' => true],
                    'low' => ['supported' => true],
                    'max' => ['supported' => true],
                    'medium' => ['supported' => true],
                ],
                'image_input' => ['supported' => true],
                'pdf_input' => ['supported' => true],
                'structured_outputs' => ['supported' => false],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ],
            new \DateTime(),
            'Claude Opus 4.6',
            1000000,
            128000
        );

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->setRetrieveModelInfo($info);
        $service->ask('test', '', 'testuser');

        $this->assertIsArray($capturedCaps);
        $this->assertEquals(1000000, $capturedCaps['context_window']);
    }

    public function testCacheHitSkipsApiCall(): void {
        $cached = [
            'max_tokens' => 64000,
            'supports_thinking' => true,
            'supports_effort' => true,
        ];
        $this->cache->method('get')->willReturn($cached);
        // set() should not be called since we have a cache hit
        $this->cache->expects($this->never())->method('set');

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->throwOnRetrieveModel(new \RuntimeException('should not be called'));

        $result = $service->ask('test', '', 'testuser');
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testFallbackOnApiFailure(): void {
        $this->cache->method('get')->willReturn(null);

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->throwOnRetrieveModel(new \RuntimeException('API unreachable'));

        // Should fall back to static values without error
        $result = $service->ask('test', '', 'testuser');
        $params = $service->lastCreateParams;

        // Static fallback: Opus 4.6 supports effort; thinking stays opt-in
        $this->assertArrayNotHasKey('thinking', $params);
        $this->assertArrayHasKey('outputConfig', $params);
    }

    public function testOpus47UsesXhighEffort(): void {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->atLeastOnce())->method('set');

        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::OPUS_4_7,
                'max_tokens' => '4096',
                default => $default,
            });

        $info = ModelInfo::with(
            ClaudeModels::OPUS_4_7,
            [
                'batch' => ['supported' => true],
                'citations' => ['supported' => false],
                'code_execution' => ['supported' => false],
                'context_management' => ['supported' => false, 'strategies' => []],
                'effort' => [
                    'supported' => true,
                    'high' => ['supported' => true],
                    'low' => ['supported' => true],
                    'max' => ['supported' => true],
                    'medium' => ['supported' => true],
                ],
                'image_input' => ['supported' => true],
                'pdf_input' => ['supported' => true],
                'structured_outputs' => ['supported' => false],
                'thinking' => ['supported' => true, 'types' => ['adaptive' => ['supported' => true]]],
            ],
            new \DateTime(),
            'Claude Opus 4.7',
            1000000,
            128000
        );

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->setRetrieveModelInfo($info);

        $service->ask('test', '', 'testuser');
        $params = $service->lastCreateParams;

        $this->assertArrayNotHasKey('thinking', $params);
        $this->assertEquals('xhigh', $params['outputConfig']['effort']);
    }

    public function testNonThinkingModelOmitsThinkingParams(): void {
        $this->cache->method('get')->willReturn(null);

        // Use Haiku which doesn't support thinking/effort
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::HAIKU_4_5,
                'max_tokens' => '4096',
                default => $default,
            });

        $info = ModelInfo::with(
            ClaudeModels::HAIKU_4_5,
            [
                'batch' => ['supported' => true],
                'citations' => ['supported' => false],
                'code_execution' => ['supported' => false],
                'context_management' => ['supported' => false, 'strategies' => []],
                'effort' => [
                    'supported' => false,
                    'high' => ['supported' => false],
                    'low' => ['supported' => false],
                    'max' => ['supported' => false],
                    'medium' => ['supported' => false],
                ],
                'image_input' => ['supported' => true],
                'pdf_input' => ['supported' => true],
                'structured_outputs' => ['supported' => false],
                'thinking' => ['supported' => false, 'types' => ['adaptive' => ['supported' => false]]],
            ],
            new \DateTime(),
            'Claude Haiku 4.5',
            200000,
            8192
        );

        $service = new CapabilityTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->setRetrieveModelInfo($info);

        $result = $service->ask('test', '', 'testuser');
        $params = $service->lastCreateParams;

        $this->assertArrayNotHasKey('thinking', $params);
        $this->assertArrayNotHasKey('outputConfig', $params);
    }

    /** Build a service for a given model with cached caps and optional admin config. */
    private function makeServiceForModel(string $model, array $appConfig = []): CapabilityTestableService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match (true) {
                $key === 'model' => $model,
                $key === 'max_tokens' => '4096',
                array_key_exists($key, $appConfig) => $appConfig[$key],
                default => $default,
            });

        $this->cache->method('get')->willReturn([
            'max_tokens' => 64000,
            'context_window' => 200000,
            'supports_thinking' => true,
            'supports_effort' => true,
        ]);

        return new CapabilityTestableService($config, $this->logger, $this->credentials, $this->cacheFactory);
    }

    public function testEffortConversationOverrideWins(): void {
        $service = $this->makeServiceForModel(ClaudeModels::OPUS_4_6, ['effort' => 'max']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser', ['effort' => 'low']);
        $this->assertEquals('low', $service->lastCreateParams['outputConfig']['effort']);
    }

    public function testAdminEffortDefaultUsedWithoutOverride(): void {
        $service = $this->makeServiceForModel(ClaudeModels::OPUS_4_6, ['effort' => 'max']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertEquals('max', $service->lastCreateParams['outputConfig']['effort']);
    }

    public function testInvalidAdminEffortFallsBackToModelDefault(): void {
        // xhigh is not allowed on Sonnet 4.6 → falls back to model default 'medium'
        $service = $this->makeServiceForModel(ClaudeModels::SONNET_4_6, ['effort' => 'xhigh']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertEquals('medium', $service->lastCreateParams['outputConfig']['effort']);
    }

    public function testInvalidEffortOverrideFallsThroughToAdminDefault(): void {
        $service = $this->makeServiceForModel(ClaudeModels::SONNET_4_6, ['effort' => 'high']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser', ['effort' => 'xhigh']);
        $this->assertEquals('high', $service->lastCreateParams['outputConfig']['effort']);
    }

    public function testThinkingEnabledByAdminDefault(): void {
        $service = $this->makeServiceForModel(ClaudeModels::FABLE_5, ['thinking' => 'true']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertEquals(['type' => 'adaptive'], $service->lastCreateParams['thinking']);
    }

    public function testThinkingConversationOverrideBeatsAdminDefault(): void {
        $service = $this->makeServiceForModel(ClaudeModels::FABLE_5, ['thinking' => 'true']);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser', ['thinking' => false]);
        $this->assertArrayNotHasKey('thinking', $service->lastCreateParams);
    }

    public function testThinkingEnabledViaConversationOverride(): void {
        $service = $this->makeServiceForModel(ClaudeModels::FABLE_5);
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser', ['thinking' => true]);
        $this->assertEquals(['type' => 'adaptive'], $service->lastCreateParams['thinking']);
    }

    public function testCallCreateStreamForwardsTools(): void {
        // Guard: callCreateStream() must forward the tools parameter to the SDK,
        // otherwise streaming tool-calling silently degrades to tool-less behavior.
        $ref = new \ReflectionMethod(ClaudeSDKService::class, 'callCreateStream');
        $file = $ref->getFileName();
        $src = file_get_contents($file);
        $start = $ref->getStartLine() - 1;
        $end = $ref->getEndLine();
        $body = implode("\n", array_slice(explode("\n", $src), $start, $end - $start));

        $this->assertStringContainsString(
            "tools: \$params['tools'] ?? null",
            $body,
            'callCreateStream must forward tools to the SDK; see plan file stay-on-this-branch-imperative-fiddle.md change #1.'
        );
    }

    public function testErrorTypeIncludedInExceptionLogContext(): void {
        $this->cache->method('get')->willReturn([
            'max_tokens' => 64000,
            'supports_thinking' => false,
            'supports_effort' => false,
        ]);

        // Build a RateLimitException (subclass of APIStatusException) and
        // force the typed ErrorType via reflection — the SDK constructor
        // needs full HTTP Request/Response objects which are awkward to mock.
        $e = (new \ReflectionClass(RateLimitException::class))->newInstanceWithoutConstructor();
        $typeProp = new \ReflectionProperty(\Anthropic\Core\Exceptions\APIStatusException::class, 'type');
        $typeProp->setAccessible(true);
        $typeProp->setValue($e, ErrorType::RATE_LIMIT_ERROR);
        $msgProp = new \ReflectionProperty(\Exception::class, 'message');
        $msgProp->setAccessible(true);
        $msgProp->setValue($e, 'rate limited');

        $capturedCtx = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->willReturnCallback(function ($msg, $ctx) use (&$capturedCtx) {
                if (str_contains($msg, 'Rate limit')) {
                    $capturedCtx = $ctx;
                }
            });

        $service = new class($this->config, $logger, $this->credentials, $this->cacheFactory, $e) extends ClaudeSDKService {
            public function __construct($cfg, $log, $cred, $cf, private \Throwable $toThrow) {
                parent::__construct($cfg, $log, $cred, $cf);
            }
            protected function callCreate(Client $client, array $params): \Anthropic\Messages\Message {
                throw $this->toThrow;
            }
            protected function getClient(?string $userId = null): Client {
                return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
            }
        };

        $result = $service->ask('test', '', 'testuser');

        $this->assertArrayHasKey('error', $result);
        $this->assertNotNull($capturedCtx, 'Rate limit error should have been logged');
        $this->assertArrayHasKey('error_type', $capturedCtx);
        $this->assertEquals('rate_limit_error', $capturedCtx['error_type']);
    }

    public function testRefusalStopDetailsLoggedInResponseMetadata(): void {
        $this->cache->method('get')->willReturn([
            'max_tokens' => 64000,
            'supports_thinking' => false,
            'supports_effort' => false,
        ]);

        $capturedDebug = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(function ($msg, $ctx = []) use (&$capturedDebug) {
            if (str_contains($msg, 'Response metadata')) {
                $capturedDebug[] = $ctx;
            }
        });

        $refusal = RefusalStopDetails::with('cyber', 'I cannot help with that.');

        $service = new class($this->config, $logger, $this->credentials, $this->cacheFactory, $refusal) extends ClaudeSDKService {
            public function __construct($cfg, $log, $cred, $cf, private RefusalStopDetails $refusal) {
                parent::__construct($cfg, $log, $cred, $cf);
            }
            protected function callCreate(Client $client, array $params): \Anthropic\Messages\Message {
                $stub = (new \ReflectionClass(\Anthropic\Messages\Message::class))->newInstanceWithoutConstructor();
                $ref = new \ReflectionClass($stub);
                $textObj = new \stdClass();
                $textObj->type = 'text';
                $textObj->text = '';
                foreach (['content' => [$textObj], 'stopReason' => 'refusal', 'stopDetails' => $this->refusal] as $prop => $val) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($stub, $val);
                }
                $usage = \Anthropic\Messages\Usage::with(null, null, null, null, 10, 5, null, null, null);
                $p = $ref->getProperty('usage');
                $p->setValue($stub, $usage);
                return $stub;
            }
            protected function getClient(?string $userId = null): Client {
                return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
            }
        };

        $service->ask('test', '', 'testuser');

        $found = null;
        foreach ($capturedDebug as $ctx) {
            if (isset($ctx['stop_details'])) {
                $found = $ctx['stop_details'];
                break;
            }
        }
        $this->assertNotNull($found, 'Response metadata log should include stop_details for refusals');
        $this->assertEquals('refusal', $found['type']);
        $this->assertEquals('cyber', $found['category']);
        $this->assertEquals('I cannot help with that.', $found['explanation']);
    }

    public function testUsageIncludesCacheTokens(): void {
        $this->cache->method('get')->willReturn([
            'max_tokens' => 128000,
            'supports_thinking' => false,
            'supports_effort' => false,
        ]);

        $service = new class($this->config, $this->logger, $this->credentials, $this->cacheFactory) extends ClaudeSDKService {
            public ?array $lastCreateParams = null;
            protected function callCreate(Client $client, array $params): \Anthropic\Messages\Message {
                $this->lastCreateParams = $params;
                $stub = (new \ReflectionClass(\Anthropic\Messages\Message::class))->newInstanceWithoutConstructor();
                $ref  = new \ReflectionClass($stub);
                $textObj = new \stdClass();
                $textObj->type = 'text';
                $textObj->text = 'response';
                foreach (['content' => [$textObj], 'stopReason' => 'end_turn'] as $prop => $val) {
                    $p = $ref->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($stub, $val);
                }
                $usage = \Anthropic\Messages\Usage::with(null, 50, 200, 'us', 100, 30, null, null, 'standard');
                $p = $ref->getProperty('usage');
                $p->setValue($stub, $usage);
                return $stub;
            }
            protected function getClient(?string $userId = null): Client {
                $apiKey = $this->getApiKey($userId);
                if (!$apiKey) throw new \RuntimeException('No API key configured');
                return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
            }
        };

        $result = $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');

        $this->assertArrayHasKey('usage', $result);
        $this->assertEquals(50, $result['usage']['cache_creation_tokens']);
        $this->assertEquals(200, $result['usage']['cache_read_tokens']);
        $this->assertEquals(100, $result['usage']['input_tokens']);
        $this->assertEquals(30, $result['usage']['output_tokens']);
    }
}
