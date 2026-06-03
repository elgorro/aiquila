<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\MistralProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class MistralProviderTest extends TestCase {
    private $clientService;
    private $client;
    private $config;
    private $credentials;
    private $logger;
    private MistralProvider $provider;

    protected function setUp(): void {
        $this->clientService = $this->createMock(IClientService::class);
        $this->client        = $this->createMock(IClient::class);
        $this->config        = $this->createMock(IConfig::class);
        $this->credentials   = $this->createMock(CredentialService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->clientService->method('newClient')->willReturn($this->client);
        $this->credentials->method('getApiKey')->willReturn('test-key');
        // Config defaults: getAppValue($app, $key, $default) -> $default; getUserValue -> ''.
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->config->method('getUserValue')->willReturn('');

        $this->provider = new MistralProvider(
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
        $this->assertSame('mistral', $this->provider->getId());
        $this->assertSame('Mistral', $this->provider->getLabel());
        $this->assertTrue($this->provider->supportsNativeMcp());
    }

    public function testIsConfiguredReflectsApiKey(): void {
        $this->assertTrue($this->provider->isConfigured('u'));
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

    public function testChatTranslatesSystemAndImageBlocks(): void {
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
        $userParts = $captured['messages'][1]['content'];
        $this->assertSame('image_url', $userParts[0]['type']);
        $this->assertSame('data:image/png;base64,AAAA', $userParts[0]['image_url']);
        $this->assertSame('text', $userParts[1]['type']);
    }

    public function testChatWithToolsRoundTrip(): void {
        $responses = [
            // First call: model requests a tool.
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
            // Second call: model produces the final answer.
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
            return ['content' => [['type' => 'text', 'text' => 'a.txt\nb.txt']]];
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
        $this->assertSame(['type' => 'object', 'properties' => []], $captured['tools'][0]['function']['parameters']);
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
        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertSame(2, $done['usage']['input_tokens']);
    }

    public function testStreamingToolCallRoundTrip(): void {
        $first = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_9\",\"function\":{\"name\":\"list_files\",\"arguments\":\"{\\\"path\\\":\"}}]}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"\\\"/\\\"}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n"
            . "data: [DONE]\n";
        $second = "data: {\"choices\":[{\"delta\":{\"content\":\"Done.\"},\"finish_reason\":\"stop\"}]}\n"
            . "data: [DONE]\n";
        $bodies = [$first, $second];
        $this->client->method('post')->willReturnCallback(function () use (&$bodies) {
            return $this->sseResponse(array_shift($bodies));
        });

        $executorArgs = null;
        $executor = function (string $name, array $input) use (&$executorArgs): array {
            $executorArgs = [$name, $input];
            return ['content' => [['type' => 'text', 'text' => 'result']]];
        };

        $events = iterator_to_array($this->provider->chatWithToolsStream([['role' => 'user', 'content' => 'go']], [], $executor, null, 'u'));

        $this->assertSame(['list_files', ['path' => '/']], $executorArgs);
        $types = array_column($events, 'type');
        $this->assertContains('tool_use', $types);
        $this->assertContains('tool_result', $types);
        $this->assertContains('text_delta', $types);
        $this->assertSame('done', end($events)['type']);
    }

    public function testListModels(): void {
        $this->client->method('get')->willReturn($this->jsonResponse([
            'data' => [['id' => 'mistral-small-latest'], ['id' => 'mistral-large-latest']],
        ]));

        $models = $this->provider->listModels('u');
        $this->assertContains('mistral-large-latest', $models);
        $this->assertContains('mistral-small-latest', $models);
    }

    public function testNativeMcpWithoutConnectorsYieldsError(): void {
        $events = iterator_to_array($this->provider->chatWithNativeMcp(
            [['role' => 'user', 'content' => 'hi']],
            [],
            null,
            'u',
        ));
        $this->assertSame('error', $events[0]['type']);
    }

    public function testNativeMcpBuildsConversationRequest(): void {
        $captured = null;
        $url = null;
        $this->client->method('post')->willReturnCallback(function (string $u, array $opts) use (&$captured, &$url) {
            $url = $u;
            $captured = json_decode($opts['body'], true);
            return $this->sseResponse(
                "data: {\"type\":\"message.output.delta\",\"content\":\"ok\"}\n"
                . "data: {\"type\":\"conversation.response.done\",\"usage\":{\"prompt_tokens\":3,\"completion_tokens\":2}}\n"
                . "data: [DONE]\n"
            );
        });

        $events = iterator_to_array($this->provider->chatWithNativeMcp(
            [['role' => 'user', 'content' => 'hello']],
            [['type' => 'connector', 'connector_id' => 'my_conn']],
            'You are helpful.',
            'u',
        ));

        $this->assertStringContainsString('/conversations', $url);
        $this->assertSame('You are helpful.', $captured['instructions']);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $captured['inputs']);
        $this->assertSame([['type' => 'connector', 'connector_id' => 'my_conn']], $captured['tools']);
        $this->assertArrayHasKey('model', $captured);
        $this->assertTrue($captured['stream']);

        $this->assertSame('text_delta', $events[0]['type']);
        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertSame(3, $done['usage']['input_tokens']);
    }

    public function testNativeMcpStreamMapsEvents(): void {
        $sse = "data: {\"type\":\"message.output.delta\",\"content\":\"Hi \"}\n"
            . "data: {\"type\":\"tool.execution.started\",\"output_index\":0,\"id\":\"tool_1\",\"name\":\"list_files\",\"arguments\":\"{\\\"path\\\":\\\"/\\\"}\"}\n"
            . "data: {\"type\":\"tool.execution.done\",\"output_index\":0,\"id\":\"tool_1\",\"name\":\"list_files\",\"info\":{\"result\":\"ok\"}}\n"
            . "data: {\"type\":\"message.output.delta\",\"content\":\"there\"}\n"
            . "data: {\"type\":\"conversation.response.done\",\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":4}}\n"
            . "data: [DONE]\n";
        $this->client->method('post')->willReturn($this->sseResponse($sse));

        $events = iterator_to_array($this->provider->chatWithNativeMcp(
            [['role' => 'user', 'content' => 'go']],
            [['type' => 'connector', 'connector_id' => 'c1']],
            null,
            'u',
        ));

        $byType = [];
        foreach ($events as $e) {
            $byType[$e['type']][] = $e;
        }

        $this->assertSame('Hi ', $byType['text_delta'][0]['text']);
        $this->assertSame('there', $byType['text_delta'][1]['text']);
        $this->assertSame('list_files', $byType['tool_use'][0]['name']);
        $this->assertSame(['path' => '/'], $byType['tool_use'][0]['input']);
        $this->assertSame('tool_1', $byType['tool_result'][0]['tool_use_id']);

        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertSame(10, $done['usage']['input_tokens']);
        $this->assertSame(4, $done['usage']['output_tokens']);
    }

    public function testNativeMcpCollectReturnsResponse(): void {
        $this->client->method('post')->willReturn($this->sseResponse(
            "data: {\"type\":\"message.output.delta\",\"content\":\"All done\"}\n"
            . "data: {\"type\":\"conversation.response.done\",\"usage\":{\"prompt_tokens\":7,\"completion_tokens\":3}}\n"
            . "data: [DONE]\n"
        ));

        $result = $this->provider->chatWithNativeMcpCollect(
            [['role' => 'user', 'content' => 'hi']],
            [['type' => 'connector', 'connector_id' => 'c1']],
            null,
            'u',
        );

        $this->assertSame('All done', $result['response']);
        $this->assertSame(7, $result['usage']['input_tokens']);
    }
}
