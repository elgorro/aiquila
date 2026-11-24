<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Batch;

use OCA\AIquila\Batch\AskOperation;
use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The custom-prompt docs operation: option validation, the prompt it builds,
 * and a done-tag per question, so editing the question reprocesses files.
 */
class AskOperationTest extends TestCase {
    private const OPTIONS = ['prompt' => 'List the deadlines in this contract.', 'outputSuffix' => 'deadlines'];

    public function testValidOptionsPass(): void {
        (new AskOperation())->validateOptions(self::OPTIONS);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badOptions(): iterable {
        yield 'no prompt' => [['outputSuffix' => 'x'], 'prompt is required'];
        yield 'blank prompt' => [['prompt' => "  \n", 'outputSuffix' => 'x'], 'prompt is required'];
        yield 'prompt not a string' => [['prompt' => ['a'], 'outputSuffix' => 'x'], 'prompt is required'];
        yield 'prompt too long' => [['prompt' => str_repeat('a', AskOperation::MAX_PROMPT_LENGTH + 1), 'outputSuffix' => 'x'], 'at most 4000'];
        yield 'no suffix' => [['prompt' => 'q'], 'outputSuffix is required'];
        yield 'path separator' => [['prompt' => 'q', 'outputSuffix' => 'a/b'], 'outputSuffix is required'];
        yield 'backslash' => [['prompt' => 'q', 'outputSuffix' => 'a\\b'], 'outputSuffix is required'];
        yield 'dot' => [['prompt' => 'q', 'outputSuffix' => '..'], 'outputSuffix is required'];
        yield 'extension' => [['prompt' => 'q', 'outputSuffix' => 'a.pdf'], 'outputSuffix is required'];
        yield 'upper case' => [['prompt' => 'q', 'outputSuffix' => 'Risks'], 'outputSuffix is required'];
        yield 'too long' => [['prompt' => 'q', 'outputSuffix' => str_repeat('a', 33)], 'outputSuffix is required'];
        yield 'leading hyphen' => [['prompt' => 'q', 'outputSuffix' => '-x'], 'outputSuffix is required'];
        yield 'a source extension' => [['prompt' => 'q', 'outputSuffix' => 'pdf'], 'looks like a file type'];
        yield 'the summary suffix' => [['prompt' => 'q', 'outputSuffix' => 'summary'], 'another operation'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badOptions')]
    public function testBadOptionsAreRejected(array $options, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        (new AskOperation())->validateOptions($options);
    }

    public function testThePromptCarriesTheQuestionAndTheDocument(): void {
        $op = new AskOperation();
        $text = $op->buildPrompt('Clause 4: pay by 1 March.', self::OPTIONS);
        $this->assertStringContainsString("Request:\nList the deadlines in this contract.", $text);
        $this->assertStringEndsWith("Document:\nClause 4: pay by 1 March.", $text);

        $pdf = $op->buildDocumentPrompt(self::OPTIONS);
        $this->assertStringContainsString('about the attached document', $pdf);
        $this->assertStringNotContainsString('Document:', $pdf);
    }

    public function testTheOutputIsNamedByTheSuffix(): void {
        $this->assertSame('.deadlines.md', (new AskOperation())->outputSuffix(self::OPTIONS));
    }

    public function testTheDoneTagNamesTheQuestion(): void {
        $op = new AskOperation();
        $tag = $op->doneTag(self::OPTIONS);
        $this->assertMatchesRegularExpression('/^aiquila:asked-[0-9a-f]{8}$/', $tag);
        $this->assertSame($tag, $op->doneTag(['prompt' => '  List the deadlines in this contract. ', 'outputSuffix' => 'deadlines']), 'surrounding whitespace is not a new question');
        $this->assertNotSame($tag, $op->doneTag(['prompt' => 'List the parties.', 'outputSuffix' => 'deadlines']));
        $this->assertNotSame($tag, $op->doneTag(['prompt' => self::OPTIONS['prompt'], 'outputSuffix' => 'dates']));
    }

    /**
     * An up-to-date answer counts as done only while the source carries the
     * current question's tag; after the question changes the file is due again.
     */
    public function testEditingTheQuestionReprocessesFilesWithAFreshAnswer(): void {
        $tagged = [];
        $tags = $this->createStub(ISystemTagManager::class);
        $known = [];
        $tags->method('getTag')->willReturnCallback(function (string $name) use (&$known) {
            if (!isset($known[$name])) {
                throw new TagNotFoundException();
            }
            return $this->tag($name);
        });
        $tags->method('createTag')->willReturnCallback(function (string $name) use (&$known) {
            $known[$name] = true;
            return $this->tag($name);
        });
        $mapper = $this->createStub(ISystemTagObjectMapper::class);
        $mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, $tagIds) use (&$tagged): void {
            foreach ((array)$tagIds as $t) {
                $tagged[$id][$t] = true;
            }
        });
        $mapper->method('haveTag')->willReturnCallback(function (array $ids, string $type, string $tagId) use (&$tagged): bool {
            return isset($tagged[$ids[0]][$tagId]);
        });
        $mapper->method('getTagIdsForObjects')->willReturnCallback(
            static function (array $ids) use (&$tagged): array {
                return array_map(array_keys(...), array_intersect_key($tagged, array_flip($ids)));
            }
        );

        $provider = $this->createStub(ClaudeSDKService::class);
        $provider->method('ask')->willReturn(['response' => 'answer', 'usage' => []]);
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($provider);

        // An answer file beside the source, newer than it.
        $answer = $this->createStub(File::class);
        $answer->method('getId')->willReturn(90);
        $tags->createTag(BatchDocumentRunner::OUTPUT_TAG, false, false);
        $tagged['90']['t:' . BatchDocumentRunner::OUTPUT_TAG] = true;
        $answer->method('getMTime')->willReturn(200);
        $parent = $this->createStub(Folder::class);
        $parent->method('nodeExists')->willReturn(true);
        $parent->method('get')->willReturn($answer);
        $source = $this->createStub(File::class);
        $source->method('getId')->willReturn(7);
        $source->method('getName')->willReturn('contract.txt');
        $source->method('getPath')->willReturn('/bob/files/In/contract.txt');
        $source->method('getMimetype')->willReturn('text/plain');
        $source->method('getContent')->willReturn('body');
        $source->method('getSize')->willReturn(4);
        $source->method('getMTime')->willReturn(100);
        $source->method('getParent')->willReturn($parent);

        $root = $this->createStub(IRootFolder::class);
        $runner = new BatchDocumentRunner($root, $factory, $tags, $mapper, $this->createStub(LoggerInterface::class), $this->createStub(UsageStatService::class));
        $ctx = fn (array $options): BatchContext => new BatchContext(
            userId: 'bob',
            operation: new AskOperation(),
            options: $options,
            requestOptions: static fn (): array => [],
            allowBatch: false,
        );

        // The answer exists, but nothing says it answers this question.
        $this->assertSame([$source], $runner->collect($ctx(self::OPTIONS), $source));

        // Answered (and tagged) for this question: done.
        $runner->process($ctx(self::OPTIONS), new CoworkerRun(), [$source], static function (): void {
        });
        $this->assertSame([], $runner->collect($ctx(self::OPTIONS), $source));

        // A different question: due again, although the same answer file is fresh.
        $edited = ['prompt' => 'List the parties.', 'outputSuffix' => 'deadlines'];
        $this->assertSame([$source], $runner->collect($ctx($edited), $source));
    }

    private function tag(string $name): ISystemTag {
        $t = $this->createStub(ISystemTag::class);
        $t->method('getId')->willReturn('t:' . $name);
        return $t;
    }
}
