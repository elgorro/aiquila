<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\DeepSeekModels;
use OCA\AIquila\Service\Provider\DeepSeekProvider;
use OCA\AIquila\Service\Provider\ProviderProbe;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class DeepSeekProviderTest extends TestCase {
    private $clientService;
    private $client;
    private $config;
    private $credentials;
    private $logger;
    private DeepSeekProvider $provider;

    protected function setUp(): void {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = $this->createMock(IClient::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->credentials   = $this->createMock(CredentialService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->credentials->method('getApiKey')->willReturn('test-key');
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->config->method('getUserValue')->willReturn('');

        $this->provider = new DeepSeekProvider(
            $this->clientService,
            $this->config,
            $this->credentials,
            $this->logger
        );
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
        $this->assertSame('deepseek', $this->provider->getId());
        $this->assertSame('DeepSeek', $this->provider->getLabel());
        $this->assertFalse($this->provider->supportsNativeMcp());
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

    public function testImageInputIsRejected(): void {
        $result = $this->provider->askWithImage('describe', 'AAAA', 'image/png', 'u');
        $this->assertArrayHasKey('error', $result);
    }

    public function testChatTranslatesSystemAndDropsImageBlocks(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'AAAA']],
                ['type' => 'text', 'text' => 'describe'],
            ],
        ]];
        $this->provider->chat($messages, 'You are helpful', 'u');

        $this->assertSame('system', $captured['messages'][0]['role']);
        $this->assertSame('You are helpful', $captured['messages'][0]['content']);
        // Image dropped; only the text survives flattened to a string.
        $this->assertSame('describe', $captured['messages'][1]['content']);
    }

    public function testReasonerModelOmitsSamplingParams(): void {
        $captured = null;
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('getAppValue')->willReturnCallback(
            fn($app, $key, $default) => $key === 'model_deepseek' ? 'deepseek-reasoner' : $default
        );
        $provider = new DeepSeekProvider($this->clientService, $this->config, $this->credentials, $this->logger);
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
        });

        $provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u', ['temperature' => 0.5, 'top_p' => 0.9]);

        $this->assertSame('deepseek-reasoner', $captured['model']);
        $this->assertArrayNotHasKey('temperature', $captured);
        $this->assertArrayNotHasKey('top_p', $captured);
    }

    public function testChatModelForwardsSamplingParams(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
        });

        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u', ['temperature' => 0.5]);

        $this->assertSame(0.5, $captured['temperature']);
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

    public function testToolsAreTranslatedToFunctionShape(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]]);
        });

        $tools = [['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object', 'properties' => []]]];
        $this->provider->chatWithTools([['role' => 'user', 'content' => 'q']], $tools, fn() => [], null, 'u');

        $this->assertSame('function', $captured['tools'][0]['type']);
        $this->assertSame('search', $captured['tools'][0]['function']['name']);
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

    public function testStreamingDropsReasoningContent(): void {
        $sse = "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking...\"}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Answer\"},\"finish_reason\":\"stop\"}]}\n"
            . "data: [DONE]\n";
        $this->client->method('post')->willReturn($this->sseResponse($sse));

        $events = iterator_to_array($this->provider->chatWithToolsStream([['role' => 'user', 'content' => 'hi']], [], fn() => [], null, 'u'));

        $textDeltas = array_values(array_filter($events, fn($e) => $e['type'] === 'text_delta'));
        $this->assertCount(1, $textDeltas);
        $this->assertSame('Answer', $textDeltas[0]['text']);
    }

    public function testNativeMcpYieldsError(): void {
        $events = iterator_to_array($this->provider->chatWithNativeMcp(
            [['role' => 'user', 'content' => 'hi']],
            [['type' => 'connector', 'connector_id' => 'c1']],
            null,
            'u',
        ));
        $this->assertSame('error', $events[0]['type']);

        $collected = $this->provider->chatWithNativeMcpCollect([['role' => 'user', 'content' => 'hi']], [], null, 'u');
        $this->assertArrayHasKey('error', $collected);
    }

    public function testListModels(): void {
        $this->client->method('get')->willReturn($this->jsonResponse([
            'data' => [['id' => 'deepseek-chat'], ['id' => 'deepseek-reasoner']],
        ]));

        $models = $this->provider->listModels('u');
        $this->assertContains('deepseek-chat', $models);
        $this->assertContains('deepseek-reasoner', $models);
    }

    /**
     * The probe covers every OpenAI-compatible provider through the shared
     * implementation in AbstractOpenAiCompatibleProvider.
     */
    public function testProbeIsGreenWhenTheConfiguredModelIsOffered(): void {
        $this->client->method('get')->willReturn($this->jsonResponse([
            'data' => [['id' => DeepSeekModels::DEFAULT_MODEL]],
        ]));

        $result = $this->provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_OK, $result['state']);
        $this->assertSame(ProviderProbe::REASON_OK, $result['reason']);
    }

    public function testProbeIsOrangeWhenTheConfiguredModelIsGone(): void {
        $this->client->method('get')->willReturn($this->jsonResponse([
            'data' => [['id' => 'some-other-model']],
        ]));

        $result = $this->provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_DEGRADED, $result['state']);
        $this->assertSame(ProviderProbe::REASON_MODEL_MISSING, $result['reason']);
        $this->assertStringContainsString(DeepSeekModels::DEFAULT_MODEL, $result['message']);
    }

    public function testProbeIsRedWhenTheKeyIsRejected(): void {
        $this->client->method('get')->willThrowException(new \RuntimeException('HTTP 401 Unauthorized'));

        $result = $this->provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_ERROR, $result['state']);
        $this->assertSame(ProviderProbe::REASON_UNAUTHORIZED, $result['reason']);
    }

    /** Rate limiting is orange: the key is fine, the request just has to wait. */
    public function testProbeIsOrangeWhenRateLimited(): void {
        $this->client->method('get')->willThrowException(new \RuntimeException('HTTP 429 Too Many Requests'));

        $result = $this->provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_DEGRADED, $result['state']);
        $this->assertSame(ProviderProbe::REASON_RATE_LIMITED, $result['reason']);
    }

    public function testProbeIsRedWhenTheEndpointIsUnreachable(): void {
        $this->client->method('get')->willThrowException(new \RuntimeException('cURL error 7: connection refused'));

        $result = $this->provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_ERROR, $result['state']);
        $this->assertSame(ProviderProbe::REASON_UNREACHABLE, $result['reason']);
    }

    public function testProbeIsGreyWithoutAKey(): void {
        $credentials = $this->createMock(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('');
        $provider = new DeepSeekProvider($this->clientService, $this->config, $credentials, $this->logger);

        $result = $provider->probe('u');

        $this->assertSame(ProviderProbe::STATE_UNCONFIGURED, $result['state']);
        $this->assertSame(ProviderProbe::REASON_NOT_CONFIGURED, $result['reason']);
    }
}
