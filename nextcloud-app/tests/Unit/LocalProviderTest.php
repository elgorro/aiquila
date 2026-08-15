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
     * @param array<string, string> $secrets named CredentialService secrets
     */
    private function provider(array $appValues = [], string $apiKey = '', array $secrets = []): LocalProvider {
        $appValues += ['local_base_url' => 'http://localhost:11434'];
        $this->config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $appValues[$key] ?? $default
        );
        $this->credentials->method('getApiKey')->willReturn($apiKey);
        $this->credentials->method('getSecret')->willReturnCallback(
            fn(string $name) => $secrets[$name] ?? ''
        );

        return new LocalProvider($this->clientService, $this->config, $this->credentials, $this->logger);
    }

    /** The IClientService options a plain chat() call ends up sending. */
    private function postOptions(LocalProvider $provider): array {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = $opts;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        return $captured;
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

    // ── Auth modes (#446) ───────────────────────────────────────────────

    public function testBearerRemainsTheDefaultMode(): void {
        // Installs predating the auth-mode setting must keep behaving exactly
        // as they did.
        $options = $this->postOptions($this->provider([], 'token'));

        $this->assertSame('Bearer token', $options['headers']['Authorization']);
    }

    public function testAuthModeNoneSendsNoCredential(): void {
        $options = $this->postOptions($this->provider(['local_auth_mode' => 'none'], 'token'));

        $this->assertArrayNotHasKey('Authorization', $options['headers']);
    }

    public function testAuthModeBasicSendsTheKeyAsThePassword(): void {
        $options = $this->postOptions($this->provider([
            'local_auth_mode' => 'basic',
            'local_auth_user' => 'nextcloud',
        ], 's3cret'));

        $this->assertSame('Basic ' . base64_encode('nextcloud:s3cret'), $options['headers']['Authorization']);
    }

    public function testAuthModeHeaderSendsTheNamedHeaderAndNoAuthorization(): void {
        $options = $this->postOptions($this->provider([
            'local_auth_mode' => 'header',
            'local_auth_header' => 'X-API-Key',
        ], 'gateway-key'));

        $this->assertSame('gateway-key', $options['headers']['X-API-Key']);
        $this->assertArrayNotHasKey('Authorization', $options['headers']);
    }

    public function testAuthModeHeaderWithoutAHeaderNameSendsNothing(): void {
        // Better an obvious 401 than the key landing in a header nobody named.
        $options = $this->postOptions($this->provider(['local_auth_mode' => 'header'], 'gateway-key'));

        $this->assertArrayNotHasKey('Authorization', $options['headers']);
        $this->assertSame(['Content-Type' => 'application/json'], $options['headers']);
    }

    public function testUnknownAuthModeFallsBackToBearer(): void {
        $options = $this->postOptions($this->provider(['local_auth_mode' => 'kerberos'], 'token'));

        $this->assertSame('Bearer token', $options['headers']['Authorization']);
    }

    public function testEmptyKeySendsNoCredentialInAnyMode(): void {
        $options = $this->postOptions($this->provider([
            'local_auth_mode' => 'basic',
            'local_auth_user' => 'nextcloud',
        ], ''));

        $this->assertArrayNotHasKey('Authorization', $options['headers']);
    }

    // ── Extra headers ───────────────────────────────────────────────────

    public function testExtraHeadersAreMerged(): void {
        $options = $this->postOptions($this->provider([], 'token', [
            'local_extra_headers' => "# Cloudflare Access\nCF-Access-Client-Id: abc\nCF-Access-Client-Secret: def\n",
        ]));

        $this->assertSame('abc', $options['headers']['CF-Access-Client-Id']);
        $this->assertSame('def', $options['headers']['CF-Access-Client-Secret']);
        $this->assertSame('Bearer token', $options['headers']['Authorization']);
    }

    public function testExtraHeadersCannotOverrideTheProvidersOwn(): void {
        // HeaderSpec refuses these at save time; a value that got stored anyway
        // must still not win over the configured auth scheme.
        $options = $this->postOptions($this->provider([], 'token', [
            'local_extra_headers' => "Authorization: Bearer stolen\nContent-Type: text/plain",
        ]));

        $this->assertSame('Bearer token', $options['headers']['Authorization']);
        $this->assertSame('application/json', $options['headers']['Content-Type']);
    }

    public function testUnparsableStoredExtraHeadersAreDroppedNotSpliced(): void {
        $options = $this->postOptions($this->provider([], 'token', [
            'local_extra_headers' => 'this is not a header',
        ]));

        $this->assertSame(
            ['Authorization' => 'Bearer token', 'Content-Type' => 'application/json'],
            $options['headers'],
        );
    }

    // ── TLS ─────────────────────────────────────────────────────────────

    public function testNoTlsOptionsByDefault(): void {
        $options = $this->postOptions($this->provider());

        $this->assertArrayNotHasKey('verify', $options);
        $this->assertArrayNotHasKey('cert', $options);
        $this->assertArrayNotHasKey('ssl_key', $options);
    }

    public function testVerificationCanBeTurnedOff(): void {
        $options = $this->postOptions($this->provider(['local_tls_verify' => 'no']));

        $this->assertFalse($options['verify']);
    }

    public function testCaBundleAndClientCertificateArePassedThrough(): void {
        $ca = $this->tempFile('ca');
        $cert = $this->tempFile('cert');
        $key = $this->tempFile('key');

        $options = $this->postOptions($this->provider([
            'local_ca_bundle' => $ca,
            'local_client_cert' => $cert,
            'local_client_key' => $key,
        ]));

        $this->assertSame($ca, $options['verify']);
        $this->assertSame($cert, $options['cert']);
        $this->assertSame($key, $options['ssl_key']);
    }

    public function testClientKeyPassphraseIsPairedWithThePaths(): void {
        $cert = $this->tempFile('cert');
        $key = $this->tempFile('key');

        $options = $this->postOptions($this->provider([
            'local_client_cert' => $cert,
            'local_client_key' => $key,
        ], '', ['local_client_key_password' => 'pw']));

        $this->assertSame([$cert, 'pw'], $options['cert']);
        $this->assertSame([$key, 'pw'], $options['ssl_key']);
    }

    public function testCaBundleIsIgnoredWhenVerificationIsOff(): void {
        $options = $this->postOptions($this->provider([
            'local_tls_verify' => 'no',
            'local_ca_bundle' => $this->tempFile('ca'),
        ]));

        $this->assertFalse($options['verify']);
    }

    public function testAMissingCertificateFileFailsWithAReadableMessage(): void {
        // chat() renders every failure through errorMessage(), so the check is
        // that the admin is told which file and which setting — not a cURL 58.
        $provider = $this->provider(['local_client_cert' => '/nope/missing.pem']);

        $result = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertStringContainsString('/nope/missing.pem', $result['error']);
        $this->assertStringContainsString('client certificate', $result['error']);
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function tempFile(string $prefix): string {
        $path = tempnam(sys_get_temp_dir(), 'aiquila-' . $prefix);
        $this->tempFiles[] = $path;
        return $path;
    }

    protected function tearDown(): void {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
