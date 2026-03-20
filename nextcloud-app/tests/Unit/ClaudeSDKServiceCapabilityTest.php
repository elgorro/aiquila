<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
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
        $usage = \Anthropic\Messages\Usage::with(null, null, null, null, 10, 20, null, null);
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

        $this->assertArrayHasKey('thinking', $params);
        $this->assertEquals(['type' => 'adaptive'], $params['thinking']);
        $this->assertArrayHasKey('outputConfig', $params);
        $this->assertEquals('high', $params['outputConfig']['effort']);
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

        // Static fallback: Opus 4.6 supports thinking and effort
        $this->assertArrayHasKey('thinking', $params);
        $this->assertArrayHasKey('outputConfig', $params);
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
                $usage = \Anthropic\Messages\Usage::with(null, 50, 200, 'us', 100, 30, null, 'standard');
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
