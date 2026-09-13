<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\MistralModels;
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
            'data' => [['id' => MistralModels::SMALL], ['id' => MistralModels::LARGE]],
        ]));

        $models = $this->provider->listModels('u');
        $this->assertContains(MistralModels::LARGE, $models);
        $this->assertContains(MistralModels::SMALL, $models);
    }

    public function testVisionFallbackTargetsACurrentModel(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'a cat']]]]);
        });

        // Default model is Small 4, which is multimodal: no swap.
        $this->provider->askWithImage('what is this?', 'AAAA', 'image/png', 'u');
        $this->assertSame(MistralModels::DEFAULT_MODEL, $captured['model']);

        // supportsVision() must not resurrect the retired pixtral heuristic.
        $this->assertFalse(MistralModels::supportsVision('pixtral-large-latest'));
        $this->assertTrue(MistralModels::supportsVision(MistralModels::DEFAULT_MODEL));
        $this->assertSame(MistralModels::VISION_MODEL, MistralModels::SMALL);
    }

    public function testReasoningEffortIsSentOnlyForModelsThatAcceptIt(): void {
        $captured = null;
        $this->client->method('post')->willReturnCallback(function (string $url, array $opts) use (&$captured) {
            $captured = json_decode($opts['body'], true);
            return $this->jsonResponse(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        // No effort requested → parameter omitted entirely.
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u');
        $this->assertArrayNotHasKey('reasoning_effort', $captured);

        // Small 4 reasons.
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u', ['effort' => 'high']);
        $this->assertSame('high', $captured['reasoning_effort']);

        // Ministral does not: the value is dropped rather than 400ing the API.
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u', [
            'effort' => 'high',
            'model' => MistralModels::MINISTRAL_8B,
        ]);
        $this->assertArrayNotHasKey('reasoning_effort', $captured);

        // Anthropic's vocabulary is not Mistral's.
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], null, 'u', ['effort' => 'xhigh']);
        $this->assertArrayNotHasKey('reasoning_effort', $captured);
    }

    public function testAllowedEffortsAreProviderScoped(): void {
        $this->assertTrue($this->provider->getCapabilities()['effort']);
        $this->assertSame(['none', 'high'], $this->provider->getAllowedEfforts(MistralModels::MEDIUM));
        $this->assertSame([], $this->provider->getAllowedEfforts(MistralModels::MINISTRAL_3B));
    }

    public function testReasoningChunkListContentIsFlattenedToTheAnswer(): void {
        $this->client->method('post')->willReturn($this->jsonResponse([
            'choices' => [[
                'message' => ['content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'let me think']]],
                    ['type' => 'text', 'text' => '42'],
                ]],
                'finish_reason' => 'stop',
            ]],
        ]));

        $result = $this->provider->chat([['role' => 'user', 'content' => 'q']], null, 'u');
        $this->assertSame('42', $result['response']);
    }

    public function testStreamedThinkingChunksAreNotEmittedAsAnswerText(): void {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":[{\"type\":\"thinking\",\"thinking\":[{\"type\":\"text\",\"text\":\"hmm\"}]}]}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Answer\"},\"finish_reason\":\"stop\"}]}\n"
            . "data: [DONE]\n";
        $this->client->method('post')->willReturn($this->sseResponse($sse));

        $events = iterator_to_array($this->provider->chatWithToolsStream([['role' => 'user', 'content' => 'hi']], [], fn() => [], null, 'u'));

        $deltas = array_values(array_filter($events, fn($e) => $e['type'] === 'text_delta'));
        $this->assertCount(1, $deltas);
        $this->assertSame('Answer', $deltas[0]['text']);
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

    // ── Non-text modalities ─────────────────────────────────────────────────

    public function testCapabilitiesDeclareTheThreeModalities(): void {
        $capabilities = $this->provider->getCapabilities();
        $this->assertTrue($capabilities['audio_in']);
        $this->assertTrue($capabilities['audio_out']);
        $this->assertTrue($capabilities['image_out']);
    }

    public function testTranscribeAudioPostsMultipartWithoutAJsonContentType(): void {
        $url = null;
        $options = null;
        $this->client->method('post')->willReturnCallback(
            function (string $u, array $o) use (&$url, &$options): IResponse {
                $url = $u;
                $options = $o;
                return $this->jsonResponse([
                    'text' => 'Guten Morgen.',
                    'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 8],
                ]);
            }
        );

        $result = $this->provider->transcribeAudio('raw-bytes', 'audio/mpeg', 'memo.mp3', 'u', ['language' => 'de']);

        $this->assertSame('Guten Morgen.', $result['response']);
        $this->assertSame(40, $result['usage']['input_tokens']);
        $this->assertSame('https://api.mistral.ai/v1/audio/transcriptions', $url);
        // Guzzle writes Content-Type itself, boundary included.
        $this->assertArrayNotHasKey('Content-Type', $options['headers']);
        $this->assertSame('Bearer test-key', $options['headers']['Authorization']);
        $this->assertSame([
            ['name' => 'model', 'contents' => MistralProvider::DEFAULT_TRANSCRIBE_MODEL],
            ['name' => 'file', 'contents' => 'raw-bytes', 'filename' => 'memo.mp3'],
            ['name' => 'language', 'contents' => 'de'],
        ], $options['multipart']);
    }

    public function testTranscribeAudioReportsAMissingTranscript(): void {
        $this->client->method('post')->willReturn($this->jsonResponse(['model' => 'voxtral-mini-latest']));

        $this->assertSame(['error' => 'Mistral returned no transcript.'], $this->provider->transcribeAudio('raw', 'audio/mpeg', 'a.mp3', 'u'));
    }

    public function testTranscribeAudioRejectsAnEmptyRecordingWithoutCallingTheApi(): void {
        $this->client->expects($this->never())->method('post');

        $this->assertSame(['error' => 'No audio to transcribe.'], $this->provider->transcribeAudio('', 'audio/mpeg', 'a.mp3', 'u'));
    }

    /** Mistral documents base64 inside a JSON envelope. */
    public function testSynthesizeSpeechDecodesABase64Envelope(): void {
        $body = null;
        $this->client->method('post')->willReturnCallback(
            function (string $u, array $o) use (&$body): IResponse {
                $body = json_decode($o['body'], true);
                return $this->jsonResponse(['audio_data' => base64_encode('mp3-bytes')]);
            }
        );

        $result = $this->provider->synthesizeSpeech('Read this out.', 'u', ['voice' => 'alloy']);

        $this->assertSame('mp3-bytes', $result['audio']);
        $this->assertSame('audio/mpeg', $result['mimeType']);
        $this->assertSame(MistralProvider::DEFAULT_TTS_MODEL, $body['model']);
        $this->assertSame('Read this out.', $body['input']);
        $this->assertSame('alloy', $body['voice_id']);
        $this->assertSame('mp3', $body['response_format']);
    }

    /** The OpenAI-shaped route this mirrors answers with the bytes directly. */
    public function testSynthesizeSpeechAcceptsARawAudioBody(): void {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn("ID3\x03 raw mp3");
        $this->client->method('post')->willReturn($response);

        $this->assertSame("ID3\x03 raw mp3", $this->provider->synthesizeSpeech('Hello', 'u')['audio']);
    }

    public function testSynthesizeSpeechRejectsBlankTextWithoutCallingTheApi(): void {
        $this->client->expects($this->never())->method('post');

        $this->assertSame(['error' => 'No text to speak.'], $this->provider->synthesizeSpeech('   ', 'u'));
    }

    public function testGenerateImagesUsesAnInlineToolAndDownloadsEachFile(): void {
        $body = null;
        $this->client->method('post')->willReturnCallback(
            function (string $u, array $o) use (&$body): IResponse {
                $this->assertSame('https://api.mistral.ai/v1/conversations', $u);
                $body = json_decode($o['body'], true);
                return $this->jsonResponse([
                    'outputs' => [
                        ['type' => 'tool.execution', 'name' => 'image_generation'],
                        ['type' => 'message.output', 'content' => [
                            ['type' => 'text', 'text' => 'Here you go.'],
                            ['type' => 'tool_file', 'tool' => 'image_generation', 'file_id' => 'file-1', 'file_type' => 'png'],
                            ['type' => 'tool_file', 'tool' => 'image_generation', 'file_id' => 'file-2', 'file_type' => 'png'],
                        ]],
                    ],
                    'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 4],
                ]);
            }
        );
        $downloaded = [];
        $this->client->method('get')->willReturnCallback(
            function (string $u) use (&$downloaded): IResponse {
                $downloaded[] = $u;
                $response = $this->createMock(IResponse::class);
                $response->method('getBody')->willReturn('png-' . count($downloaded));
                return $response;
            }
        );

        $result = $this->provider->generateImages('an orange cat', 2, 'u');

        $this->assertSame(['png-1', 'png-2'], $result['images']);
        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame(30, $result['usage']['input_tokens']);
        $this->assertSame([
            'https://api.mistral.ai/v1/files/file-1/content',
            'https://api.mistral.ai/v1/files/file-2/content',
        ], $downloaded);

        // No agent is created: the tool rides along on the conversation itself.
        $this->assertSame([['type' => 'image_generation']], $body['tools']);
        $this->assertSame(MistralProvider::DEFAULT_IMAGE_MODEL, $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertStringContainsString('Generate 2 distinct images', $body['inputs'][0]['content']);
    }

    public function testGenerateImagesStopsAtTheRequestedCount(): void {
        $this->client->method('post')->willReturn($this->jsonResponse([
            'outputs' => [['type' => 'message.output', 'content' => [
                ['type' => 'tool_file', 'file_id' => 'file-1', 'file_type' => 'jpg'],
                ['type' => 'tool_file', 'file_id' => 'file-2', 'file_type' => 'jpg'],
            ]]],
        ]));
        $this->client->expects($this->once())->method('get')->willReturnCallback(
            function (): IResponse {
                $response = $this->createMock(IResponse::class);
                $response->method('getBody')->willReturn('jpeg-bytes');
                return $response;
            }
        );

        $result = $this->provider->generateImages('a blue door', 1, 'u');

        $this->assertSame(['jpeg-bytes'], $result['images']);
        $this->assertSame('image/jpeg', $result['mimeType']);
    }

    public function testGenerateImagesReportsAResponseWithNoFiles(): void {
        $this->client->method('post')->willReturn($this->jsonResponse([
            'outputs' => [['type' => 'message.output', 'content' => [['type' => 'text', 'text' => 'I cannot do that.']]]],
        ]));

        $this->assertSame(
            ['error' => 'Mistral generated no images for this prompt.'],
            $this->provider->generateImages('a blue door', 1, 'u'),
        );
    }
}
