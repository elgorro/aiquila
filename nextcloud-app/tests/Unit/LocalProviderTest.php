<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LocalProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class LocalProviderTest extends TestCase {
    private $clientService;
    private $client;
    private $config;
    private $credentials;
    private $logger;

    protected function setUp(): void {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = $this->createMock(IClient::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->credentials   = $this->createMock(CredentialService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->config->method('getUserValue')->willReturn('');
    }

    /**
     * @param array<string, string> $appValues
     */
    private function provider(array $appValues = [], string $apiKey = ''): LocalProvider {
        $appValues += ['local_base_url' => 'http://localhost:11434'];
        $this->config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $appValues[$key] ?? $default
        );
        $this->credentials->method('getApiKey')->willReturn($apiKey);

        return new LocalProvider($this->clientService, $this->config, $this->credentials, $this->logger);
    }

    private function jsonResponse(array $payload): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode($payload));
        return $response;
    }

    // ── Base URL normalization ──────────────────────────────────────────

    public function testNormalizeBaseUrlAppendsVersionSegment(): void {
        $this->assertSame('http://localhost:11434/v1', LocalProvider::normalizeBaseUrl('http://localhost:11434'));
        $this->assertSame('http://localhost:11434/v1', LocalProvider::normalizeBaseUrl('http://localhost:11434/'));
        $this->assertSame('http://localhost:11434/v1', LocalProvider::normalizeBaseUrl('  http://localhost:11434/v1/  '));
        $this->assertSame('https://ai.example.com/v1', LocalProvider::normalizeBaseUrl('https://ai.example.com'));
    }

    public function testNormalizeBaseUrlRejectsNonHttp(): void {
        $this->assertSame('', LocalProvider::normalizeBaseUrl(''));
        $this->assertSame('', LocalProvider::normalizeBaseUrl('localhost:11434'));
        $this->assertSame('', LocalProvider::normalizeBaseUrl('file:///etc/passwd'));
        $this->assertSame('', LocalProvider::normalizeBaseUrl('ftp://example.com'));
    }

    public function testIdAndCapabilities(): void {
        $provider = $this->provider();
        $this->assertSame('local', $provider->getId());
        $this->assertSame('Local model', $provider->getLabel());
        $this->assertFalse($provider->supportsNativeMcp());
    }

    // ── Configuration ───────────────────────────────────────────────────

    public function testConfiguredDependsOnBaseUrlNotApiKey(): void {
        $this->assertTrue($this->provider([], '')->isConfigured());
    }

    public function testUnconfiguredWithoutBaseUrl(): void {
        $this->assertFalse($this->provider(['local_base_url' => ''], 'token')->isConfigured());
    }

    public function testTimeoutDefaultsWellAboveTheHostedProviders(): void {
        // The shared api_timeout of 30s would abort most local inference.
        $provider = $this->provider();
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame(LocalProvider::DEFAULT_TIMEOUT, $captured['timeout']);
    }

    // ── HTTP shape ──────────────────────────────────────────────────────

    public function testNoAuthorizationHeaderWithoutApiKey(): void {
        $provider = $this->provider([], '');
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertArrayNotHasKey('Authorization', $captured['headers']);
    }

    public function testBearerHeaderSentWhenApiKeyStored(): void {
        $provider = $this->provider([], 'lm-studio-token');
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Bearer lm-studio-token', $captured['headers']['Authorization']);
    }

    public function testRequestsTargetTheConfiguredEndpoint(): void {
        $provider = $this->provider(['local_base_url' => 'http://ollama:11434']);
        $capturedUrl = null;
        $this->client->method('post')->willReturnCallback(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('http://ollama:11434/v1/chat/completions', $capturedUrl);
    }

    public function testLocalAddressAllowanceIsOptedInByDefault(): void {
        // Nextcloud blocks loopback/private targets otherwise, which is every
        // realistic local-model deployment.
        $provider = $this->provider();
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($captured['nextcloud']['allow_local_address']);
    }

    public function testLocalAddressAllowanceCanBeDisabled(): void {
        $provider = $this->provider(['local_allow_local_address' => 'no']);
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertArrayNotHasKey('nextcloud', $captured);
    }

    // ── Wire format ─────────────────────────────────────────────────────

    public function testToolChoiceIsNotSent(): void {
        // Ollama rejects the field; the others tolerate its absence.
        $provider = $this->provider();
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
        });

        $tools = [['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object']]];
        $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $tools, fn() => []);

        $this->assertSame('search', $captured['tools'][0]['function']['name']);
        $this->assertArrayNotHasKey('tool_choice', $captured);
    }

    public function testImagesRejectedUnlessVisionEnabled(): void {
        $result = $this->provider()->askWithImage('describe', 'AAAA', 'image/png');
        $this->assertArrayHasKey('error', $result);
    }

    public function testImagesSentAsDataUriPartsWhenVisionEnabled(): void {
        $provider = $this->provider(['local_vision' => 'yes']);
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'a cat']]]]);
        });

        $provider->askWithImage('describe', 'AAAA', 'image/png');

        $parts = $captured['messages'][0]['content'];
        $this->assertSame('image_url', $parts[0]['type']);
        $this->assertSame('data:image/png;base64,AAAA', $parts[0]['image_url']['url']);
        $this->assertSame('describe', $parts[1]['text']);
    }

    public function testChatWithToolsRoundTrip(): void {
        $provider = $this->provider();
        $responses = [
            $this->jsonResponse([
                'choices' => [[
                    'message' => ['content' => '', 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'list_files', 'arguments' => '{"path":"/"}'],
                    ]]],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
            ]),
            $this->jsonResponse([
                'choices' => [['message' => ['content' => 'Found 2 files.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6],
            ]),
        ];
        $this->client->method('post')->willReturnCallback(function () use (&$responses) {
            return array_shift($responses);
        });

        $executorArgs = null;
        $executor = function (string $name, array $input) use (&$executorArgs): array {
            $executorArgs = [$name, $input];
            return ['content' => [['type' => 'text', 'text' => 'a.txt']]];
        };

        $tools = [['name' => 'list_files', 'description' => 'List files', 'input_schema' => ['type' => 'object']]];
        $result = $provider->chatWithTools([['role' => 'user', 'content' => 'list']], $tools, $executor);

        $this->assertSame(['list_files', ['path' => '/']], $executorArgs);
        $this->assertSame('Found 2 files.', $result['response']);
        $this->assertSame(7, $result['usage']['input_tokens']);
    }

    public function testStreamingYieldsTextDeltasAndDone(): void {
        $provider = $this->provider();
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":1}}\n"
            . "data: [DONE]\n";
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($sse);
        $this->client->method('post')->willReturn($response);

        $events = iterator_to_array($provider->chatWithToolsStream([['role' => 'user', 'content' => 'hi']], [], fn() => []));

        $deltas = array_values(array_filter($events, fn($e) => $e['type'] === 'text_delta'));
        $this->assertSame('Hel', $deltas[0]['text']);
        $this->assertSame('lo', $deltas[1]['text']);
        $this->assertSame('done', end($events)['type']);
    }

    public function testStreamingWithoutEndpointYieldsError(): void {
        $provider = $this->provider(['local_base_url' => '']);

        $events = iterator_to_array($provider->chatWithToolsStream([['role' => 'user', 'content' => 'hi']], [], fn() => []));

        $this->assertSame('error', $events[0]['type']);
        $this->assertStringContainsString('endpoint', $events[0]['error']);
    }

    public function testChatWithoutEndpointReturnsActionableError(): void {
        $provider = $this->provider(['local_base_url' => '']);
        $this->client->expects($this->never())->method('post');

        $result = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertStringContainsString('endpoint', $result['error']);
    }

    public function testListModelsWithoutAnApiKey(): void {
        $provider = $this->provider([], '');
        $this->client->method('get')->willReturn($this->jsonResponse([
            'data' => [['id' => 'qwen2.5:7b'], ['id' => 'llama3.2']],
        ]));

        $models = $provider->listModels();

        $this->assertSame(['llama3.2', 'qwen2.5:7b'], $models);
    }

    public function testNativeMcpYieldsError(): void {
        $provider = $this->provider();
        $events = iterator_to_array($provider->chatWithNativeMcp([['role' => 'user', 'content' => 'hi']], []));
        $this->assertSame('error', $events[0]['type']);

        $this->assertArrayHasKey('error', $provider->chatWithNativeMcpCollect([['role' => 'user', 'content' => 'hi']], []));
    }
}
