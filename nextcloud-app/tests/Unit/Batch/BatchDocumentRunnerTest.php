<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Batch;

use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Batch\SummarizeOperation;
use OCA\AIquila\Batch\TranslateOperation;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The runner on its own, with no coworker anywhere: everything it knows comes
 * from the BatchContext.
 */
class BatchDocumentRunnerTest extends TestCase {
    private $provider;
    private $userFolder;
    private BatchDocumentRunner $runner;
    /** @var list<array{0: ?string, 1: string}> getProviderForUser() calls */
    private array $providerCalls = [];
    /** @var list<array<int, mixed>> */
    private array $usageRecorded = [];
    /** @var array<string, string> */
    private array $written = [];
    /** @var array<string, list<string>> file id => system tag ids */
    private array $tags = [];

    protected function setUp(): void {
        $this->provider = $this->createStub(ClaudeSDKService::class);
        $this->provider->method('supportsBatch')->willReturn(true);

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturnCallback(function (string $uid, ?string $id = null) {
            $this->providerCalls[] = [$id, $uid];
            return $this->provider;
        });

        $this->userFolder = $this->createStub(Folder::class);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);

        $usage = $this->createStub(UsageStatService::class);
        $usage->method('record')->willReturnCallback(function (mixed ...$args): void {
            $this->usageRecorded[] = $args;
        });

