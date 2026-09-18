<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\DocsSummarizeFolderTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the "docs" task family: which files it picks up, what it
 * submits, and how it accounts for results that come back in any order.
 */
class BatchTextTaskTypeTest extends TestCase {
    private $rootFolder;
    private $providerFactory;
    private $tagManager;
    private $tagObjectMapper;
    private $logger;
    private $provider;
    private $userFolder;
    private DocsSummarizeFolderTaskType $task;

    /** @var array<string, string> written output path => content */
    private array $written = [];

    protected function setUp(): void {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->providerFactory = $this->createMock(LLMProviderFactory::class);
        $this->tagManager = $this->createMock(ISystemTagManager::class);
        $this->tagObjectMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // The service is only ever reached through the four batch methods.
        $this->provider = $this->createMock(ClaudeSDKService::class);
        $this->providerFactory->method('getProviderForUser')->willReturn($this->provider);

        $this->userFolder = $this->createMock(Folder::class);
        $this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);

        $this->task = new DocsSummarizeFolderTaskType(
            $this->rootFolder,
            $this->providerFactory,
            $this->tagManager,
            $this->tagObjectMapper,
            $this->logger,
        );
    }

    private function coworker(array $options = []): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('docs:summarize');
        $cw->setCronSchedule('0 2 * * *');
        $cw->setInputPath('/Documents');
        $cw->setOptions(json_encode($options));
        (new \ReflectionProperty($cw, 'id'))->setValue($cw, 7);
        return $cw;
    }

    private function newRun(): CoworkerRun {
        $run = new CoworkerRun();
        $run->setCoworkerId(7);
        $run->setUserId('alice');
        return $run;
    }

    /**
     * A source file in a parent folder that records what gets written to it.
     */
    private function file(int $id, string $name, string $mime, string $content, int $mtime = 100): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/Documents/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getParent')->willReturn($this->outputParent());
        return $file;
    }

    private ?Folder $parent = null;

    private function outputParent(): Folder {
        if ($this->parent !== null) {
            return $this->parent;
        }
        $parent = $this->createMock(Folder::class);
        $parent->method('nodeExists')->willReturnCallback(
            fn (string $name): bool => isset($this->written[$name])
        );
        $parent->method('get')->willReturnCallback(function (string $name) {
            $existing = $this->createMock(File::class);
            $existing->method('getMTime')->willReturn(0);
            $existing->method('putContent')->willReturnCallback(function ($c) use ($name) {
                $this->written[$name] = $c;
            });
            return $existing;
        });
        $parent->method('newFile')->willReturnCallback(function (string $name, $content = null) {
            $this->written[$name] = (string)$content;
            return $this->createMock(File::class);
        });
        $this->parent = $parent;
        return $parent;
    }

    /** @param list<File> $files */
    private function inputFolder(array $files): void {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturn($files);
        $this->userFolder->method('get')->willReturn($folder);
    }

    private function progress(): callable {
        return static function (int $processed, int $total): void {
        };
    }

    // ── Submission ────────────────────────────────────────────────────────

    public function testRunSubmitsOneBatchForTheWholeFolder(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'first body'),
            $this->file(12, 'b.md', 'text/markdown', 'second body'),
        ]);

        $captured = null;
        $this->provider->expects($this->once())
            ->method('submitBatch')
            ->willReturnCallback(function (array $requests) use (&$captured): string {
                $captured = $requests;
                return 'batch_01ABC';
            });

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertTrue($result['pending']);
        $this->assertSame(2, $result['itemsTotal']);
        $this->assertSame(0, $result['itemsProcessed']);
        $this->assertSame('batch_01ABC', $result['state']['batch_id']);
        // custom_id has to carry the identity of the file, because results
        // come back keyed by it and in no particular order.
        $this->assertSame(['f11' => 11, 'f12' => 12], $result['state']['file_ids']);
        $this->assertCount(2, $captured);
        $this->assertStringContainsString('first body', $captured[0]['messages'][0]['content']);
    }

    public function testNonMatchingMimeTypesAreIgnored(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'photo.jpg', 'image/jpeg', 'binary'),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
    }

    /** Half a document summarised as if it were the whole one is worse than none. */
    public function testOversizedFilesAreSkippedRatherThanTruncated(): void {
        $this->inputFolder([
            $this->file(11, 'small.txt', 'text/plain', 'body'),
            $this->file(12, 'huge.txt', 'text/plain', str_repeat('x', 5000)),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(['maxBytesPerFile' => 2048]), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
        $this->assertSame(['huge.txt'], $result['state']['skipped']);
    }

    /**
     * These coworkers run nightly; without this, every run would re-bill the
     * whole folder.
     */
    public function testFilesWithAFresherOutputAreSkipped(): void {
        $this->written['done.summary.md'] = 'an earlier summary';
        $this->inputFolder([
            // mtime 0 so the existing output (mtime 0) is not stale.
            $this->file(11, 'done.txt', 'text/plain', 'body', 0),
            $this->file(12, 'new.txt', 'text/plain', 'body'),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(['f12' => 12], $result['state']['file_ids']);
    }

    public function testForceReprocessesFilesThatAlreadyHaveOutput(): void {
        $this->written['done.summary.md'] = 'an earlier summary';
        $this->inputFolder([$this->file(11, 'done.txt', 'text/plain', 'body', 0)]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(['force' => true]), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
    }

    public function testAnEmptyFolderFinishesWithoutSubmittingAnything(): void {
        $this->inputFolder([]);
        $this->provider->expects($this->never())->method('submitBatch');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertArrayNotHasKey('pending', $result);
        $this->assertSame(0, $result['itemsTotal']);
    }

    // ── Resumption ────────────────────────────────────────────────────────

    private function state(array $fileIds, ?int $submittedAt = null): array {
        return [
            'batch_id' => 'batch_01ABC',
            'file_ids' => $fileIds,
            'submitted_at' => $submittedAt ?? time(),
            'skipped' => [],
            'options' => [],
        ];
    }

    public function testResumeStaysPendingWhileTheBatchIsStillRunning(): void {
        $this->provider->method('getBatchStatus')->willReturn([
            'status' => 'in_progress',
            'ended' => false,
            'counts' => ['processing' => 2, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
        ]);
        $this->provider->expects($this->never())->method('fetchBatchResults');

        $result = $this->task->resume($this->coworker(), $this->newRun(), $this->state(['f11' => 11]), $this->progress());

        $this->assertTrue($result['pending']);
        $this->assertSame([], $this->written);
    }

    /** Anthropic expires a batch at 24 h; past that nothing will ever end the run. */
    public function testResumeFailsPastTheBatchDeadline(): void {
        $this->provider->method('getBatchStatus')->willReturn([
            'status' => 'in_progress',
            'ended' => false,
            'counts' => ['processing' => 1, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not end within 26 hours');
        $this->task->resume(
            $this->coworker(),
            $this->newRun(),
            $this->state(['f11' => 11], time() - 27 * 3600),
            $this->progress()
        );
    }

    /**
     * Results are fed back in the reverse of submission order — the only way
     * to catch a positional read pairing a file with another file's summary.
     */
    public function testResumeWritesOneSiblingPerResultRegardlessOfOrder(): void {
        $a = $this->file(11, 'a.txt', 'text/plain', 'body');
        $b = $this->file(12, 'b.txt', 'text/plain', 'body');
        $this->userFolder->method('getById')->willReturnCallback(
            fn (int $id): array => [$id === 11 ? $a : $b]
        );

        $this->provider->method('getBatchStatus')->willReturn([
            'status' => 'ended',
            'ended' => true,
            'counts' => ['processing' => 0, 'succeeded' => 2, 'errored' => 0, 'canceled' => 0, 'expired' => 0],
        ]);
        $this->provider->method('fetchBatchResults')->willReturn([
            'f12' => ['response' => 'summary of b', 'usage' => [], 'citations' => []],
            'f11' => ['response' => 'summary of a', 'usage' => [], 'citations' => []],
        ]);

        $result = $this->task->resume(
            $this->coworker(),
            $this->newRun(),
            $this->state(['f11' => 11, 'f12' => 12]),
            $this->progress()
        );

        $this->assertSame(2, $result['itemsProcessed']);
        $this->assertSame('summary of a', $this->written['a.summary.md']);
        $this->assertSame('summary of b', $this->written['b.summary.md']);
    }

    public function testResumeCountsEachFailureKindSeparately(): void {
        $files = [
            11 => $this->file(11, 'ok.txt', 'text/plain', 'body'),
            12 => $this->file(12, 'refused.txt', 'text/plain', 'body'),
            13 => $this->file(13, 'errored.txt', 'text/plain', 'body'),
            14 => $this->file(14, 'gone.txt', 'text/plain', 'body'),
        ];
        $this->userFolder->method('getById')->willReturnCallback(
            fn (int $id): array => [$files[$id]]
        );

        $this->provider->method('getBatchStatus')->willReturn([
            'status' => 'ended',
            'ended' => true,
            'counts' => ['processing' => 0, 'succeeded' => 2, 'errored' => 1, 'canceled' => 0, 'expired' => 0],
        ]);
        $this->provider->method('fetchBatchResults')->willReturn([
            'f11' => ['response' => 'a summary', 'usage' => [], 'citations' => []],
            'f12' => ['error' => 'Declined by policy', 'error_type' => 'refused'],
            'f13' => ['error' => 'invalid_request_error: too long', 'error_type' => 'errored'],
            // f14 deliberately absent — a request with no result at all.
        ]);

        $result = $this->task->resume(
            $this->coworker(),
            $this->newRun(),
            $this->state(['f11' => 11, 'f12' => 12, 'f13' => 13, 'f14' => 14]),
            $this->progress()
        );

        $this->assertSame(4, $result['itemsTotal']);
        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertStringContainsString('1 refused', $result['summary']);
        $this->assertStringContainsString('1 errored', $result['summary']);
        $this->assertStringContainsString('1 missing', $result['summary']);
        $this->assertStringContainsString('Refused: refused.txt', $result['summary']);
        $this->assertArrayNotHasKey('pending', $result);
    }

    public function testResumeWithoutABatchIdFails(): void {
        $this->expectException(\RuntimeException::class);
        $this->task->resume($this->coworker(), $this->newRun(), [], $this->progress());
    }

    // ── Regressions ───────────────────────────────────────────────────────

    /**
     * A .summary.md is text and lands in the tree being walked. Left in, each
     * run feeds the last run's output back through and grows another
     * generation — a.summary.summary.md, then a.summary.summary.summary.md.
     */
    public function testOwnOutputIsNotPickedUpAsInput(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'a.summary.md', 'text/markdown', 'an earlier summary'),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
    }

    /**
     * The shipped summarize template writes to /Documents/Summaries while
     * reading /Documents recursively, so the output folder is inside its own
     * input unless the walk skips it.
     */
    public function testTheConfiguredOutputFolderIsExcludedFromTheWalk(): void {
        $outputFolder = $this->createMock(Folder::class);
        $outputFolder->method('getId')->willReturn(99);
        $outputFolder->method('getDirectoryListing')->willReturn([
            $this->file(12, 'stale.md', 'text/markdown', 'output from an earlier run'),
        ]);

        $input = $this->createMock(Folder::class);
        $input->method('getDirectoryListing')->willReturn([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $outputFolder,
        ]);

        $this->userFolder->method('get')->willReturnCallback(
            fn (string $path): Folder => $path === '/Documents' ? $input : $outputFolder
        );
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run(
            $this->coworker(['outputFolder' => '/Documents/Summaries']),
            $this->newRun(),
            $this->progress()
        );

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
    }

    /**
     * The walk stops at MAX_ITEMS_PER_RUN. If already-finished files are
     * filtered out only afterwards, the first 200 fill the quota every night
     * and file 201 is never reached on any run.
     */
    public function testFinishedFilesDoNotConsumeTheRunQuota(): void {
        $files = [];
        for ($i = 0; $i < 250; $i++) {
            $name = sprintf('doc%03d', $i);
            // The first 240 already have a fresh summary.
            if ($i < 240) {
                $this->written[$name . '.summary.md'] = 'done';
            }
            $files[] = $this->file(1000 + $i, $name . '.txt', 'text/plain', 'body', $i < 240 ? 0 : 100);
        }
        $this->inputFolder($files);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        // The 10 unfinished ones must all be reached, not starved behind 200
        // finished ones.
        $this->assertSame(10, $result['itemsTotal']);
        $this->assertArrayHasKey('f1240', $result['state']['file_ids']);
        $this->assertArrayHasKey('f1249', $result['state']['file_ids']);
    }

    /**
     * A batch is submitted as one request for the whole folder, outside any
     * per-file guard, so one binary file in the payload would take the entire
     * run down rather than just itself.
     */
    public function testNonTextContentIsSkippedRatherThanInlined(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'weird.txt', 'text/plain', "\xff\xfe\x00binary"),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
        $this->assertSame(['weird.txt'], $result['state']['skipped']);
    }

    /** PDF bytes are not text; nothing here builds a document content block. */
    public function testPdfsAreNotCollectedByDefault(): void {
        $this->inputFolder([
            $this->file(11, 'a.txt', 'text/plain', 'body'),
            $this->file(12, 'report.pdf', 'application/pdf', '%PDF-1.4 binary'),
        ]);
        $this->provider->method('submitBatch')->willReturn('batch_01ABC');

        $result = $this->task->run($this->coworker(), $this->newRun(), $this->progress());

        $this->assertSame(['f11' => 11], $result['state']['file_ids']);
    }
}
