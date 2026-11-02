<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\BaseClient;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Core\Conversion;
use Anthropic\Messages\Message;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Responses are queued as API-shaped arrays and parsed with the SDK's own
 * converter, so the tests see what production sees, including the GA
 * types turning a `fallback` block into another class.
 */
class FallbackTestableService extends ClaudeSDKService {
    /** @var list<array<string, mixed>> */
    public array $responses = [];
    /** @var list<array<string, mixed>> */
    public array $createCalls = [];
    /** @var list<array<string, mixed>|null> */
    public array $requestOptions = [];
    /** @var list<object> */
    public array $streamEvents = [];
    /** @var list<array<string, mixed>> */
    public array $streamCalls = [];
    public bool $platformServesFallback = true;

    protected function platformFeatures(): array {
        return [self::FEATURE_SERVER_FALLBACK => $this->platformServesFallback] + parent::platformFeatures();
    }

    protected function callCreate(BaseClient $client, array $params): Message {
        $this->createCalls[] = $params;
        $this->requestOptions[] = $this->requestOptionsForMessages($params);
        $response = array_shift($this->responses) ?? ['content' => [['type' => 'text', 'text' => 'ok']]];
        /** @var Message */
        return Conversion::coerce(Message::class, $response + [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $params['model'],
            'stop_reason' => 'end_turn', 'stop_details' => null, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function callCreateStream(BaseClient $client, array $params): BaseStream {
        $this->streamCalls[] = $params;
        $events = $this->streamEvents;
        $this->streamEvents = [];
        return new class($events) implements BaseStream {
            /** @param list<object> $events */
            public function __construct(private array $events = []) {
            }
            public function close(): void {
            }
            public function getIterator(): \Iterator {
                return new \ArrayIterator($this->events);
            }
        };
    }

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, mixed> */
    public function batchParams(array $params): array {
        $m = new \ReflectionMethod(ClaudeSDKService::class, 'toBatchParams');
        /** @var array<string, mixed> */
        return $m->invoke($this, $params);
    }
}

class ServerFallbackTest extends TestCase {
    private const HANDOFF = [
        'type' => 'fallback',
        'from' => ['model' => 'claude-opus-5'],
        'to' => ['model' => 'claude-opus-4-8'],
        'trigger' => ['type' => 'refusal', 'category' => 'cyber'],
    ];

    /** @param array<string, string> $appValues */
    private function service(array $appValues = []): FallbackTestableService {
        $config = $this->createStub(AppSettings::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default) => $appValues[$key] ?? match ($key) {
                'model' => ClaudeModels::OPUS_5,
                'max_tokens' => '4096',
                default => $default,
            }
        );
        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createStub(ICache::class);
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new FallbackTestableService(
            $config,
            $this->createStub(LoggerInterface::class),
            $credentials,
            $cacheFactory,
            $this->createStub(RequestMetadataService::class),
        );
    }

    private static function event(string $type, array $fields = []): object {
        return (object)(['type' => $type] + $fields);
    }

    // ── Request ─────────────────────────────────────────────────────────

    public function testDefaultFallbackTravelsWithItsBetaHeader(): void {
        $service = $this->service();
        $service->ask('Hi', '', 'alice');

        $this->assertSame('default', $service->createCalls[0]['fallbacks']);
        $options = $service->requestOptions[0];
        $this->assertSame(['fallbacks' => 'default'], $options['extraBodyParams']);
        // Exactly 2026-07-01: 2026-06-01 rejects the "default" form.
        $this->assertSame('server-side-fallback-2026-07-01', $options['extraHeaders']['anthropic-beta']);
    }

    public function testFastModeAndFallbackShareOneHeader(): void {
        $service = $this->service();
        $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'alice', ['speed' => true]);

        $options = $service->requestOptions[0];
        $this->assertSame(['speed' => 'fast', 'fallbacks' => 'default'], $options['extraBodyParams']);
        $this->assertSame('fast-mode-2026-02-01,server-side-fallback-2026-07-01', $options['extraHeaders']['anthropic-beta']);
    }

    public function testAdminCanSwitchItOff(): void {
        $service = $this->service(['server_fallback' => '0']);
        $service->ask('Hi', '', 'alice');

        $this->assertArrayNotHasKey('fallbacks', $service->createCalls[0]);
        $this->assertNull($service->requestOptions[0]);
    }

    public function testPlatformsWithoutServerFallbackNeverSendIt(): void {
        $service = $this->service();
        $service->platformServesFallback = false;
        $service->ask('Hi', '', 'alice');

        $this->assertArrayNotHasKey('fallbacks', $service->createCalls[0]);
    }

    /** The Batch API turns a request carrying `fallbacks` into an errored item. */
    public function testBatchRequestsNeverCarryIt(): void {
        $service = $this->service();
        $batch = $service->batchParams([
            'model' => ClaudeModels::OPUS_5,
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'fallbacks' => 'default',
        ]);

        $this->assertArrayNotHasKey('fallbacks', $batch);
    }

    // ── Non-streaming responses ─────────────────────────────────────────

    public function testFallbackServedAnswerIsReturned(): void {
        $service = $this->service();
        $service->responses[] = ['model' => 'claude-opus-4-8', 'content' => [self::HANDOFF, ['type' => 'text', 'text' => 'Answer']]];

        $result = $service->ask('Hi', '', 'alice');

        $this->assertSame('Answer', $result['response']);
    }

    /**
     * Every model in the chain declined: the response is the last refusal.
     * Its category can be null, which is a normal value.
     */
    public function testRefusalWithNullCategory(): void {
        $service = $this->service();
        $service->responses[] = [
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null],
        ];

        $result = $service->chat([['role' => 'user', 'content' => 'Hi']], null, 'alice');

        $this->assertSame('refused', $result['error_type']);
        $this->assertNull($result['refusal_category']);
        $this->assertStringContainsString('declined', $result['error']);
    }