        $this->runner = new BatchDocumentRunner(
            $root,
            $factory,
            $this->tagManager(),
            $this->tagMapper(),
            $this->createStub(LoggerInterface::class),
            $usage,
        );
    }

    /** One system tag per name, created on first use, id = "t:<name>". */
    private function tagManager(): ISystemTagManager {
        $created = [];
        $manager = $this->createStub(ISystemTagManager::class);
        $tag = function (string $name): \OCP\SystemTag\ISystemTag {
            $t = $this->createStub(\OCP\SystemTag\ISystemTag::class);
            $t->method('getId')->willReturn('t:' . $name);
            return $t;
        };
        $manager->method('getTag')->willReturnCallback(function (string $name) use (&$created, $tag) {
            if (!isset($created[$name])) {
                throw new \OCP\SystemTag\TagNotFoundException();
            }
            return $tag($name);
        });
        $manager->method('createTag')->willReturnCallback(function (string $name) use (&$created, $tag) {
            $created[$name] = true;
            return $tag($name);
        });
        return $manager;
    }

    private function tagMapper(): ISystemTagObjectMapper {
        $mapper = $this->createStub(ISystemTagObjectMapper::class);
        $mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, $tagIds): void {
            $this->tags[$id] = array_merge($this->tags[$id] ?? [], (array)$tagIds);
        });
        $mapper->method('getTagIdsForObjects')->willReturnCallback(
            fn (array $ids): array => array_intersect_key($this->tags, array_flip($ids))
        );
        return $mapper;
    }

    private function ctx(array $options = [], string $outputFolder = '', ?string $providerId = null, ?\Closure $requestOptions = null): BatchContext {
        return new BatchContext(
            userId: 'bob',
            operation: isset($options['targetLanguage']) ? new TranslateOperation() : new SummarizeOperation(),
            options: $options,
            requestOptions: $requestOptions ?? static fn (LLMProviderInterface $p): array => ['context' => 'task'],
            providerId: $providerId,
            outputFolder: $outputFolder,
            usageType: 'flow',
        );
    }

    private function parent(): Folder {
        $parent = $this->createStub(Folder::class);
        $parent->method('nodeExists')->willReturnCallback(fn (string $n): bool => isset($this->written[$n]));
        $parent->method('newFile')->willReturnCallback(function (string $n, $c = null) {
            $this->written[$n] = (string)$c;
            $out = $this->createStub(File::class);
            $out->method('getId')->willReturn(900 + count($this->written));
            return $out;
        });
        return $parent;
    }

    private function file(int $id, string $name, string $content, string $mime = 'text/plain', ?Folder $parent = null): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/bob/files/In/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMTime')->willReturn(100);
        $file->method('getParent')->willReturn($parent ?? $this->parent());
        return $file;
    }

    private function noProgress(): callable {
        return static function (int $p, int $t): void {
        };
    }

    public function testASingleFileRootIsCollectedWhenItMatches(): void {
        $file = $this->file(5, 'a.txt', 'body');
        $this->assertSame([$file], $this->runner->collect($this->ctx(), $file));

        $image = $this->file(6, 'a.jpg', 'body', 'image/jpeg');
        $this->assertSame([], $this->runner->collect($this->ctx(), $image));
    }

    public function testTheWalkStopsAtTheCap(): void {
        $files = [];
        for ($i = 0; $i < BatchDocumentRunner::MAX_ITEMS_PER_RUN + 5; $i++) {
            $files[] = $this->file(100 + $i, "d$i.txt", 'body');
        }
        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($files);

        $this->assertCount(BatchDocumentRunner::MAX_ITEMS_PER_RUN, $this->runner->collect($this->ctx(), $folder));
    }

    /** The prompt, the request options and the provider all come from the context. */
    public function testProcessTakesEverythingFromTheContext(): void {
        $captured = null;
        $this->provider->method('submitBatch')->willReturnCallback(function (array $requests, ?string $uid) use (&$captured): string {
            $captured = [$requests, $uid];
            return 'batch_X';
        });

        $result = $this->runner->process(
            $this->ctx(['targetLanguage' => 'German'], '', 'anthropic', static fn (): array => ['context' => 'task', 'effort' => 'low']),
            new CoworkerRun(),
            [$this->file(7, 'a.txt', 'hallo')],
            $this->noProgress(),
        );

        $this->assertSame([['anthropic', 'bob']], $this->providerCalls);
        $this->assertSame('bob', $captured[1]);
        $this->assertStringContainsString('into German', $captured[0][0]['messages'][0]['content']);
        $this->assertSame(['context' => 'task', 'effort' => 'low'], $captured[0][0]['options']);
        $this->assertSame(['targetLanguage' => 'German'], $result['state']['options']);
        $this->assertTrue($result['pending']);
    }

    /** Resume writes with the context's operation, folder and usage type. */
    public function testResumeWritesIntoTheContextsOutputFolder(): void {
        $source = $this->file(7, 'a.txt', 'body');
        $this->userFolder->method('getById')->willReturn([$source]);
        $out = $this->parent();
        $this->userFolder->method('get')->willReturnCallback(fn (string $p) => $p === '/Out' ? $out : throw new \OCP\Files\NotFoundException());

        $this->provider->method('getBatchStatus')->willReturn(['status' => 'ended', 'ended' => true, 'counts' => []]);
        $this->provider->method('fetchBatchResults')->willReturn(['f7' => ['response' => 'Übersetzung', 'usage' => []]]);

        $run = new CoworkerRun();
        $result = $this->runner->resume(
            $this->ctx(['targetLanguage' => 'German'], '/Out'),
            $run,
            ['batch_id' => 'batch_X', 'file_ids' => ['f7' => 7], 'submitted_at' => time()],
            $this->noProgress(),
        );

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertSame(['a.txt.german.md' => 'Übersetzung'], $this->written);
        $this->assertSame('flow', $this->usageRecorded[0][3]);
        $this->assertSame('Translated', $run->getDecodedLog()[0]['message']);
        $this->assertSame('/In/a.txt', $run->getDecodedLog()[0]['item']);
    }

    /**
     * Every output carries the hidden output tag, and a walk skips it, so a
     * translation run over a folder never translates its summaries.
     */
    public function testOutputsAreTaggedAndNeverCollectedByAnotherOperation(): void {
        $this->provider = $this->createStub(ClaudeSDKService::class);
        $this->provider->method('supportsBatch')->willReturn(false);
        $this->provider->method('ask')->willReturn(['response' => 'a summary', 'usage' => []]);
        $this->runner->process($this->ctx([], '', null), new CoworkerRun(), [$this->file(7, 'a.txt', 'body')], $this->noProgress());
        $this->assertSame(['901' => ['t:' . BatchDocumentRunner::OUTPUT_TAG]], array_intersect_key($this->tags, ['901' => 1]));

        $summary = $this->file(901, 'a.summary.md', 'a summary', 'text/markdown');
        $source = $this->file(8, 'b.txt', 'body');
        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$summary, $source]);

        $collected = $this->runner->collect($this->ctx(['targetLanguage' => 'German']), $folder);

        $this->assertSame([$source], $collected);
    }

    /** Folder with the given sources; outputs land in $this->written, existing ones have mtime 500. */
    private function sharedFolder(): Folder {
        $folder = $this->createStub(Folder::class);
        $folder->method('nodeExists')->willReturnCallback(fn (string $n): bool => isset($this->written[$n]));
        $folder->method('get')->willReturnCallback(function (string $n): File {
            $existing = $this->createStub(File::class);
            $existing->method('getMTime')->willReturn(500);
            $existing->method('getPath')->willReturn('/bob/files/In/' . $n);
            $existing->method('putContent')->willReturnCallback(function ($c) use ($n): void {
                $this->written[$n] = (string)$c;
            });
            return $existing;
        });
        $folder->method('newFile')->willReturnCallback(function (string $n, $c = null): File {
            $this->written[$n] = (string)$c;
            $out = $this->createStub(File::class);
            $out->method('getId')->willReturn(900 + count($this->written));
            $out->method('getPath')->willReturn('/bob/files/In/' . $n);
            return $out;
        });
        return $folder;
    }

    private function answerSynchronously(): void {
        $this->provider = $this->createStub(LLMProviderInterface::class);
        $this->provider->method('getId')->willReturn('local');
        $this->provider->method('ask')->willReturnCallback(
            fn (string $prompt): array => ['response' => 'S:' . substr($prompt, -5), 'usage' => []]
        );
    }

    /** Sources that differ only in their extension each get their own output, and both are then done. */
    public function testSourcesSharingABaseNameDoNotShareAnOutput(): void {
        $this->answerSynchronously();
        $folder = $this->sharedFolder();
        $md = $this->file(7, 'report.md', 'alpha', 'text/markdown', $folder);
        $txt = $this->file(8, 'report.txt', 'bravo', 'text/plain', $folder);
        $folder->method('getDirectoryListing')->willReturn([$md, $txt]);

        $result = $this->runner->process($this->ctx(), new CoworkerRun(), [$md, $txt], $this->noProgress());

        $this->assertSame(2, $result['itemsProcessed']);
        $this->assertSame(['report.md.summary.md' => 'S:alpha', 'report.txt.summary.md' => 'S:bravo'], $this->written);
        $this->assertSame([], $this->runner->collect($this->ctx(), $folder));
    }

    /** An output from before the extension was kept is still the one source's output. */
    public function testAnUnambiguousLegacyOutputStaysInUse(): void {
        $this->answerSynchronously();
        $folder = $this->sharedFolder();
        $this->written['report.summary.md'] = 'old';
        $txt = $this->file(8, 'report.txt', 'bravo', 'text/plain', $folder);
        $image = $this->file(9, 'report.jpg', 'x', 'image/jpeg', $folder);
        $folder->method('getDirectoryListing')->willReturn([$txt, $image]);

        $this->assertSame([], $this->runner->collect($this->ctx(), $folder), 'fresh: not billed again');

        $this->runner->process($this->ctx(['force' => true]), new CoworkerRun(), [$txt], $this->noProgress());
        $this->assertSame(['report.summary.md' => 'S:bravo'], $this->written, 'kept up to date where it is');
    }

    /** A legacy output two sources may have written counts for neither: both are done again. */
    public function testAnAmbiguousLegacyOutputCountsForNoSource(): void {
        $this->answerSynchronously();
        $folder = $this->sharedFolder();
        $this->written['report.summary.md'] = 'whichever came last';
        $md = $this->file(7, 'report.md', 'alpha', 'text/markdown', $folder);
        $txt = $this->file(8, 'report.txt', 'bravo', 'text/plain', $folder);
        $folder->method('getDirectoryListing')->willReturn([$md, $txt]);

        $collected = $this->runner->collect($this->ctx(), $folder);
        $this->assertSame([$md, $txt], $collected);

        $this->runner->process($this->ctx(), new CoworkerRun(), $collected, $this->noProgress());
        $this->assertSame([
            'report.summary.md' => 'whichever came last',
            'report.md.summary.md' => 'S:alpha',
            'report.txt.summary.md' => 'S:bravo',
        ], $this->written);
    }

    /** `foo.md`'s output name is `foo.md.txt`'s legacy one, so it is never taken for the latter's. */
    public function testALegacyNameThatIsAnotherSourcesOutputIsNotUsed(): void {
        $folder = $this->sharedFolder();
        $this->written['foo.md.summary.md'] = 'of foo.md';
        $md = $this->file(7, 'foo.md', 'alpha', 'text/markdown', $folder);
        $txt = $this->file(8, 'foo.md.txt', 'bravo', 'text/plain', $folder);
        $folder->method('getDirectoryListing')->willReturn([$md, $txt]);

        $this->assertSame([$txt], $this->runner->collect($this->ctx(), $folder));
    }

    public function testSharedOptionsAreValidatedWithoutAnOperation(): void {
        BatchDocumentRunner::validateOptions(['recursive' => false, 'force' => true, 'outputFolder' => '/Out']);
        $this->addToAssertionCount(1);

        foreach ([['recursive' => 'yes'], ['outputFolder' => '/a/../b'], ['maxBytesPerFile' => 1], ['mimeTypes' => []]] as $bad) {
            try {
                BatchDocumentRunner::validateOptions($bad);
                $this->fail(json_encode($bad) . ' should be rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSummarizeRejectsAnUnknownStyle(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new SummarizeOperation())->validateOptions(['style' => 'poem']);
    }
}
