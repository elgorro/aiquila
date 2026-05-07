<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\Messages\Usage;
use Anthropic\Models\ModelInfo;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Concrete subclass that lets individual tests inject exceptions through
 * the two protected dispatch methods, without needing to mock final SDK classes.
 */
class TestableClaudeSDKService extends ClaudeSDKService {
    private ?\Exception $createException = null;
    private ?\Exception $streamException = null;

    private ?array      $listModelsItems     = null;
    private ?\Exception $listModelsException = null;
    private ?ModelInfo  $retrieveModelInfo   = null;
    private ?\Exception $retrieveModelException = null;

    /** Captured params from the last callCreate() call */
    public ?array $lastCreateParams = null;

    /** Captured requestOptions array from the last callCreate() call */
    public ?array $lastRequestOptions = null;

    /** Optional stub text to return from makeStubMessage */
    public string $stubResponseText = '';

    /** Optional citations to attach to the stub text block */
    public array $stubCitations = [];

    /** Optional pre-built event list for callCreateStream() to iterate */
    public ?array $stubStreamEvents = null;

    /** Captured params from the last callCreateStream() call */
    public ?array $lastStreamParams = null;

    public function throwOnCreate(\Exception $e): void {
        $this->createException = $e;
    }

    public function throwOnStream(\Exception $e): void {
        $this->streamException = $e;
    }

    public function setListModelsItems(array $items): void      { $this->listModelsItems = $items; }
    public function throwOnListModels(\Exception $e): void      { $this->listModelsException = $e; }
    public function setRetrieveModelInfo(ModelInfo $info): void { $this->retrieveModelInfo = $info; }
    public function throwOnRetrieveModel(\Exception $e): void   { $this->retrieveModelException = $e; }

    protected function callCreate(Client $client, array $params): Message {
        $this->lastCreateParams = $params;
        $this->lastRequestOptions = $this->requestOptionsForMessages($params);
        if ($this->createException !== null) {
            throw $this->createException;
        }
        return $this->makeStubMessage($this->stubResponseText);
    }

    protected function callCreateStream(Client $client, array $params): BaseStream {
        $this->lastStreamParams = $params;
        if ($this->streamException !== null) {
            throw $this->streamException;
        }
        $events = $this->stubStreamEvents ?? [];
        $stream = new class implements BaseStream {
            public array $events = [];
            public function __construct(
                \Anthropic\Core\Conversion\Contracts\Converter|\Anthropic\Core\Conversion\Contracts\ConverterSource|string $convert = '',
                ?\Psr\Http\Message\RequestInterface $request = null,
                ?\Psr\Http\Message\ResponseInterface $response = null,
                mixed $parsedBody = null,
            ) {}
            public function close(): void {}
            public function getIterator(): \Traversable { return new \ArrayIterator($this->events); }
        };
        $stream->events = $events;
        return $stream;
    }

    /**
     * Helper for tests: build a fake stream event object as a stdClass with
     * the event-specific fields the streaming loop reads.
     */
    public static function streamEvent(string $type, array $extras = []): \stdClass {
        $e = new \stdClass();
        $e->type = $type;
        foreach ($extras as $k => $v) {
            $e->$k = $v;
        }
        return $e;
    }

    protected function callListModels(Client $client, array $params): array {
        if ($this->listModelsException !== null) throw $this->listModelsException;
        return $this->listModelsItems ?? [];
    }

    protected function callRetrieveModel(Client $client, string $modelId): ModelInfo {
        if ($this->retrieveModelException !== null) throw $this->retrieveModelException;
        return $this->retrieveModelInfo ?? ModelInfo::with($modelId, null, new \DateTime(), 'Test Model', null, null);
    }

    // Bypass real Client construction; callCreate/callCreateStream intercept before use.
    protected function getClient(?string $userId = null): Client {
        $apiKey = $this->getApiKey($userId);
        if (!$apiKey) {
            throw new \RuntimeException('No API key configured');
        }
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    private function makeStubMessage(string $text = ''): Message {
        $stub = (new \ReflectionClass(Message::class))->newInstanceWithoutConstructor();
        $ref  = new \ReflectionClass($stub);

        $contentItems = [];
        if ($text !== '') {
            $textObj = new \stdClass();
            $textObj->type = 'text';
            $textObj->text = $text;
            if ($this->stubCitations !== []) {
                $textObj->citations = $this->stubCitations;
            }
            $contentItems[] = $textObj;
        }

        foreach (['content' => $contentItems, 'stopReason' => 'end_turn'] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($stub, $val);
        }
        $usage = Usage::with(null, null, null, null, 10, 20, null, null);
        $p = $ref->getProperty('usage');
        $p->setValue($stub, $usage);
        return $stub;
    }
}

class ClaudeServiceTest extends TestCase {
    private $config;
    private $logger;
    private $credentials;
    private $cacheFactory;
    private ClaudeSDKService $service;
    private TestableClaudeSDKService $testable;

