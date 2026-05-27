<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the native MCP connector path on ClaudeSDKService.
 *
 * The Anthropic SDK's BaseStream is an IteratorAggregate; we mock it with
 * an inline subclass that yields a hand-crafted sequence of streaming events
 * mirroring what the API emits when `mcp_servers` is in use:
 *
 *   message_start → content_block_start(text) → content_block_delta(text)
 *     → content_block_stop → content_block_start(mcp_tool_use)
 *     → content_block_delta(input_json) → content_block_stop
 *     → content_block_start(mcp_tool_result, full content inline)
 *     → content_block_stop → message_delta → message_stop
 */
class NativeMcpTestableService extends ClaudeSDKService {
    /** @var BaseStream|null */
    private ?BaseStream $stubStream = null;
    public ?array $lastParams = null;
    public ?array $lastMcpServers = null;
    public bool $shouldThrowOnOpen = false;

    public function setStubStream(BaseStream $stream): void {
        $this->stubStream = $stream;
    }

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    protected function callBetaCreateStreamWithMcp(Client $client, array $params, array $mcpServers): BaseStream {
        $this->lastParams = $params;
        $this->lastMcpServers = $mcpServers;
        if ($this->shouldThrowOnOpen) {
            throw new \RuntimeException('open failed');
        }
        if ($this->stubStream === null) {
            throw new \RuntimeException('test forgot to set stub stream');
        }
        return $this->stubStream;
    }
}

class ClaudeSDKServiceNativeMcpTest extends TestCase {
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
        // Cached caps to avoid hitting models->retrieve from buildRequestParams.
        $this->cache->method('get')->willReturn([
            'max_tokens' => 8192,
            'supports_thinking' => false,
            'supports_effort' => false,
        ]);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default) => match ($key) {
                'model' => ClaudeModels::OPUS_4_6,
                'max_tokens' => '4096',
                default => $default,
            }
        );
    }

    private function streamFromEvents(array $events): BaseStream {
        $stream = $this->createMock(BaseStream::class);
        $stream->method('getIterator')->willReturn(new \ArrayIterator($events));
        return $stream;
    }

    private function obj(array $props): \stdClass {
        $o = new \stdClass();
        foreach ($props as $k => $v) {
            if (is_array($v) && array_keys($v) !== range(0, count($v) - 1)) {
                $o->$k = $this->obj($v);
            } else {
                $o->$k = $v;
            }
        }
        return $o;
    }

    public function testEmptyMcpServersYieldsError(): void {
        $service = new NativeMcpTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $events = iterator_to_array($service->chatWithNativeMcp(
            messages: [['role' => 'user', 'content' => 'hi']],
            mcpServers: [],
            userId: 'alice',
        ), false);

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('no reachable HTTPS', $events[0]['error']);
    }

    public function testStreamMapsTextAndMcpBlocksToEvents(): void {
        $service = new NativeMcpTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);

        $stream = [
            $this->obj([
                'type' => 'message_start',
                'message' => ['usage' => ['inputTokens' => 100, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0]],
            ]),
            // Text block
            $this->obj(['type' => 'content_block_start', 'index' => 0, 'contentBlock' => ['type' => 'text']]),
            $this->obj(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]),
            $this->obj(['type' => 'content_block_stop', 'index' => 0]),
            // mcp_tool_use block — input arrives via input_json_delta
            $this->obj([
                'type' => 'content_block_start', 'index' => 1,
                'contentBlock' => ['type' => 'mcp_tool_use', 'id' => 'tu_1', 'name' => 'list_files', 'serverName' => 'aiquila'],
            ]),
            $this->obj(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partialJSON' => '{"path":"/"}']]),
            $this->obj(['type' => 'content_block_stop', 'index' => 1]),
            // mcp_tool_result inline
            $this->obj([
                'type' => 'content_block_start', 'index' => 2,
                'contentBlock' => ['type' => 'mcp_tool_result', 'toolUseID' => 'tu_1', 'isError' => false, 'content' => 'file1\nfile2'],
            ]),
            $this->obj(['type' => 'content_block_stop', 'index' => 2]),
            $this->obj(['type' => 'message_delta', 'delta' => ['stopReason' => 'end_turn'], 'usage' => ['outputTokens' => 25]]),
            $this->obj(['type' => 'message_stop']),
        ];
        $service->setStubStream($this->streamFromEvents($stream));

        $servers = [['type' => 'url', 'name' => 'aiquila', 'url' => 'https://mcp.example.com/mcp']];
        $events = iterator_to_array($service->chatWithNativeMcp(
            messages: [['role' => 'user', 'content' => 'list root']],
            mcpServers: $servers,
            system: 'be helpful',
            userId: 'alice',
        ), false);

        // The request was sent with the right mcpServers list.
        $this->assertSame($servers, $service->lastMcpServers);

        $types = array_column($events, 'type');
        $this->assertContains('text_delta', $types);
        $this->assertContains('tool_use', $types);
        $this->assertContains('tool_result', $types);
        $this->assertSame('done', end($events)['type']);

        // Find specific events.
        $textDelta = $events[array_search('text_delta', $types)];
        $this->assertSame('Hello', $textDelta['text']);

        $toolUse = $events[array_search('tool_use', $types)];
        $this->assertSame('tu_1', $toolUse['id']);
        $this->assertSame('list_files', $toolUse['name']);
        $this->assertSame(['path' => '/'], $toolUse['input']);
        $this->assertSame('aiquila', $toolUse['server']);

        $toolResult = $events[array_search('tool_result', $types)];
        $this->assertSame('tu_1', $toolResult['tool_use_id']);
        $this->assertSame('file1\nfile2', $toolResult['output']);
        $this->assertFalse($toolResult['is_error']);

        $done = end($events);
        $this->assertSame(100, $done['usage']['input_tokens']);
        $this->assertSame(25, $done['usage']['output_tokens']);
    }

    public function testOpenFailureYieldsErrorEvent(): void {
        $service = new NativeMcpTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $service->shouldThrowOnOpen = true;

        $events = iterator_to_array($service->chatWithNativeMcp(
            messages: [['role' => 'user', 'content' => 'hi']],
            mcpServers: [['type' => 'url', 'name' => 'x', 'url' => 'https://x.test/mcp']],
            userId: 'alice',
        ), false);

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertSame('open failed', $events[0]['error']);
    }

    public function testCollectFlattensToLegacyShape(): void {
        $service = new NativeMcpTestableService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $stream = [
            $this->obj(['type' => 'message_start', 'message' => ['usage' => ['inputTokens' => 5, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0]]]),
            $this->obj(['type' => 'content_block_start', 'index' => 0, 'contentBlock' => ['type' => 'text']]),
            $this->obj(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'OK']]),
            $this->obj(['type' => 'content_block_stop', 'index' => 0]),
            $this->obj(['type' => 'message_delta', 'delta' => ['stopReason' => 'end_turn'], 'usage' => ['outputTokens' => 1]]),
            $this->obj(['type' => 'message_stop']),
        ];
        $service->setStubStream($this->streamFromEvents($stream));

        $result = $service->chatWithNativeMcpCollect(
            messages: [['role' => 'user', 'content' => 'hi']],
            mcpServers: [['type' => 'url', 'name' => 'x', 'url' => 'https://x.test/mcp']],
            userId: 'alice',
        );

        $this->assertSame('OK', $result['response']);
        $this->assertSame(5, $result['usage']['input_tokens']);
        $this->assertSame(1, $result['usage']['output_tokens']);
        $this->assertArrayNotHasKey('error', $result);
    }
}
