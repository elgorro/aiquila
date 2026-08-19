<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\HetznerModels;
use OCA\AIquila\Service\Provider\HetznerProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class HetznerProviderTest extends TestCase {
    private $clientService;
    private $client;
    private $config;
    private $credentials;
    private $logger;
    private HetznerProvider $provider;

    protected function setUp(): void {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = $this->createMock(IClient::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->credentials   = $this->createMock(CredentialService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->credentials->method('getApiKey')->willReturn('test-token');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->config->method('getUserValue')->willReturn('');

        $this->provider = new HetznerProvider(
            $this->clientService,
            $this->config,
            $this->credentials,
            $this->logger
        );
    }

    /** Provider with specific app config values (everything else = default). */
    private function provider(array $appValues): HetznerProvider {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default = '') => $appValues[$key] ?? $default
        );
        return new HetznerProvider($this->clientService, $config, $this->credentials, $this->logger);
    }

    private function jsonResponse(array $payload): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode($payload));
        return $response;
    }

    private function sseResponse(string $sse): IResponse {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($sse);
        return $response;
    }

    public function testIdAndCapabilities(): void {
        $this->assertSame('hetzner', $this->provider->getId());
        $this->assertSame('Hetzner Inference (EU)', $this->provider->getLabel());
        $this->assertFalse($this->provider->supportsNativeMcp());
    }

    public function testDefaultsToHetznerEndpointAndSendsBearerToken(): void {
        $capturedUrl = null;
        $capturedOpts = null;
        $this->client->method('post')->willReturnCallback(
            function (string $url, array $opts) use (&$capturedUrl, &$capturedOpts) {
                $capturedUrl = $url;
                $capturedOpts = $opts;
                return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
            }
        );

        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertSame('https://inference.hetzner.com/api/v1/chat/completions', $capturedUrl);
        $this->assertSame('Bearer test-token', $capturedOpts['headers']['Authorization']);
    }

    public function testBaseUrlOverrideIsUsedWithoutAppendingV1(): void {
        $provider = $this->provider(['hetzner_base_url' => 'https://inference.example.com/api/v2/']);
        $capturedUrl = null;
        $this->client->method('post')->willReturnCallback(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertSame('https://inference.example.com/api/v2/chat/completions', $capturedUrl);
    }

    public function testNormalizeBaseUrlRejectsNonHttpInput(): void {
        $this->assertSame('', HetznerProvider::normalizeBaseUrl(''));
        $this->assertSame('', HetznerProvider::normalizeBaseUrl('  '));
        $this->assertSame('', HetznerProvider::normalizeBaseUrl('file:///etc/passwd'));
        $this->assertSame('', HetznerProvider::normalizeBaseUrl('not a url'));
        $this->assertSame(
            'https://inference.hetzner.com/api/v1',
            HetznerProvider::normalizeBaseUrl(' https://inference.hetzner.com/api/v1/ ')
        );
    }

    public function testInvalidOverrideFallsBackToDefaultEndpoint(): void {
        $provider = $this->provider(['hetzner_base_url' => 'ftp://nope']);
        $capturedUrl = null;
        $this->client->method('post')->willReturnCallback(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertSame(HetznerProvider::DEFAULT_API_BASE . '/chat/completions', $capturedUrl);
    }

    public function testIsConfiguredRequiresToken(): void {
        $credentials = $this->createMock(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('');
        $provider = new HetznerProvider($this->clientService, $this->config, $credentials, $this->logger);

        $this->assertFalse($provider->isConfigured('u'));
        $this->assertTrue($this->provider->isConfigured('u'));
    }

    public function testModelDefaultsAndOverrides(): void {
        $this->assertSame(HetznerModels::DEFAULT_MODEL, $this->provider->getModel());

        $provider = $this->provider(['model_hetzner' => 'some/other-model']);
        $this->assertSame('some/other-model', $provider->getModel());
    }

    public function testMaxTokensIsClampedToModelCeiling(): void {
        $provider = $this->provider(['max_tokens_hetzner' => '999999']);
        $this->assertSame(
            HetznerModels::getMaxTokenCeiling(HetznerModels::DEFAULT_MODEL),
            $provider->getMaxTokens()
        );
    }

    public function testImageInputIsSentAsDataUri(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'a cat']]]]);
        });

        $this->provider->askWithImage('describe', 'AAAA', 'image/png', 'u');

        $parts = $captured['messages'][0]['content'];
        $this->assertSame('image_url', $parts[0]['type']);
        $this->assertSame('data:image/png;base64,AAAA', $parts[0]['image_url']['url']);
        $this->assertSame('describe', $parts[1]['text']);
    }

    public function testChatReturnsResponseAndMapsUsage(): void {
        $this->client->method('post')->willReturn($this->jsonResponse([
            'choices' => [['message' => ['content' => 'Hello!'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5],
        ]));

        $result = $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertSame('Hello!', $result['response']);
        $this->assertSame(12, $result['usage']['input_tokens']);
        $this->assertSame(5, $result['usage']['output_tokens']);
    }

    public function testChatWithToolsRoundTrip(): void {
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
        $result = $this->provider->chatWithTools([['role' => 'user', 'content' => 'list']], $tools, $executor, null, 'u');

        $this->assertSame(['list_files', ['path' => '/']], $executorArgs);
        $this->assertSame('Found 2 files.', $result['response']);
        $this->assertSame(7, $result['usage']['input_tokens']);
        $this->assertSame(8, $result['usage']['output_tokens']);
    }

    public function testToolChoiceIsSent(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]]);
        });

        $tools = [['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object', 'properties' => []]]];
        $this->provider->chatWithTools([['role' => 'user', 'content' => 'q']], $tools, fn() => [], null, 'u');

        $this->assertSame('function', $captured['tools'][0]['type']);
        $this->assertSame('auto', $captured['tool_choice']);
    }

    public function testStreamingYieldsTextDeltasAndDone(): void {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":1}}\n"
            . "data: [DONE]\n";
        $this->client->method('post')->willReturn($this->sseResponse($sse));

        $events = iterator_to_array($this->provider->chatWithToolsStream([['role' => 'user', 'content' => 'hi']], [], fn() => [], null, 'u'));

        $deltas = array_values(array_filter($events, fn($e) => $e['type'] === 'text_delta'));
        $this->assertSame('Hel', $deltas[0]['text']);
        $this->assertSame('lo', $deltas[1]['text']);
        $this->assertSame('done', end($events)['type']);
    }

    public function testNativeMcpYieldsError(): void {
        $events = iterator_to_array($this->provider->chatWithNativeMcp(
            [['role' => 'user', 'content' => 'hi']],
            [['type' => 'connector', 'connector_id' => 'c1']],
            null,
            'u',
        ));
        $this->assertSame('error', $events[0]['type']);
    }

    public function testListModelsUsesLiveEndpoint(): void {
        $capturedUrl = null;
        $this->client->method('get')->willReturnCallback(function (string $url) use (&$capturedUrl) {
            $capturedUrl = $url;
            return $this->jsonResponse([
                'data' => [['id' => 'Qwen/Qwen3.6-35B-A3B-FP8'], ['id' => 'Qwen3.8-27B']],
            ]);
        });

        $models = $this->provider->listModels('u');

        $this->assertSame('https://inference.hetzner.com/api/v1/models', $capturedUrl);
        $this->assertContains('Qwen/Qwen3.6-35B-A3B-FP8', $models);
        $this->assertContains('Qwen3.8-27B', $models);
    }

    public function testStaticRegistryCoversTheFullLineUp(): void {
        // The fallback is what the settings UI shows when the live listing
        // fails, so a registry that has gone stale silently narrows the picker.
        // Hetzner withdrew the large models (Kimi-K2.7-Code, DeepSeek-V4-Flash,
        // GLM-5.2) from Experiments on 2026-08-19; the two small Qwen models
        // are all that is left.
        $this->assertSame([
            HetznerModels::QWEN3_6_35B,
            HetznerModels::QWEN3_8_27B,
        ], HetznerModels::getAllModels());
    }

    public function testEveryKnownModelHasItsOwnCeiling(): void {
        foreach (HetznerModels::getAllModels() as $model) {
            $this->assertGreaterThan(
                HetznerModels::DEFAULT_MAX_TOKENS,
                HetznerModels::getMaxTokenCeiling($model),
                $model . ' is clamped to the generic default'
            );
        }
        // An id the registry has not caught up with still gets a safe value.
        $this->assertSame(HetznerModels::DEFAULT_MAX_TOKENS, HetznerModels::getMaxTokenCeiling('brand/new'));
    }

    public function testMaxTokensUsesTheSelectedModelsCeiling(): void {
        $provider = $this->provider([
            'model_hetzner' => HetznerModels::QWEN3_6_35B,
            'max_tokens_hetzner' => '999999',
        ]);
        $this->assertSame(
            HetznerModels::getMaxTokenCeiling(HetznerModels::QWEN3_6_35B),
            $provider->getMaxTokens()
        );
    }

    public function testVisionSupportFollowsTheSelectedModel(): void {
        $this->assertTrue(HetznerModels::supportsVision(HetznerModels::QWEN3_6_35B));
        $this->assertTrue(HetznerModels::supportsVision(HetznerModels::QWEN3_8_27B));
        // No model currently served by Experiments is text-only, and an id the
        // registry has not caught up with is assumed capable rather than
        // crippled by a stale list.
        $this->assertTrue(HetznerModels::supportsVision('brand/new'));
    }

    public function testRateLimitErrorIsMapped(): void {
        $this->client->method('post')->willThrowException(new \RuntimeException('HTTP 429 Too Many Requests'));

        $result = $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertStringContainsString('rate limit', strtolower($result['error']));
    }

    public function testInvalidTokenErrorIsMapped(): void {
        $this->client->method('post')->willThrowException(new \RuntimeException('HTTP 401 Unauthorized'));

        $result = $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');

        $this->assertStringContainsString('experiments.hetzner.com', $result['error']);
    }
}