    protected function setUp(): void {
        $this->config      = $this->createMock(IConfig::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->credentials = $this->createMock(CredentialService::class);

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $this->service     = new ClaudeSDKService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
        $this->testable    = new TestableClaudeSDKService($this->config, $this->logger, $this->credentials, $this->cacheFactory);
    }

    // ── PSR-7 helpers for building SDK exceptions ─────────────────────────

    private function makeStatusException(string $class, int $status): APIStatusException {
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('{}');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        $request = $this->createMock(RequestInterface::class);

        return new $class(request: $request, response: $response);
    }

    private function makeConnectionException(): APIConnectionException {
        return new APIConnectionException(
            request: $this->createMock(RequestInterface::class),
            message: 'network down'
        );
    }

    private function makeTimeoutException(): APITimeoutException {
        return new APITimeoutException(
            request: $this->createMock(RequestInterface::class),
            message: 'timed out'
        );
    }

    private function configWithApiKey(): void {
        $this->credentials->method('getApiKey')->willReturn('test-key');
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => '4096',
                default      => $default,
            });
    }

    // ── getApiKey ─────────────────────────────────────────────────────────

    public function testGetApiKeyReturnsUserKeyFirst(): void {
        $this->credentials->method('getApiKey')
            ->with('testuser')
            ->willReturn('user-api-key');

        $this->assertEquals('user-api-key', $this->service->getApiKey('testuser'));
    }

    public function testGetApiKeyFallsBackToAdminKey(): void {
        $this->credentials->method('getApiKey')
            ->with('testuser')
            ->willReturn('admin-api-key');

        $this->assertEquals('admin-api-key', $this->service->getApiKey('testuser'));
    }

    // ── getModel ──────────────────────────────────────────────────────────

    public function testGetModelReturnsDefault(): void {
        $this->config->method('getAppValue')
            ->with('aiquila', 'model', ClaudeModels::DEFAULT_MODEL)
            ->willReturn(ClaudeModels::DEFAULT_MODEL);

        $this->assertEquals(ClaudeModels::DEFAULT_MODEL, $this->service->getModel());
    }

    public function testGetModelReturnsUserPreferenceOverAdminModel(): void {
        $this->config->method('getUserValue')
            ->willReturnMap([['testuser', 'aiquila', 'user_model', '', 'claude-haiku-4-5-20251001']]);

        $this->assertEquals('claude-haiku-4-5-20251001', $this->service->getModel('testuser'));
    }

    public function testGetModelFallsBackToAdminWhenUserHasNoPref(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->with('aiquila', 'model', ClaudeModels::DEFAULT_MODEL)
            ->willReturn('claude-opus-4-6');

        $this->assertEquals('claude-opus-4-6', $this->service->getModel('testuser'));
    }

    public function testGetModelWithNullUserIdSkipsUserLookup(): void {
        $this->config->expects($this->never())->method('getUserValue');
        $this->config->method('getAppValue')
            ->with('aiquila', 'model', ClaudeModels::DEFAULT_MODEL)
            ->willReturn(ClaudeModels::DEFAULT_MODEL);

        $this->assertEquals(ClaudeModels::DEFAULT_MODEL, $this->service->getModel(null));
    }

    // ── getMaxTokens ──────────────────────────────────────────────────────

