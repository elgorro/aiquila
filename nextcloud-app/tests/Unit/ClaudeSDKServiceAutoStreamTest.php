<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Messages\Message;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal in-memory BaseStream double yielding pre-baked SSE events.
 */
class FakeEventStream implements BaseStream {
    private array $events = [];

    public function __construct(mixed ...$args) {
    }

    public static function withEvents(array $events): self {
        $stream = new self();
        $stream->events = $events;
        return $stream;
    }

    public function getIterator(): \Iterator {
        return new \ArrayIterator($this->events);
    }

    public function close(): void {
    }
}

class AutoStreamTestableService extends ClaudeSDKService {
    public bool $createCalled = false;
    public bool $streamCalled = false;
    public ?array $lastStreamParams = null;
    private array $streamEvents = [];

    public function setStreamEvents(array $events): void {
        $this->streamEvents = $events;
    }

    protected function callCreate(Client $client, array $params): Message {
        $this->createCalled = true;
        throw new \LogicException('callCreate must not be used above the streaming threshold');
    }

    protected function callCreateStream(Client $client, array $params): BaseStream {
        $this->streamCalled = true;
        $this->lastStreamParams = $params;
        return FakeEventStream::withEvents($this->streamEvents);
    }

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }
}

class ClaudeSDKServiceAutoStreamTest extends TestCase {
    private IConfig $config;
    private LoggerInterface $logger;
    private CredentialService $credentials;
    private ICacheFactory $cacheFactory;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->credentials = $this->createMock(CredentialService::class);
        $this->credentials->method('getApiKey')->willReturn('test-key');

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn([
            'max_tokens' => 128000,
            'context_window' => 1000000,
            'supports_thinking' => false,
            'supports_effort' => false,
        ]);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::OPUS_4_8,
                'max_tokens' => '64000',
                default => $default,
            });
    }

    private function makeEvents(): array {
        $usage = new \stdClass();
        $usage->inputTokens = 42;
        $usage->cacheCreationInputTokens = 5;
        $usage->cacheReadInputTokens = 7;
        $usage->inferenceGeo = null;
        $usage->serviceTier = null;

        $message = new \stdClass();
        $message->id = 'msg_test';
        $message->usage = $usage;

        $start = new \stdClass();
        $start->type = 'message_start';
        $start->message = $message;

        $blockStart = new \stdClass();
        $blockStart->type = 'content_block_start';
        $blockStart->index = 0;
        $blockStart->contentBlock = (object)['type' => 'text'];

        $delta1 = new \stdClass();
        $delta1->type = 'content_block_delta';
        $delta1->index = 0;
        $delta1->delta = (object)['type' => 'text_delta', 'text' => 'Hello '];

        $delta2 = new \stdClass();
        $delta2->type = 'content_block_delta';
        $delta2->index = 0;
        $delta2->delta = (object)['type' => 'text_delta', 'text' => 'world'];

        $blockStop = new \stdClass();
        $blockStop->type = 'content_block_stop';
        $blockStop->index = 0;

        $messageDelta = new \stdClass();
        $messageDelta->type = 'message_delta';
        $messageDelta->delta = (object)['stopReason' => 'end_turn', 'stopSequence' => null, 'stopDetails' => null];
        $messageDelta->usage = (object)['outputTokens' => 12];

        $stop = new \stdClass();
        $stop->type = 'message_stop';

        return [$start, $blockStart, $delta1, $delta2, $blockStop, $messageDelta, $stop];
    }

    public function testLargeMaxTokensUsesStreamingTransport(): void {
        $service = new AutoStreamTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->setStreamEvents($this->makeEvents());

        $result = $service->ask('test prompt', '', 'testuser');

        $this->assertTrue($service->streamCalled, 'max_tokens 64000 must route through the streaming transport');
        $this->assertFalse($service->createCalled);
        $this->assertEquals(64000, $service->lastStreamParams['max_tokens']);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertEquals('Hello world', $result['response']);
        $this->assertEquals(42, $result['usage']['input_tokens']);
        $this->assertEquals(12, $result['usage']['output_tokens']);
        $this->assertEquals(5, $result['usage']['cache_creation_tokens']);
        $this->assertEquals(7, $result['usage']['cache_read_tokens']);
    }

    public function testSmallMaxTokensKeepsNonStreamingTransport(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::OPUS_4_8,
                'max_tokens' => '4096',
                default => $default,
            });

        $service = new AutoStreamTestableService($config, $this->logger, $this->credentials, $this->cacheFactory);

        $result = $service->ask('test prompt', '', 'testuser');

        // callCreate throws LogicException in the double, which ask() converts
        // into an error result — proving the non-streaming path was chosen.
        $this->assertTrue($service->createCalled);
        $this->assertFalse($service->streamCalled);
    }
}