    /**
     * The GA SDK parses a `fallback` block into a ToolUseBlock. It must be
     * replayed as a fallback block in place, never run as a tool.
     */
    public function testToolLoopReplaysTheHandoffAndRunsOnlyRealToolCalls(): void {
        $service = $this->service();
        $service->responses[] = [
            'model' => 'claude-opus-4-8',
            'stop_reason' => 'tool_use',
            'content' => [self::HANDOFF, ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'list_files', 'input' => ['path' => '/']]],
        ];
        $service->responses[] = ['content' => [['type' => 'text', 'text' => 'Two files.']]];

        $ran = [];
        $result = $service->chatWithTools(
            [['role' => 'user', 'content' => 'What is in /?']],
            [['name' => 'list_files', 'description' => 'List', 'input_schema' => ['type' => 'object']]],
            function (string $name, array $input) use (&$ran): array {
                $ran[] = $name;
                return ['content' => [['type' => 'text', 'text' => 'a, b']]];
            },
            null,
            'alice',
        );

        $this->assertSame('Two files.', $result['response']);
        $this->assertSame(['list_files'], $ran);
        $replayed = $service->createCalls[1]['messages'][1]['content'];
        $this->assertSame(self::HANDOFF, $replayed[0]);
        $this->assertSame('tool_use', $replayed[1]['type']);
        $this->assertSame('tu_1', $replayed[1]['id']);
    }

    // ── Streaming ───────────────────────────────────────────────────────

    public function testStreamedRefusalIsAnErrorEventMarkedRefused(): void {
        $service = $this->service();
        $service->streamEvents = [
            self::event('message_start', ['message' => (object)['usage' => (object)['inputTokens' => 7]]]),
            self::event('content_block_start', ['index' => 0, 'contentBlock' => (object)['type' => 'text']]),
            self::event('content_block_delta', ['index' => 0, 'delta' => (object)['type' => 'text_delta', 'text' => 'Here is']]),
            self::event('content_block_stop', ['index' => 0]),
            self::event('message_delta', [
                'delta' => (object)['stopReason' => 'refusal', 'stopDetails' => (object)['category' => 'bio', 'explanation' => 'Declined (bio).']],
                'usage' => (object)['outputTokens' => 3],
            ]),
        ];

        $events = iterator_to_array($service->chatWithToolsStream([['role' => 'user', 'content' => 'Hi']], [], fn() => [], null, 'alice'), false);
        $last = end($events);

        $this->assertSame('error', $last['type']);
        $this->assertSame('refused', $last['error_type']);
        $this->assertSame('bio', $last['refusal_category']);
        $this->assertSame('Declined (bio).', $last['error']);
        $this->assertSame(7, $last['usage']['input_tokens']);
        $this->assertNotContains('done', array_column($events, 'type'));
    }

    /**
     * Mid-output handoff on a stream: the declined model's tool call is
     * dropped (and not run); the fallback model's call is run and replayed
     * after the handoff block.
     */
    public function testStreamedMidOutputHandoffRunsOnlyTheFallbackModelsTools(): void {
        $service = $this->service();
        $service->streamEvents = [
            self::event('content_block_start', ['index' => 0, 'contentBlock' => (object)['type' => 'tool_use', 'id' => 'tu_declined', 'name' => 'delete']]),
            self::event('content_block_delta', ['index' => 0, 'delta' => (object)['type' => 'input_json_delta', 'partialJSON' => '{}']]),
            self::event('content_block_stop', ['index' => 0]),
            self::event('content_block_start', ['index' => 1, 'contentBlock' => (object)self::HANDOFF]),
            self::event('content_block_stop', ['index' => 1]),
            self::event('content_block_start', ['index' => 2, 'contentBlock' => (object)['type' => 'tool_use', 'id' => 'tu_kept', 'name' => 'list_files']]),
            self::event('content_block_delta', ['index' => 2, 'delta' => (object)['type' => 'input_json_delta', 'partialJSON' => '{"path":"/"}']]),
            self::event('content_block_stop', ['index' => 2]),
            self::event('message_delta', ['delta' => (object)['stopReason' => 'tool_use']]),
        ];

        $ran = [];
        $generator = $service->chatWithToolsStream(
            [['role' => 'user', 'content' => 'Hi']],
            [['name' => 'list_files', 'description' => 'List', 'input_schema' => ['type' => 'object']]],
            function (string $name) use (&$ran, $service): array {
                $ran[] = $name;
                $service->streamEvents = [self::event('message_delta', ['delta' => (object)['stopReason' => 'end_turn']])];
                return ['content' => [['type' => 'text', 'text' => 'a']]];
            },
            null,
            'alice',
        );
        $events = iterator_to_array($generator, false);

        $this->assertSame(['list_files'], $ran);
        $this->assertSame(['tool_use', 'tool_result', 'done'], array_column($events, 'type'));
        $replayed = $service->streamCalls[1]['messages'][1]['content'];
        $this->assertSame('fallback', $replayed[0]['type']);
        $this->assertSame(['model' => 'claude-opus-4-8'], $replayed[0]['to']);
        $this->assertSame('tu_kept', $replayed[1]['id']);
        $this->assertCount(2, $replayed);
    }
}