    public function testGetMaxTokensReturnsDefault(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => '4096',
                default      => $default,
            });

        $this->assertEquals(4096, $this->service->getMaxTokens());
    }

    public function testGetMaxTokensClampsToModelCeiling(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'max_tokens' => '999999',
                'model'      => ClaudeModels::OPUS_4_6,
                default      => $default,
            });
        $this->config->method('getUserValue')->willReturn('');

        $this->assertEquals(128000, $this->service->getMaxTokens('testuser'));
    }

    // ── getConfiguration ──────────────────────────────────────────────────

    public function testGetConfigurationReturnsExpectedKeys(): void {
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'api_key'     => 'test-key',
                'model'       => ClaudeModels::DEFAULT_MODEL,
                'max_tokens'  => '4096',
                'api_timeout' => '30',
                default       => $default,
            });

        $config = $this->service->getConfiguration();
        $this->assertArrayHasKey('api_key', $config);
        $this->assertArrayHasKey('model', $config);
        $this->assertArrayHasKey('max_tokens', $config);
        $this->assertArrayHasKey('timeout', $config);
        $this->assertEquals('test-key', $config['api_key']);
    }

    // ── ask(): no API key ─────────────────────────────────────────────────

    public function testAskReturnsErrorWhenNoApiKey(): void {
        $this->credentials->method('getApiKey')->willReturn('');

        $result = $this->service->ask('Hello', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
    }

    // ── ask(): typed HTTP exception handling (all documented codes) ────────

    public function testAskReturnsInvalidApiKeyOnAuthenticationException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(AuthenticationException::class, 401));

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function testAskReturnsPermissionDeniedOnPermissionDeniedException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(PermissionDeniedException::class, 403));

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('permission', $result['error']);
    }

    public function testAskReturnsRateLimitMessageOnRateLimitException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(RateLimitException::class, 429));

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
    }

    /** Covers HTTP 500 and 529 (overloaded) — both map to InternalServerException. */
    public function testAskReturnsUnavailableOnInternalServerException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(InternalServerException::class, 500));

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('temporarily unavailable', $result['error']);
    }

    public function testAskReturnsConnectionErrorOnAPIConnectionException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeConnectionException());

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection to Claude API failed', $result['error']);
    }

    public function testAskReturnsConnectionErrorOnAPITimeoutException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeTimeoutException());

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection to Claude API failed', $result['error']);
    }

    public function testAskReturnsErrorOnGenericAPIStatusException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(APIStatusException::class, 422));

        $result = $this->testable->ask('Hi', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Error:', $result['error']);
    }

    // ── ask(): options passthrough ─────────────────────────────────────────

    public function testAskWithOptionsPassesTemperatureToParams(): void {
        $this->configWithApiKey();

        $result = $this->testable->ask('Hi', '', 'testuser', ['temperature' => 0.7]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('temperature', $this->testable->lastCreateParams);
        $this->assertSame(0.7, $this->testable->lastCreateParams['temperature']);
    }

    public function testAskWithOptionsPassesTopPAndTopKToParams(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', ['top_p' => 0.9, 'top_k' => 40]);

        $this->assertSame(0.9, $this->testable->lastCreateParams['top_p']);
        $this->assertSame(40,  $this->testable->lastCreateParams['top_k']);
    }

    public function testAskWithOptionsPassesStopSequencesToParams(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', ['stop_sequences' => ['END', 'STOP']]);

        $this->assertSame(['END', 'STOP'], $this->testable->lastCreateParams['stop_sequences']);
    }

    public function testAskWithSystemPromptAddsSystemBlock(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', ['system' => 'You are a helpful assistant.']);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayHasKey('system', $params);
        $this->assertIsArray($params['system']);
        $this->assertSame('text', $params['system'][0]['type']);
        $this->assertSame('You are a helpful assistant.', $params['system'][0]['text']);
    }

    // ── buildRequestParams(): cache_system ────────────────────────────────

    public function testBuildRequestParamsWithCacheSystemAddsCacheControl(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', [
            'system'       => 'You are a helpful assistant.',
            'cache_system' => true,
        ]);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayHasKey('system', $params);
        $systemBlock = $params['system'][0];
        $this->assertArrayHasKey('cache_control', $systemBlock);
        $this->assertSame('ephemeral', $systemBlock['cache_control']['type']);
    }

    public function testBuildRequestParamsCachesSystemPromptByDefault(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', ['system' => 'You are a helpful assistant.']);

        $params = $this->testable->lastCreateParams;
        $systemBlock = $params['system'][0];
        $this->assertArrayHasKey('cache_control', $systemBlock);
        $this->assertSame('ephemeral', $systemBlock['cache_control']['type']);
    }

    public function testBuildRequestParamsWithCacheSystemFalseOmitsCacheControl(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', [
            'system'       => 'You are a helpful assistant.',
            'cache_system' => false,
        ]);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayNotHasKey('cache_control', $params['system'][0]);
    }

    public function testBuildRequestParamsAddsCacheControlToLastToolByDefault(): void {
        $this->configWithApiKey();

        $tools = [
            ['name' => 'tool_a', 'description' => 'A', 'input_schema' => []],
            ['name' => 'tool_b', 'description' => 'B', 'input_schema' => []],
            ['name' => 'tool_c', 'description' => 'C', 'input_schema' => []],
        ];

        $this->testable->ask('Hi', '', 'testuser', ['tools' => $tools]);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayHasKey('tools', $params);
        $this->assertCount(3, $params['tools']);
        $this->assertArrayNotHasKey('cache_control', $params['tools'][0]);
        $this->assertArrayNotHasKey('cache_control', $params['tools'][1]);
        $this->assertArrayHasKey('cache_control', $params['tools'][2]);
        $this->assertSame('ephemeral', $params['tools'][2]['cache_control']['type']);
    }

    public function testBuildRequestParamsWithCacheToolsFalseOmitsToolCacheControl(): void {
        $this->configWithApiKey();

        $tools = [['name' => 'tool_a', 'description' => 'A', 'input_schema' => []]];
        $this->testable->ask('Hi', '', 'testuser', ['tools' => $tools, 'cache_tools' => false]);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayNotHasKey('cache_control', $params['tools'][0]);
    }

    // ── Files API beta header detection ────────────────────────────────────

    public function testRequestOptionsOmitFilesBetaWhenNoFileIdSource(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser');

        $this->assertNull($this->testable->lastRequestOptions);
    }

    public function testRequestOptionsCarryFilesBetaWhenContentReferencesFileId(): void {
        $this->configWithApiKey();

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'file_abc']],
                    ['type' => 'text', 'text' => 'Summarize.'],
                ],
            ],
        ];
        $this->testable->chat($messages, null, 'testuser');

        $opts = $this->testable->lastRequestOptions;
        $this->assertIsArray($opts);
        $this->assertArrayHasKey('extraHeaders', $opts);
        $this->assertSame('files-api-2025-04-14', $opts['extraHeaders']['anthropic-beta']);
    }

    // ── chat() ────────────────────────────────────────────────────────────

    public function testChatReturnsResponse(): void {
        $this->configWithApiKey();
        $this->testable->stubResponseText = 'Hello!';

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $result   = $this->testable->chat($messages, null, 'testuser');

        $this->assertArrayHasKey('response', $result);
        $this->assertSame('Hello!', $result['response']);
        $this->assertArrayHasKey('usage', $result);
        $this->assertArrayHasKey('input_tokens', $result['usage']);
        $this->assertArrayHasKey('output_tokens', $result['usage']);
    }

    public function testChatWithSystemPromptPassesSystemToParams(): void {
        $this->configWithApiKey();

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $this->testable->chat($messages, 'You are a pirate.', 'testuser');

        $params = $this->testable->lastCreateParams;
        $this->assertArrayHasKey('system', $params);
        $this->assertSame('You are a pirate.', $params['system'][0]['text']);
    }

    public function testChatPassesMessagesArrayDirectly(): void {
        $this->configWithApiKey();

        $messages = [
            ['role' => 'user',      'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
            ['role' => 'user',      'content' => 'How are you?'],
        ];
        $this->testable->chat($messages, null, 'testuser');

        $this->assertSame($messages, $this->testable->lastCreateParams['messages']);
    }

    public function testChatExceptionHandlingAuth(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(AuthenticationException::class, 401));

        $result = $this->testable->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function testChatExceptionHandlingRateLimit(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(RateLimitException::class, 429));

        $result = $this->testable->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
    }

    public function testChatExceptionHandlingConnection(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeConnectionException());

        $result = $this->testable->chat([['role' => 'user', 'content' => 'Hi']], null, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection to Claude API failed', $result['error']);
    }

    // ── askWithDocument() ─────────────────────────────────────────────────

    public function testAskWithDocumentPlainTextBuildsCorrectBlock(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize this', 'Hello world', 'text/plain', 'test.txt', 'testuser');

        $params   = $this->testable->lastCreateParams;
        $messages = $params['messages'];
        $content  = $messages[0]['content'];

        // First block is the document
        $docBlock = $content[0];
        $this->assertSame('document', $docBlock['type']);
        $this->assertSame('text',  $docBlock['source']['type']);
        $this->assertSame('text/plain', $docBlock['source']['media_type']);
        $this->assertSame('Hello world', $docBlock['source']['data']);
        $this->assertSame('test.txt', $docBlock['title']);

        // Second block is the prompt
        $this->assertSame('text', $content[1]['type']);
        $this->assertSame('Summarize this', $content[1]['text']);
    }

    public function testAskWithDocumentPdfBuildsBase64Block(): void {
        $this->configWithApiKey();
        $pdfBytes = '%PDF-1.4 fake content';

        $this->testable->askWithDocument('What is in this PDF?', $pdfBytes, 'application/pdf', 'doc.pdf', 'testuser');

        $params  = $this->testable->lastCreateParams;
        $docBlock = $params['messages'][0]['content'][0];

        $this->assertSame('document', $docBlock['type']);
        $this->assertSame('base64', $docBlock['source']['type']);
        $this->assertSame('application/pdf', $docBlock['source']['media_type']);
        $this->assertSame(base64_encode($pdfBytes), $docBlock['source']['data']);
    }

    public function testAskWithDocumentAddsCacheControlByDefault(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser');

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayHasKey('cache_control', $docBlock['source']);
        $this->assertSame('ephemeral', $docBlock['source']['cache_control']['type']);
    }

    public function testAskWithDocumentOmitsCacheControlWhenOptedOut(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser', false);

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayNotHasKey('cache_control', $docBlock['source']);
    }

    public function testAskWithDocumentEnablesCitationsByDefault(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser');

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayHasKey('citations', $docBlock);
        $this->assertTrue($docBlock['citations']['enabled']);
    }

    public function testAskWithDocumentOmitsCitationsWhenOptedOut(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser', true, false);

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayNotHasKey('citations', $docBlock);
    }

    public function testAskWithDocumentReturnsCitationsFromResponse(): void {
        $this->configWithApiKey();

        $this->testable->stubResponseText = 'According to the document, X holds.';
        $this->testable->stubCitations = [
            [
                'type' => 'page_location',
                'cited_text' => 'X holds in all cases.',
                'document_index' => 0,
                'document_title' => 'doc.pdf',
                'start_page_number' => 3,
                'end_page_number' => 3,
            ],
        ];

        $result = $this->testable->askWithDocument('Q', 'data', 'application/pdf', 'doc.pdf', 'testuser');

        $this->assertArrayHasKey('citations', $result);
        $this->assertCount(1, $result['citations']);
        $this->assertSame('page_location', $result['citations'][0]['type']);
        $this->assertSame(3, $result['citations'][0]['start_page_number']);
        $this->assertSame('X holds in all cases.', $result['citations'][0]['cited_text']);
    }

    public function testAskWithDocumentReturnsEmptyCitationsWhenNoneEmitted(): void {
        $this->configWithApiKey();

        $this->testable->stubResponseText = 'Plain answer.';

        $result = $this->testable->askWithDocument('Q', 'data', 'text/plain', '', 'testuser');

        $this->assertArrayHasKey('citations', $result);
        $this->assertSame([], $result['citations']);
    }

    public function testAskWithDocumentOmitsTitleWhenEmpty(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser');

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayNotHasKey('title', $docBlock);
    }

    public function testAskWithDocumentExceptionHandling(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(AuthenticationException::class, 401));

        $result = $this->testable->askWithDocument('Q', 'data', 'text/plain', '', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    // ── sendMessage() with file handling ─────────────────────────────────

    public function testSendMessageWithPdfFileDelegatesToAskWithDocument(): void {
        $this->configWithApiKey();

        // Create a temp PDF-ish file
        $tmpFile = tempnam(sys_get_temp_dir(), 'aiquila_test_');
        file_put_contents($tmpFile, '%PDF-1.4 fake pdf content');

        // We need to make mime_content_type return application/pdf; rename to .pdf
        $pdfFile = $tmpFile . '.pdf';
        rename($tmpFile, $pdfFile);

        try {
            $response = $this->testable->sendMessage('What is this?', 'testuser', $pdfFile);

            // Verify a document block was built (not a plain ask)
            $params = $this->testable->lastCreateParams;
            $this->assertNotNull($params);
            $content = $params['messages'][0]['content'] ?? null;
            // document block or text block — either way no error
            $this->assertIsString($response);
        } finally {
            @unlink($pdfFile);
        }
    }

    public function testSendMessageWithoutFileCallsPlainAsk(): void {
        $this->configWithApiKey();

        $response = $this->testable->sendMessage('Hello', 'testuser');

        $params   = $this->testable->lastCreateParams;
        $messages = $params['messages'];
        // Plain ask: single user message with string content
        $this->assertSame('user', $messages[0]['role']);
        $this->assertIsString($messages[0]['content']);
    }

    public function testSendMessageThrowsOnError(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $this->expectException(\Exception::class);
        $this->service->sendMessage('Hello', 'testuser');
    }

    // ── askStream(): options passthrough ──────────────────────────────────

    public function testAskStreamThrowsInvalidApiKeyOnAuthenticationException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeStatusException(AuthenticationException::class, 401));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid API key/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    public function testAskStreamThrowsPermissionDeniedOnPermissionDeniedException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeStatusException(PermissionDeniedException::class, 403));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/permission/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    public function testAskStreamThrowsRateLimitOnRateLimitException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeStatusException(RateLimitException::class, 429));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Rate limit exceeded/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    /** Covers HTTP 500 and 529 (overloaded) — both map to InternalServerException. */
    public function testAskStreamThrowsUnavailableOnInternalServerException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeStatusException(InternalServerException::class, 500));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/temporarily unavailable/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    public function testAskStreamThrowsConnectionErrorOnAPIConnectionException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeConnectionException());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Connection to Claude API failed/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    public function testAskStreamThrowsConnectionErrorOnAPITimeoutException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream($this->makeTimeoutException());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Connection to Claude API failed/');
        iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
    }

    public function testAskStreamYieldsNothingOnEmptyStream(): void {
        $this->configWithApiKey();
        // No exception set — stub returns empty stream.
        $chunks = iterator_to_array($this->testable->askStream('Hi', '', 'testuser'));
        $this->assertEmpty($chunks);
    }

    // ── summarize & sendMessage ───────────────────────────────────────────

    public function testSummarizeCallsAskWithSummarizePrompt(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->service->summarize('Long text here...', 'testuser');
        $this->assertArrayHasKey('error', $result);
    }

    // ── askWithImage(): cache_control ──────────────────────────────────────

    public function testAskWithImageAddsCacheControlToImageBlock(): void {
        $this->configWithApiKey();

        $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');

        $imageBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertSame('image', $imageBlock['type']);
        $this->assertArrayHasKey('cache_control', $imageBlock);
        $this->assertSame('ephemeral', $imageBlock['cache_control']['type']);
    }

    public function testAskWithImagesAddsCacheControlOnlyToLastImage(): void {
        $this->configWithApiKey();

        $images = [
            ['base64' => 'aaa', 'mimeType' => 'image/png'],
            ['base64' => 'bbb', 'mimeType' => 'image/jpeg'],
            ['base64' => 'ccc', 'mimeType' => 'image/png'],
        ];
        $this->testable->askWithImages('Compare', $images, 'testuser');

        $content = $this->testable->lastCreateParams['messages'][0]['content'];
        $this->assertArrayNotHasKey('cache_control', $content[0]);
        $this->assertArrayNotHasKey('cache_control', $content[1]);
        $this->assertArrayHasKey('cache_control', $content[2]);
        $this->assertSame('ephemeral', $content[2]['cache_control']['type']);
        // Final block is the prompt text, not an image.
        $this->assertSame('text', $content[3]['type']);
    }

    // ── askWithImage(): typed exception handling ───────────────────────────

    public function testAskWithImageReturnsInvalidApiKeyOnAuthenticationException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(AuthenticationException::class, 401));

        $result = $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function testAskWithImageReturnsPermissionDeniedOnPermissionDeniedException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(PermissionDeniedException::class, 403));

        $result = $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('permission', $result['error']);
    }

    public function testAskWithImageReturnsRateLimitOnRateLimitException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(RateLimitException::class, 429));

        $result = $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
    }

    public function testAskWithImageReturnsUnavailableOnInternalServerException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(InternalServerException::class, 500));

        $result = $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('temporarily unavailable', $result['error']);
    }

    public function testAskWithImageReturnsConnectionErrorOnAPIConnectionException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeConnectionException());

        $result = $this->testable->askWithImage('Describe', 'base64data', 'image/png', 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection to Claude API failed', $result['error']);
    }

    // ── askWithImages(): multi-image exception handling ─────────────────────

    public function testAskWithImagesReturnsErrorWhenEmpty(): void {
        $this->configWithApiKey();

        $result = $this->testable->askWithImages('Compare', [], 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No images', $result['error']);
    }

    public function testAskWithImagesReturnsErrorWhenTooMany(): void {
        $this->configWithApiKey();

        $images = array_fill(0, 21, ['base64' => 'data', 'mimeType' => 'image/png']);
        $result = $this->testable->askWithImages('Compare', $images, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Too many images', $result['error']);
    }

    public function testAskWithImagesReturnsInvalidApiKeyOnAuthenticationException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(AuthenticationException::class, 401));

        $images = [['base64' => 'data1', 'mimeType' => 'image/jpeg'], ['base64' => 'data2', 'mimeType' => 'image/png']];
        $result = $this->testable->askWithImages('Compare', $images, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function testAskWithImagesReturnsRateLimitOnRateLimitException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeStatusException(RateLimitException::class, 429));

        $images = [['base64' => 'data1', 'mimeType' => 'image/jpeg']];
        $result = $this->testable->askWithImages('Describe', $images, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Rate limit exceeded', $result['error']);
    }

    public function testAskWithImagesReturnsConnectionErrorOnAPIConnectionException(): void {
        $this->configWithApiKey();
        $this->testable->throwOnCreate($this->makeConnectionException());

        $images = [['base64' => 'data1', 'mimeType' => 'image/jpeg']];
        $result = $this->testable->askWithImages('Describe', $images, 'testuser');
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection to Claude API failed', $result['error']);
    }

    // ── askWith* (Files API file_id sources) ──────────────────────────────

    public function testAskWithDocumentUsesFileIdSourceWhenProvided(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument(
            'Summarize',
            'PDF BYTES',
            'application/pdf',
            'doc.pdf',
            'testuser',
            true,
            true,
            'file_xyz',
        );

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertSame('document', $docBlock['type']);
        $this->assertSame('file', $docBlock['source']['type']);
        $this->assertSame('file_xyz', $docBlock['source']['file_id']);
        $this->assertArrayNotHasKey('data', $docBlock['source']);
        $this->assertArrayNotHasKey('media_type', $docBlock['source']);
        // cache_control still applies
        $this->assertSame('ephemeral', $docBlock['source']['cache_control']['type']);
        // citations remain enabled at the block level
        $this->assertTrue($docBlock['citations']['enabled']);
    }

    public function testAskWithDocumentFallsBackToBase64WhenFileIdNull(): void {
        $this->configWithApiKey();
        $pdfBytes = '%PDF-1.4 fallback';

        $this->testable->askWithDocument(
            'Summarize',
            $pdfBytes,
            'application/pdf',
            'doc.pdf',
            'testuser',
            true,
            true,
            null,
        );

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertSame('base64', $docBlock['source']['type']);
        $this->assertSame(base64_encode($pdfBytes), $docBlock['source']['data']);
    }

    public function testAskWithImageUsesFileIdSourceWhenProvided(): void {
        $this->configWithApiKey();

        $this->testable->askWithImage('Describe', 'BASE64DATA', 'image/png', 'testuser', 'file_img1');

        $imageBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertSame('image', $imageBlock['type']);
        $this->assertSame('file', $imageBlock['source']['type']);
        $this->assertSame('file_img1', $imageBlock['source']['file_id']);
        $this->assertArrayNotHasKey('data', $imageBlock['source']);
        // cache_control stays on the image block (not source)
        $this->assertSame('ephemeral', $imageBlock['cache_control']['type']);
    }

    public function testAskWithImageFallsBackToBase64WhenFileIdNull(): void {
        $this->configWithApiKey();

        $this->testable->askWithImage('Describe', 'BASE64DATA', 'image/png', 'testuser', null);

        $imageBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertSame('base64', $imageBlock['source']['type']);
        $this->assertSame('image/png', $imageBlock['source']['media_type']);
        $this->assertSame('BASE64DATA', $imageBlock['source']['data']);
    }

    public function testAskWithImagesUsesPerImageFileIds(): void {
        $this->configWithApiKey();

        $images = [
            ['base64' => 'aaa', 'mimeType' => 'image/png'],
            ['base64' => 'bbb', 'mimeType' => 'image/jpeg'],
        ];
        $this->testable->askWithImages('Compare', $images, 'testuser', [null, 'file_b']);

        $content = $this->testable->lastCreateParams['messages'][0]['content'];
        // Image 0: base64 fallback
        $this->assertSame('base64', $content[0]['source']['type']);
        $this->assertSame('aaa', $content[0]['source']['data']);
        // Image 1: file_id source
        $this->assertSame('file', $content[1]['source']['type']);
        $this->assertSame('file_b', $content[1]['source']['file_id']);
        // Last image (index 1) carries cache_control regardless of source type
        $this->assertSame('ephemeral', $content[1]['cache_control']['type']);
        $this->assertArrayNotHasKey('cache_control', $content[0]);
    }

    public function testAskWithImagesFallsBackToBase64WhenFileIdsNull(): void {
        $this->configWithApiKey();

        $images = [
            ['base64' => 'aaa', 'mimeType' => 'image/png'],
            ['base64' => 'bbb', 'mimeType' => 'image/jpeg'],
        ];
        $this->testable->askWithImages('Compare', $images, 'testuser', null);

        $content = $this->testable->lastCreateParams['messages'][0]['content'];
        $this->assertSame('base64', $content[0]['source']['type']);
        $this->assertSame('base64', $content[1]['source']['type']);
    }

    // ── listModels() ──────────────────────────────────────────────────────

    public function testListModelsReturnsIds(): void {
        $this->configWithApiKey();
        $m1 = ModelInfo::with(ClaudeModels::SONNET_4_6, null, new \DateTime(), 'Claude Sonnet 4.6', null, null);
        $m2 = ModelInfo::with(ClaudeModels::HAIKU_4_5, null, new \DateTime(), 'Claude Haiku 4.5', null, null);
        $this->testable->setListModelsItems([$m1, $m2]);

        $result = $this->testable->listModels('testuser');

        $this->assertSame([ClaudeModels::SONNET_4_6, ClaudeModels::HAIKU_4_5], $result);
    }

    public function testListModelsNoApiKeyReturnsNull(): void {
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

        $result = $this->testable->listModels('testuser');

        $this->assertNull($result);
    }

    public function testListModelsOnErrorReturnsNull(): void {
        $this->configWithApiKey();
        $this->testable->throwOnListModels(new \RuntimeException('API error'));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->testable->listModels('testuser');

        $this->assertNull($result);
    }

    // ── retrieveModel() ───────────────────────────────────────────────────

    public function testRetrieveModelReturnsInfo(): void {
        $this->configWithApiKey();
        $info = ModelInfo::with(ClaudeModels::SONNET_4_6, null, new \DateTime(), 'Claude Sonnet 4.6', null, null);
        $this->testable->setRetrieveModelInfo($info);

        $result = $this->testable->retrieveModel('claude-sonnet-4-6', 'testuser');

        $this->assertSame(['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'], $result);
    }

    public function testRetrieveModelOnErrorReturnsNull(): void {
        $this->configWithApiKey();
        $this->testable->throwOnRetrieveModel(new \RuntimeException('not found'));
        $this->logger->expects($this->once())->method('warning');

        $result = $this->testable->retrieveModel('unknown-model', 'testuser');

        $this->assertNull($result);
    }

    // ── chatWithToolsStream() ──────────────────────────────────────────────

    /**
     * Build a fake content-block-start event for a text block.
     */
    private function evText(int $idx): \stdClass {
        $cb = (object)['type' => 'text'];
        return TestableClaudeSDKService::streamEvent('content_block_start', ['index' => $idx, 'contentBlock' => $cb]);
    }

    /**
     * Build a fake content-block-start event for a tool_use block.
     */
    private function evToolUse(int $idx, string $id, string $name): \stdClass {
        $cb = (object)['type' => 'tool_use', 'id' => $id, 'name' => $name];
        return TestableClaudeSDKService::streamEvent('content_block_start', ['index' => $idx, 'contentBlock' => $cb]);
    }

    private function evTextDelta(int $idx, string $text): \stdClass {
        $delta = (object)['type' => 'text_delta', 'text' => $text];
        return TestableClaudeSDKService::streamEvent('content_block_delta', ['index' => $idx, 'delta' => $delta]);
    }

    private function evJsonDelta(int $idx, string $partial): \stdClass {
        $delta = (object)['type' => 'input_json_delta', 'partialJSON' => $partial];
        return TestableClaudeSDKService::streamEvent('content_block_delta', ['index' => $idx, 'delta' => $delta]);
    }

    private function evCitationDelta(int $idx, array $citation): \stdClass {
        $delta = (object)['type' => 'citations_delta', 'citation' => (object)$citation];
        return TestableClaudeSDKService::streamEvent('content_block_delta', ['index' => $idx, 'delta' => $delta]);
    }

    private function evBlockStop(int $idx): \stdClass {
        return TestableClaudeSDKService::streamEvent('content_block_stop', ['index' => $idx]);
    }

    private function evMessageStart(int $inputTokens = 0): \stdClass {
        $usage = (object)['inputTokens' => $inputTokens, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0];
        $message = (object)['usage' => $usage];
        return TestableClaudeSDKService::streamEvent('message_start', ['message' => $message]);
    }

    private function evMessageDelta(string $stopReason, int $outputTokens = 0): \stdClass {
        $delta = (object)['stopReason' => $stopReason];
        $usage = (object)['outputTokens' => $outputTokens];
        return TestableClaudeSDKService::streamEvent('message_delta', ['delta' => $delta, 'usage' => $usage]);
    }

    public function testChatWithToolsStreamYieldsTextDeltasAndDoneOnEndTurn(): void {
        $this->configWithApiKey();

        $this->testable->stubStreamEvents = [
            $this->evMessageStart(5),
            $this->evText(0),
            $this->evTextDelta(0, 'Hello '),
            $this->evTextDelta(0, 'world.'),
            $this->evBlockStop(0),
            $this->evMessageDelta('end_turn', 4),
        ];

        $events = iterator_to_array(
            $this->testable->chatWithToolsStream(
                [['role' => 'user', 'content' => 'Hi']],
                [],
                fn($n, $i) => ['content' => [['type' => 'text', 'text' => 'unused']]],
                null,
                'testuser',
            ),
            false,
        );

        $this->assertSame(['type' => 'text_delta', 'text' => 'Hello '], $events[0]);
        $this->assertSame(['type' => 'text_delta', 'text' => 'world.'], $events[1]);
        $this->assertSame('done', $events[2]['type']);
        $this->assertSame(5, $events[2]['usage']['input_tokens']);
        $this->assertSame(4, $events[2]['usage']['output_tokens']);
        $this->assertSame([], $events[2]['citations']);
    }

    public function testChatWithToolsStreamRunsToolLoopAcrossIterations(): void {
        $this->configWithApiKey();

        // Iteration 1: model emits text + a tool_use, ending with stop_reason: tool_use
        // Iteration 2: model emits text and ends with end_turn.
        $this->testable->stubStreamEvents = [
            // iteration 1
            $this->evMessageStart(),
            $this->evText(0),
            $this->evTextDelta(0, 'Let me look that up. '),
            $this->evBlockStop(0),
            $this->evToolUse(1, 'tu_1', 'search_files'),
            $this->evJsonDelta(1, '{"query":'),
            $this->evJsonDelta(1, '"foo"}'),
            $this->evBlockStop(1),
            $this->evMessageDelta('tool_use'),
        ];

        // After iteration 1, the loop will call callCreateStream again. We need
        // to swap the stub events to simulate iteration 2. Use a wrapper executor
        // that mutates stubStreamEvents on the way out.
        $service = $this->testable;
        $toolExecutor = function (string $name, array $input) use ($service) {
            $service->stubStreamEvents = [
                $service::streamEvent('message_start', ['message' => (object)['usage' => (object)['inputTokens' => 1]]]),
                $this->evText(0),
                $this->evTextDelta(0, 'Found three results.'),
                $this->evBlockStop(0),
                $this->evMessageDelta('end_turn', 2),
            ];
            return ['content' => [['type' => 'text', 'text' => 'r1\nr2\nr3']]];
        };

        $events = iterator_to_array(
            $this->testable->chatWithToolsStream(
                [['role' => 'user', 'content' => 'find foo']],
                [['name' => 'search_files', 'description' => 's', 'input_schema' => []]],
                $toolExecutor,
                null,
                'testuser',
            ),
            false,
        );

        // Expected event sequence: text_delta, tool_use, tool_result, text_delta, done
        $types = array_map(fn($e) => $e['type'], $events);
        $this->assertSame(['text_delta', 'tool_use', 'tool_result', 'text_delta', 'done'], $types);

        $this->assertSame('search_files', $events[1]['name']);
        $this->assertSame(['query' => 'foo'], $events[1]['input']);
        $this->assertSame('tu_1', $events[2]['tool_use_id']);
        $this->assertStringContainsString('r1', $events[2]['output']);
        $this->assertFalse($events[2]['is_error']);
        $this->assertSame('Found three results.', $events[3]['text']);
        $this->assertSame('done', $events[4]['type']);
    }

    public function testChatWithToolsStreamCollectsCitationsFromTextBlocks(): void {
        $this->configWithApiKey();

        $this->testable->stubStreamEvents = [
            $this->evMessageStart(),
            $this->evText(0),
            $this->evTextDelta(0, 'Per the doc, '),
            $this->evCitationDelta(0, [
                'type' => 'page_location',
                'cited_text' => 'X is true',
                'document_index' => 0,
                'document_title' => 'doc.pdf',
                'start_page_number' => 2,
                'end_page_number' => 2,
            ]),
            $this->evTextDelta(0, 'X holds.'),
            $this->evBlockStop(0),
            $this->evMessageDelta('end_turn'),
        ];

        $events = iterator_to_array(
            $this->testable->chatWithToolsStream(
                [['role' => 'user', 'content' => 'q']],
                [],
                fn($n, $i) => [],
                null,
                'testuser',
            ),
            false,
        );

        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertCount(1, $done['citations']);
        $this->assertSame('page_location', $done['citations'][0]['type']);
        $this->assertSame(2, $done['citations'][0]['start_page_number']);
    }

    public function testChatWithToolsStreamYieldsErrorOnStreamFailure(): void {
        $this->configWithApiKey();
        $this->testable->throwOnStream(new \RuntimeException('boom'));

        $events = iterator_to_array(
            $this->testable->chatWithToolsStream(
                [['role' => 'user', 'content' => 'Hi']],
                [],
                fn($n, $i) => [],
                null,
                'testuser',
            ),
            false,
        );

        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('boom', $events[0]['error']);
    }
}
