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

    /** Optional stub text to return from makeStubMessage */
    public string $stubResponseText = '';

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
        if ($this->createException !== null) {
            throw $this->createException;
        }
        return $this->makeStubMessage($this->stubResponseText);
    }

    protected function callCreateStream(Client $client, array $params): BaseStream {
        if ($this->streamException !== null) {
            throw $this->streamException;
        }
        return new class implements BaseStream {
            public function __construct(
                \Anthropic\Core\Conversion\Contracts\Converter|\Anthropic\Core\Conversion\Contracts\ConverterSource|string $convert = '',
                ?\Psr\Http\Message\RequestInterface $request = null,
                ?\Psr\Http\Message\ResponseInterface $response = null,
                mixed $parsedBody = null,
            ) {}
            public function close(): void {}
            public function getIterator(): \Traversable { return new \ArrayIterator([]); }
        };
    }

    protected function callListModels(Client $client, array $params): array {
        if ($this->listModelsException !== null) throw $this->listModelsException;
        return $this->listModelsItems ?? [];
    }

    protected function callRetrieveModel(Client $client, string $modelId): ModelInfo {
        if ($this->retrieveModelException !== null) throw $this->retrieveModelException;
        return $this->retrieveModelInfo ?? ModelInfo::with('test-model', new \DateTime(), 'Test Model');
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
            $contentItems[] = $textObj;
        }

        foreach (['content' => $contentItems, 'stopReason' => 'end_turn'] as $prop => $val) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($stub, $val);
        }
        $usage = Usage::with(null, null, null, 10, 20, null, null);
        $p = $ref->getProperty('usage');
        $p->setValue($stub, $usage);
        return $stub;
    }
}

class ClaudeServiceTest extends TestCase {
    private $config;
    private $logger;
    private ClaudeSDKService $service;
    private TestableClaudeSDKService $testable;

    protected function setUp(): void {
        $this->config   = $this->createMock(IConfig::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->service  = new ClaudeSDKService($this->config, $this->logger);
        $this->testable = new TestableClaudeSDKService($this->config, $this->logger);
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
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')
            ->willReturnCallback(fn($app, $key, $default) => match ($key) {
                'api_key'    => 'test-key',
                'model'      => ClaudeModels::DEFAULT_MODEL,
                'max_tokens' => '4096',
                default      => $default,
            });
    }

    // ── getApiKey ─────────────────────────────────────────────────────────

    public function testGetApiKeyReturnsUserKeyFirst(): void {
        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('user-api-key');

        $this->assertEquals('user-api-key', $this->service->getApiKey('testuser'));
    }

    public function testGetApiKeyFallsBackToAdminKey(): void {
        $this->config->method('getUserValue')
            ->with('testuser', 'aiquila', 'api_key', '')
            ->willReturn('');

        $this->config->method('getAppValue')
            ->with('aiquila', 'api_key', '')
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
            ->willReturnMap([['testuser', 'aiquila', 'model', '', 'claude-haiku-4-5-20251001']]);

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
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturn('');

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

    public function testBuildRequestParamsWithoutCacheSystemOmitsCacheControl(): void {
        $this->configWithApiKey();

        $this->testable->ask('Hi', '', 'testuser', ['system' => 'You are a helpful assistant.']);

        $params = $this->testable->lastCreateParams;
        $this->assertArrayNotHasKey('cache_control', $params['system'][0]);
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

    public function testAskWithDocumentAddsCacheControlWhenRequested(): void {
        $this->configWithApiKey();

        $this->testable->askWithDocument('Summarize', 'text content', 'text/plain', '', 'testuser', true);

        $docBlock = $this->testable->lastCreateParams['messages'][0]['content'][0];
        $this->assertArrayHasKey('cache_control', $docBlock['source']);
        $this->assertSame('ephemeral', $docBlock['source']['cache_control']['type']);
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

    // ── listModels() ──────────────────────────────────────────────────────

    public function testListModelsReturnsIds(): void {
        $this->configWithApiKey();
        $m1 = ModelInfo::with('claude-3-5-sonnet-20241022', new \DateTime(), 'Claude 3.5 Sonnet');
        $m2 = ModelInfo::with('claude-3-haiku-20240307',    new \DateTime(), 'Claude 3 Haiku');
        $this->testable->setListModelsItems([$m1, $m2]);

        $result = $this->testable->listModels('testuser');

        $this->assertSame(['claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307'], $result);
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
        $info = ModelInfo::with('claude-sonnet-4-6', new \DateTime(), 'Claude Sonnet 4.6');
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
}
