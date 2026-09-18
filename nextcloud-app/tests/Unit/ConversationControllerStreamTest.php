<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ConversationController;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message as MessageEntity;
use OCA\AIquila\Db\MessageFileMapper;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Service\ContextChatService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the generator behind ConversationController::messageStream().
 *
 * What matters here is what survives a turn that goes wrong. The stream is the
 * only record the user gets, and the persisted message is the only record they
 * keep, so a failure has to reach both: an `error` event on the wire and the
 * partial text in the database, marked as interrupted. Silence, or text stored
 * as though it were a finished reply, is the bug.
 */
class ConversationControllerStreamTest extends TestCase {
    private MessageMapper $messageMapper;
    private Conversation $conversation;
    /** @var list<MessageEntity> */
    private array $inserted = [];

    /**
     * @param iterable<array<string, mixed>>|callable $events what the provider streams
     */
    private function makeController($events): ConversationController {
        $this->inserted = [];
        $this->conversation = new Conversation();
        $this->conversation->setUserId('testuser');
        $this->conversation->setTitle(null);

        $conversationMapper = $this->createMock(ConversationMapper::class);
        $conversationMapper->method('findByIdAndUser')->willReturn($this->conversation);
        $conversationMapper->method('update')->willReturnArgument(0);

        $nextId = 1;
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->messageMapper->method('insert')->willReturnCallback(
            function (MessageEntity $m) use (&$nextId): MessageEntity {
                $m->setId($nextId++);
                $this->inserted[] = $m;
                return $m;
            }
        );
        $this->messageMapper->method('findByConversation')->willReturn([]);

        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('supportsNativeMcp')->willReturn(false);
        $provider->method('getId')->willReturn('local');
        $provider->method('getLabel')->willReturn('Local model');
        $provider->method('chatWithToolsStream')->willReturnCallback(
            static function () use ($events): \Generator {
                if (is_callable($events)) {
                    yield from $events();
                    return;
                }
                yield from $events;
            }
        );

        $factory = $this->createMock(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProviderForUser')->willReturn($provider);

        $mcpClient = $this->createMock(McpClientService::class);
        $mcpClient->method('getAllTools')->willReturn(['tools' => [], 'mapping' => []]);

        $nativeMcp = $this->createMock(NativeMcpService::class);
        $nativeMcp->method('isEnabledForUser')->willReturn(false);

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'aiquila',
            $request,
            $conversationMapper,
            $this->messageMapper,
            $this->createMock(MessageFileMapper::class),
            $this->createMock(ProjectMapper::class),
            $this->createMock(ProjectPathMapper::class),
            $factory,
            $this->createMock(FileService::class),
            $this->createMock(FilesService::class),
            $this->createMock(ImageOptimizer::class),
            $mcpClient,
            $nativeMcp,
            $this->createMock(IJobList::class),
            $this->createMock(ContextChatService::class),
            'testuser',
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param iterable<array<string, mixed>>|callable $events
     * @return list<array<string, mixed>>
     */
    private function drain($events, string $prompt = 'hello'): array {
        $controller = $this->makeController($events);
        $method = new \ReflectionMethod(ConversationController::class, 'streamConversationReply');

        return iterator_to_array($method->invoke($controller, 1, $prompt, []), false);
    }

    /** @param list<array<string, mixed>> $events */
    private function types(array $events): array {
        return array_map(static fn(array $e): string => (string)($e['type'] ?? ''), $events);
    }

    private function assistantContent(): string {
        $assistant = array_values(array_filter(
            $this->inserted,
            static fn(MessageEntity $m): bool => $m->getRole() === 'assistant'
        ));
        $this->assertCount(1, $assistant, 'exactly one assistant message should be persisted');
        return (string)$assistant[0]->getContent();
    }

    public function testHappyPathStreamsAndPersists(): void {
        $events = $this->drain([
            ['type' => 'text_delta', 'text' => 'Hello '],
            ['type' => 'text_delta', 'text' => 'world'],
            ['type' => 'done', 'usage' => ['input_tokens' => 3, 'output_tokens' => 2], 'citations' => []],
        ]);

        $this->assertSame(
            ['user_message', 'text_delta', 'text_delta', 'done', 'persisted'],
            $this->types($events)
        );
        $this->assertSame('Hello world', $this->assistantContent());
    }

    /** The first prompt names the conversation, as the non-streaming path does. */
    public function testFirstTurnSetsTheTitle(): void {
        $this->drain([['type' => 'done', 'usage' => [], 'citations' => []]], 'What is a raincoat?');

        $this->assertSame('What is a raincoat?', $this->conversation->getTitle());
    }

    public function testErrorEventPersistsPartialTextWithSuffix(): void {
        $events = $this->drain([
            ['type' => 'text_delta', 'text' => 'partial answer'],
            ['type' => 'error', 'error' => 'upstream went away'],
        ]);

        $this->assertSame(['user_message', 'text_delta', 'error', 'persisted'], $this->types($events));

        $content = $this->assistantContent();
        $this->assertStringStartsWith('partial answer', $content);
        $this->assertStringContainsString('_(stream interrupted: upstream went away)_', $content);
    }

    /**
     * A provider can fail by throwing rather than by yielding an `error` event.
     * Without a guard the exception escapes mid-render: nothing is persisted and
     * the client is left with a stream that simply stops.
     */
    public function testThrownExceptionIsReportedAndPersistedLikeAnErrorEvent(): void {
        $events = $this->drain(static function (): \Generator {
            yield ['type' => 'text_delta', 'text' => 'half a th'];
            throw new \RuntimeException('connection reset');
        });

        $this->assertSame(['user_message', 'text_delta', 'error', 'persisted'], $this->types($events));
        $this->assertSame('connection reset', $events[2]['error']);

        $content = $this->assistantContent();
        $this->assertStringStartsWith('half a th', $content);
        $this->assertStringContainsString('_(stream interrupted: connection reset)_', $content);
    }

    /** A failure before any text still has to leave a record of what went wrong. */
    public function testFailureWithNoTextPersistsTheError(): void {
        $this->drain(static function (): \Generator {
            yield from [];
            throw new \RuntimeException('no route to host');
        });

        $this->assertSame('Error: no route to host', $this->assistantContent());
    }
}
