<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Controller\ConversationController;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message as MessageEntity;
use OCA\AIquila\Db\MessageFile;
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
 * A file attached on one turn has to stay in context for every later turn.
 *
 * The history sent to the provider is rebuilt from the database each turn, so
 * these tests keep an in-memory message and message-file store, run a second
 * turn without files, and look at what the provider received.
 */
class ConversationControllerFileHistoryTest extends TestCase {
    /** @var list<MessageEntity> */
    private array $messages = [];
    /** @var array<int, list<MessageFile>> */
    private array $filesByMessage = [];
    /** @var list<array<string, mixed>> the messages the provider last received */
    private array $sent = [];
    private int $nextId = 1;

    /**
     * @param array<string, array{name: string, mimeType: string, size: int, content: string}> $nextcloudFiles
     *        files readable in Nextcloud, by path; any other path throws
     */
    private function makeController(array $nextcloudFiles, bool $citeFirstDocument = false): ConversationController {
        $conversation = new Conversation();
        $conversation->setUserId('testuser');
        $conversation->setTitle('existing');

        $conversationMapper = $this->createMock(ConversationMapper::class);
        $conversationMapper->method('findByIdAndUser')->willReturn($conversation);
        $conversationMapper->method('update')->willReturnArgument(0);

        $messageMapper = $this->createMock(MessageMapper::class);
        $messageMapper->method('insert')->willReturnCallback(function (MessageEntity $m): MessageEntity {
            $m->setId($this->nextId++);
            $this->messages[] = $m;
            return $m;
        });
        $messageMapper->method('findByConversation')->willReturnCallback(fn(): array => $this->messages);

        $messageFileMapper = $this->createMock(MessageFileMapper::class);
        $messageFileMapper->method('insert')->willReturnCallback(function (MessageFile $f): MessageFile {
            $f->setId($this->nextId++);
            $this->filesByMessage[$f->getMessageId()][] = $f;
            return $f;
        });
        $messageFileMapper->method('findByMessage')->willReturnCallback(
            fn(int $id): array => $this->filesByMessage[$id] ?? []
        );

        $fileService = $this->createMock(FileService::class);
        $fileService->method('getContent')->willReturnCallback(
            static function (string $path) use ($nextcloudFiles): array {
                if (!isset($nextcloudFiles[$path])) {
                    throw new \RuntimeException('not found: ' . $path);
                }
                return $nextcloudFiles[$path];
            }
        );
        $fileService->method('getInfo')->willReturnCallback(
            static fn(string $path): array => ['name' => basename($path), 'mimeType' => $nextcloudFiles[$path]['mimeType'] ?? 'application/octet-stream']
        );

        $citations = $citeFirstDocument ? [['type' => 'page_location', 'document_index' => 0, 'cited_text' => 'x']] : [];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('supportsNativeMcp')->willReturn(false);
        $provider->method('getId')->willReturn('claude');
        $provider->method('chat')->willReturnCallback(
            function (array $messages) use ($citations): array {
                $this->sent = $messages;
                return ['response' => 'ok', 'usage' => [], 'citations' => $citations];
            }
        );
        $provider->method('chatWithToolsStream')->willReturnCallback(
            function (array $messages): \Generator {
                $this->sent = $messages;
                yield ['type' => 'text_delta', 'text' => 'ok'];
                yield ['type' => 'done', 'usage' => [], 'citations' => []];
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
            $messageMapper,
            $messageFileMapper,
            $this->createMock(ProjectMapper::class),
            $this->createMock(ProjectPathMapper::class),
            $factory,
            $fileService,
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

    private static function textFile(string $name, string $content): array {
        return ['name' => $name, 'mimeType' => 'text/markdown', 'size' => strlen($content), 'content' => $content];
    }

    private static function pdf(string $name): array {
        return ['name' => $name, 'mimeType' => 'application/pdf', 'size' => 4, 'content' => base64_encode('%PDF')];
    }

    /** Concatenated text of every block in a message's content. */
    private static function flatten(array $message): string {
        $content = $message['content'];
        if (is_string($content)) {
            return $content;
        }
        return implode("\n", array_map(
            static fn(array $b): string => (string)($b['text'] ?? ''),
            $content
        ));
    }

    public function testFollowUpTurnStillCarriesTheEarlierFile(): void {
        $controller = $this->makeController(['/notes.md' => self::textFile('notes.md', 'The code word is heron.')]);

        $controller->message(1, 'Read this', ['/notes.md']);
        $controller->message(1, 'What was the code word?');

        $this->assertCount(3, $this->sent, 'user, assistant, user');
        $first = $this->sent[0];
        $this->assertSame('user', $first['role']);
        $this->assertIsArray($first['content'], 'the earlier turn keeps its file blocks');
        $this->assertStringContainsString('The code word is heron.', self::flatten($first));
        $this->assertSame(['type' => 'text', 'text' => 'Read this'], end($first['content']));
        $this->assertSame('What was the code word?', $this->sent[2]['content']);
    }

    /**
     * Citations number documents across the whole request. With a PDF on turn
     * one and another on turn two, the second one is index 1, and the index
     * stored on the reply has to say so or a citation opens the wrong file.
     */
    public function testDocumentIndexCountsAcrossTurns(): void {
        $controller = $this->makeController(
            ['/a.pdf' => self::pdf('a.pdf'), '/b.pdf' => self::pdf('b.pdf')],
            citeFirstDocument: true,
        );

        $controller->message(1, 'First', ['/a.pdf']);
        $response = $controller->message(1, 'Second', ['/b.pdf']);

        $documents = $response->getData()['assistantMessage']['documents'];
        $this->assertSame(
            [[0, '/a.pdf'], [1, '/b.pdf']],
            array_map(static fn(array $d): array => [$d['index'], $d['path']], $documents)
        );
    }

    /** A file deleted since it was attached must not break the conversation. */
    public function testFileGoneSinceAnEarlierTurnBecomesANote(): void {
        $controller = $this->makeController(['/gone.md' => self::textFile('gone.md', 'soon deleted')]);
        $controller->message(1, 'Read this', ['/gone.md']);

        $controller = $this->makeController([]);
        $response = $controller->message(1, 'And now?');

        $this->assertSame('ok', $response->getData()['assistantMessage']['content']);
        $this->assertStringContainsString('gone.md (could not be read)', self::flatten($this->sent[0]));
    }

    public function testStreamingTurnGetsTheSameHistory(): void {
        $controller = $this->makeController(['/notes.md' => self::textFile('notes.md', 'The code word is heron.')]);
        $controller->message(1, 'Read this', ['/notes.md']);

        $method = new \ReflectionMethod(ConversationController::class, 'streamConversationReply');
        iterator_to_array($method->invoke($controller, 1, 'What was the code word?', []), false);

        $this->assertCount(3, $this->sent);
        $this->assertStringContainsString('The code word is heron.', self::flatten($this->sent[0]));
    }
}
