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
use OCA\AIquila\Service\RuntimeContextService;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\FileService;
use OCA\AIquila\Service\FilesService;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\NativeMcpService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\AIquila\Service\UsageStatService;

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
    /** What the next chatWithTools() call reports as its tool_trace. */
    private array $nextTrace = [];
    /** The tools the MCP servers list right now, and the tools last sent. */
    private ?array $currentTools = null;
    private array $sentTools = [];

    /**
     * @param array<string, array{name: string, mimeType: string, size: int, content: string}> $nextcloudFiles
     *        files readable in Nextcloud, by path; any other path throws
     */
    private function makeController(array $nextcloudFiles, bool $citeFirstDocument = false, array $tools = [], bool $claude = false): ConversationController {
        $conversation = new Conversation();
        $conversation->setId(1);
        $conversation->setUserId('testuser');
        $conversation->setTitle('existing');

        $conversationMapper = $this->createStub(ConversationMapper::class);
        $conversationMapper->method('findByIdAndUser')->willReturn($conversation);
        $conversationMapper->method('update')->willReturnArgument(0);

        $messageMapper = $this->createStub(MessageMapper::class);
        $messageMapper->method('insert')->willReturnCallback(function (MessageEntity $m): MessageEntity {
            $m->setId($this->nextId++);
            $this->messages[] = $m;
            return $m;
        });
        $messageMapper->method('findByConversation')->willReturnCallback(fn(): array => $this->messages);

        $messageFileMapper = $this->createStub(MessageFileMapper::class);
        $messageFileMapper->method('insert')->willReturnCallback(function (MessageFile $f): MessageFile {
            $f->setId($this->nextId++);
            $this->filesByMessage[$f->getMessageId()][] = $f;
            return $f;
        });
        $messageFileMapper->method('findByMessage')->willReturnCallback(
            fn(int $id): array => $this->filesByMessage[$id] ?? []
        );

        $fileService = $this->createStub(FileService::class);
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
        $provider = $claude ? $this->createStub(ClaudeSDKService::class) : $this->createStub(LLMProviderInterface::class);
        if ($claude) {
            $provider->method('supportsMidConversationToolChanges')->willReturn(true);
        }
        $provider->method('supportsNativeMcp')->willReturn(false);
        $provider->method('getId')->willReturn('claude');
        $provider->method('chat')->willReturnCallback(
            function (array $messages) use ($citations): array {
                $this->sent = $messages;
                return ['response' => 'ok', 'usage' => [], 'citations' => $citations];
            }
        );
        $provider->method('chatWithTools')->willReturnCallback(
            function (array $messages, array $tools): array {
                $this->sent = $messages;
                $this->sentTools = $tools;
                return ['response' => 'There are two files.', 'usage' => [], 'citations' => [], 'tool_trace' => $this->nextTrace];
            }
        );
        $provider->method('chatWithToolsStream')->willReturnCallback(
            function (array $messages): \Generator {
                $this->sent = $messages;
                yield ['type' => 'text_delta', 'text' => 'ok'];
                yield ['type' => 'done', 'usage' => [], 'citations' => [], 'tool_trace' => $this->nextTrace];
            }
        );

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('hasPermittedProvider')->willReturn(true);
        $factory->method('getProviderForUser')->willReturn($provider);

        $mcpClient = $this->createStub(McpClientService::class);
        $mcpClient->method('getAllTools')->willReturnCallback(fn(): array => ['tools' => $this->currentTools ?? $tools, 'mapping' => []]);

        $nativeMcp = $this->createStub(NativeMcpService::class);
        $nativeMcp->method('isEnabledForUser')->willReturn(false);

        $request = $this->createStub(IRequest::class);
        $request->method('getParams')->willReturn([]);

        return new ConversationController(
            'aiquila',
            $request,
            $conversationMapper,
            $messageMapper,
            $messageFileMapper,
            $this->createStub(ProjectMapper::class),
            $this->createStub(ProjectPathMapper::class),
            $factory,
            $fileService,
            $this->createStub(FilesService::class),
            $this->createStub(ImageOptimizer::class),
            $mcpClient,
            $nativeMcp,
            $this->createStub(IJobList::class),
            $this->createStub(ContextChatService::class),
            $this->createStub(RuntimeContextService::class),
            $this->createStub(InstructionsService::class),
            $this->createStub(UsageStatService::class),
            'testuser',
            $this->createStub(LoggerInterface::class),
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

    private const TOOLS = [['name' => 'list_files', 'description' => 'List', 'input_schema' => ['type' => 'object']]];

    /** The turns a tool loop appended before answering. */
    private static function listFilesTrace(): array {
        return [
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'List them.', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'list_files', 'input' => ['path' => '/']],
            ]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_01', 'content' => 'heron.txt, egret.txt']]],
        ];
    }

    /**
     * The next turn sees what the tool returned, as blocks, ahead of the
     * answer built on it — so the model need not call the tool again.
     */
    public function testTheNextTurnSeesWhatTheToolReturned(): void {
        $controller = $this->makeController([], tools: self::TOOLS);
        $this->nextTrace = self::listFilesTrace();
        $controller->message(1, 'What is in my files?');
        $this->nextTrace = [];
        $controller->message(1, 'Which of them is about egrets?');

        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($this->sent, 'role'));
        $this->assertSame(['tool_use'], array_column($this->sent[1]['content'], 'type'), 'thinking is not kept');
        $this->assertSame('heron.txt, egret.txt', $this->sent[2]['content'][0]['content']);
        $this->assertSame($this->sent[1]['content'][0]['id'], $this->sent[2]['content'][0]['tool_use_id']);
        $this->assertSame('There are two files.', $this->sent[3]['content']);
    }

    /** A request without tools cannot carry tool_use blocks: they go as text. */
    public function testWithoutToolsTheCallsAreReplayedAsText(): void {
        $controller = $this->makeController([], tools: self::TOOLS);
        $this->nextTrace = self::listFilesTrace();
        $controller->message(1, 'What is in my files?');

        $controller = $this->makeController([]);
        $controller->message(1, 'Which of them is about egrets?');

        $flat = json_encode($this->sent);
        $this->assertStringNotContainsString('"tool_use"', $flat);
        $this->assertStringContainsString('[Tool list_files returned: heron.txt, egret.txt]', self::flatten($this->sent[2]));
    }

    public function testTheTraceIsStoredButNotServed(): void {
        $controller = $this->makeController([], tools: self::TOOLS);
        $this->nextTrace = self::listFilesTrace();
        $controller->message(1, 'What is in my files?');

        $assistant = $this->messages[1];
        $this->assertNotNull($assistant->getToolTrace());
        $this->assertArrayNotHasKey('toolTrace', $assistant->jsonSerialize(), 'the trace stays server-side');
    }

    /** Streaming keeps the trace with the answer and does not send it to the browser. */
    public function testStreamingStoresTheTraceButDoesNotSendIt(): void {
        $controller = $this->makeController([], tools: self::TOOLS);
        $this->nextTrace = self::listFilesTrace();

        $method = new \ReflectionMethod(ConversationController::class, 'streamConversationReply');
        $events = iterator_to_array($method->invoke($controller, 1, 'What is in my files?', []), false);

        $done = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === 'done'))[0];
        $this->assertArrayNotHasKey('tool_trace', $done);
        $this->assertNotNull($this->messages[1]->getToolTrace());
    }

    private static function namedTool(string $name): array {
        return ['name' => $name, 'description' => $name, 'input_schema' => ['type' => 'object']];
    }

    /**
     * An MCP server's new tool does not change `tools` at the front: it is
     * appended deferred and offered from this turn by a stored system row,
     * which later turns replay in place.
     */
    public function testANewToolIsAddedMidConversationWithoutChangingTheToolsPrefix(): void {
        $controller = $this->makeController([], claude: true);
        $this->currentTools = [self::namedTool('a')];
        $controller->message(1, 'First');
        $firstTools = $this->sentTools;

        $this->currentTools = [self::namedTool('a'), self::namedTool('b')];
        $controller->message(1, 'Second');

        $this->assertSame($firstTools[0], $this->sentTools[0], 'the declared start is byte-identical');
        $this->assertTrue($this->sentTools[1]['defer_loading']);
        $last = end($this->sent);
        $this->assertSame('system', $last['role']);
        $this->assertSame('tool_addition', $last['content'][0]['type']);

        $controller->message(1, 'Third');

        $system = array_values(array_filter($this->sent, static fn(array $m): bool => $m['role'] === 'system'));
        $this->assertCount(1, $system, 'the stored change replays once, in place');
        $this->assertSame('tool_addition', $system[0]['content'][0]['type']);
        $this->assertSame('assistant', $this->sent[array_search($system[0], $this->sent, true) + 1]['role']);
    }

    /** A provider without the feature gets today's tools as they are, and no change rows. */
    public function testWithoutTheFeatureToolsAreSentAsTheyAre(): void {
        $controller = $this->makeController([]);
        $this->currentTools = [self::namedTool('a')];
        $controller->message(1, 'First');
        $this->currentTools = [self::namedTool('b')];
        $controller->message(1, 'Second');

        $this->assertSame([self::namedTool('b')], $this->sentTools);
        $this->assertSame([], array_filter($this->messages, static fn($m): bool => $m->getRole() === 'system'));
    }
}
