<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\TaskProcessing\ChatWithToolsProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use PHPUnit\Framework\TestCase;

/**
 * core:text2text:chatwithtools — the one task type where Nextcloud runs the
 * tools and AIquila only reports what the model asked for.
 */
class ChatWithToolsProviderTest extends TestCase {
    private $factory;
    private $llm;
    private ProviderResolver $resolver;
    private ChatWithToolsProvider $provider;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->llm = $this->createMock(LLMProviderInterface::class);
        $this->factory->method('getProviderForUser')->willReturn($this->llm);
        $this->resolver = new ProviderResolver($this->factory);
        $this->provider = new ChatWithToolsProvider($this->resolver);
    }

    public function testTaskTypeIdMatchesNextcloud(): void {
        $this->assertSame(TextToTextChatWithTools::ID, $this->provider->getTaskTypeId());
        $this->assertArrayHasKey('provider', $this->provider->getOptionalInputShape());
    }

    public function testOpenAiToolsAreTranslatedToTheCanonicalShape(): void {
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with(
                [['role' => 'user', 'content' => 'What is the weather?']],
                [[
                    'name' => 'get_weather',
                    'description' => 'Look up the weather',
                    'input_schema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ]],
                null,
                'alice',
            )
            ->willReturn(['response' => '', 'tool_calls' => []]);

        $this->provider->process('alice', [
            'input' => 'What is the weather?',
            'tools' => json_encode([[
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Look up the weather',
                    'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                ],
            ]]),
        ], static fn (float $p) => null);
    }

    public function testToolCallsComeBackAsOpenAiJsonWithStringArguments(): void {
        $this->llm->method('chatToolsTurn')->willReturn([
            'response' => 'Let me check.',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'get_weather', 'arguments' => ['city' => 'Berlin']],
            ],
        ]);

        $result = $this->provider->process('alice', ['input' => 'Weather?'], static fn (float $p) => null);

        $this->assertSame('Let me check.', $result['output']);
        $decoded = json_decode($result['tool_calls'], true);
        $this->assertSame('call_1', $decoded[0]['id']);
        $this->assertSame('function', $decoded[0]['type']);
        $this->assertSame('get_weather', $decoded[0]['function']['name']);
        // OpenAI carries arguments as a JSON string, not an object.
        $this->assertSame('{"city":"Berlin"}', $decoded[0]['function']['arguments']);
    }

    public function testNoToolCallsYieldsAnEmptyString(): void {
        $this->llm->method('chatToolsTurn')->willReturn(['response' => 'Sunny.', 'tool_calls' => []]);

        $result = $this->provider->process('alice', ['input' => 'Weather?'], static fn (float $p) => null);

        $this->assertSame(['output' => 'Sunny.', 'tool_calls' => ''], $result);
    }

    public function testToolResultsAreReplayedAfterTheUserTurn(): void {
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with(
                [
                    ['role' => 'user', 'content' => 'Hi'],
                    ['role' => 'assistant', 'content' => 'Hello'],
                    ['role' => 'user', 'content' => 'Weather?'],
                    ['role' => 'user', 'content' => "Tool results:\nBerlin: 18C"],
                ],
                [],
                null,
                'alice',
            )
            ->willReturn(['response' => 'It is 18C.', 'tool_calls' => []]);

        $this->provider->process('alice', [
            'input' => 'Weather?',
            'history' => ['Hi', 'Hello'],
            'tool_message' => 'Berlin: 18C',
        ], static fn (float $p) => null);
    }

    public function testAToolMessageAloneIsEnoughToContinue(): void {
        $this->llm->expects($this->once())
            ->method('chatToolsTurn')
            ->with([['role' => 'user', 'content' => "Tool results:\nBerlin: 18C"]], [], null, 'alice')
            ->willReturn(['response' => 'It is 18C.', 'tool_calls' => []]);

        $this->provider->process('alice', ['tool_message' => 'Berlin: 18C'], static fn (float $p) => null);
    }

    public function testAnEmptyTaskIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No chat message provided');

        $this->provider->process('alice', [], static fn (float $p) => null);
    }

    public function testMalformedToolsJsonIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->provider->process('alice', [
            'input' => 'Weather?',
            'tools' => '{not json',
        ], static fn (float $p) => null);
    }

    public function testProviderErrorsSurfaceAsRuntimeExceptions(): void {
        $this->llm->method('chatToolsTurn')->willReturn(['error' => 'Rate limit exceeded.']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded.');

        $this->provider->process('alice', ['input' => 'Weather?'], static fn (float $p) => null);
    }
}
