<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\BackgroundJob\BatchRunJob;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\BatchRunService;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Exception\BatchRunLimitException;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\UsageStatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Manual batch runs end to end through the real BatchDocumentRunner, with
 * Files, the provider and the database stubbed.
 */
class BatchRunServiceTest extends TestCase {
    private $provider;
    private $userFolder;
    private $mapper;
    private $jobs;
    private BatchRunService $service;

    /** @var array<int, \OCP\Files\Node> the user's view, by file id */
    private array $nodes = [];
    /** @var array<string, string> written output name => content */
    private array $written = [];
    /** @var array<int, CoworkerRun> the run table */
    private array $rows = [];
    private int $recentRuns = 0;
    /** @var list<array{0: string, 1: mixed}> */
    private array $jobAdds = [];

    protected function setUp(): void {
        $this->provider = $this->createStub(ClaudeSDKService::class);
        $this->provider->method('supportsBatch')->willReturn(true);
        $this->provider->method('getId')->willReturn('anthropic');
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturnCallback(fn () => $this->provider);

        $this->userFolder = $this->createStub(Folder::class);
        $this->userFolder->method('getById')->willReturnCallback(
            fn (int $id): array => isset($this->nodes[$id]) ? [$this->nodes[$id]] : []
        );
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);

        $this->mapper = $this->createStub(CoworkerRunMapper::class);
        $this->mapper->method('insert')->willReturnCallback(function (CoworkerRun $run): CoworkerRun {
            $run->setId(count($this->rows) + 1);
            $this->rows[$run->getId()] = $run;
            return $run;
        });
        $this->mapper->method('update')->willReturnCallback(fn (CoworkerRun $run): CoworkerRun => $run);
        $this->mapper->method('findById')->willReturnCallback(fn (int $id): CoworkerRun => $this->rows[$id]);
        $this->mapper->method('findManual')->willReturnCallback(fn (int $id): CoworkerRun => $this->rows[$id]);
        $this->mapper->method('findStatus')->willReturnCallback(fn (int $id): ?string => $this->rows[$id]->getStatus());
        $this->mapper->method('countManualSince')->willReturnCallback(fn (): int => $this->recentRuns);
        $this->mapper->method('claimQueued')->willReturnCallback(function (int $id): bool {
            if (($this->rows[$id] ?? null)?->getStatus() !== CoworkerRun::STATUS_QUEUED) {
                return false;
            }
            $this->rows[$id]->setStatus(CoworkerRun::STATUS_RUNNING);
            return true;
        });

        $this->jobs = $this->createStub(IJobList::class);
        $this->jobs->method('add')->willReturnCallback(function (string $job, mixed $arg): void {
            $this->jobAdds[] = [$job, $arg];
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_800_000_000);

        $logger = $this->createStub(LoggerInterface::class);
        $runner = new BatchDocumentRunner(
            $root,
            $factory,
            $this->createStub(ISystemTagManager::class),
            $this->createStub(ISystemTagObjectMapper::class),
            $logger,
            $this->createStub(UsageStatService::class),
        );

        $this->service = new BatchRunService($this->mapper, $runner, $root, $factory, $this->jobs, $time, $logger);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private ?Folder $parent = null;

    /** The folder every source sits in; records what is written to it. */
    private function parent(bool $writable = true): Folder {
        if ($this->parent !== null) {
            return $this->parent;
        }
        $parent = $this->createStub(Folder::class);
        $parent->method('isCreatable')->willReturn($writable);
        $parent->method('nodeExists')->willReturnCallback(fn (string $n): bool => isset($this->written[$n]));
        $parent->method('get')->willReturnCallback(function (string $n) {
            $existing = $this->createStub(File::class);
            $existing->method('getMTime')->willReturn(500);
            return $existing;
        });
        $parent->method('newFile')->willReturnCallback(function (string $n, $c = null) {
            $this->written[$n] = (string)$c;
            $out = $this->createStub(File::class);
            $out->method('getPath')->willReturn('/alice/files/Docs/' . $n);
            return $out;
        });
        return $this->parent = $parent;
    }

    private function file(int $id, string $name, string $content = 'body', string $mime = 'text/plain', bool $readable = true): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/alice/files/Docs/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMTime')->willReturn(100);
        $file->method('isReadable')->willReturn($readable);
        $file->method('getParent')->willReturn($this->parent());
        return $this->nodes[$id] = $file;
    }

    /** @param list<\OCP\Files\Node> $children */
    private function folder(int $id, array $children): Folder {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('isReadable')->willReturn(true);
        $folder->method('getDirectoryListing')->willReturn($children);
        return $this->nodes[$id] = $folder;
    }

    private function start(array $fileIds, array $folderIds = [], ?string $mode = null, string $op = 'summarize', array $options = []): CoworkerRun {
        return $this->service->start('alice', $op, $options, $mode, $fileIds, $folderIds);
    }

    // ── Start: input checks ───────────────────────────────────────────────

    public function testStartQueuesTheRunAndItsJob(): void {
        $this->file(11, 'a.txt');
        $run = $this->start([11]);

        $this->assertSame([[BatchRunJob::class, ['runId' => 1]]], $this->jobAdds);
        $this->assertSame(CoworkerRun::STATUS_QUEUED, $run->getStatus());
        $this->assertSame(0, $run->getCoworkerId());
        $this->assertSame(CoworkerRun::STARTED_BY_SELECTION, $run->getStartedBy());
        $this->assertSame('summarize', $run->getTaskType());
        // Batch is the default where the provider has a batch API.
        $this->assertSame('batch', $run->getDecodedState()['queued']['mode']);
        $this->assertSame(30, BatchRunService::serialize($run)['pollIntervalSeconds']);
    }

    public function testAnUnknownOperationIsRejected(): void {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageIsOrContains('operation must be one of: summarize, translate');
        $this->start([11], [], null, 'poem');
    }

    public function testOperationOptionsAreValidated(): void {
        $this->file(11, 'a.txt');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageIsOrContains('targetLanguage is required');
        $this->start([11], [], null, 'translate');
    }

    public function testAnEmptySelectionIsRejected(): void {
        $this->expectException(ValidationException::class);
        $this->start([]);
    }

    /** One answer for "does not exist" and "not readable", so ids cannot be probed. */
    public function testUnreadableAndUnknownIdsAreNotFoundAlike(): void {
        $this->file(12, 'secret.txt', 'body', 'text/plain', false);
        foreach ([12, 999] as $id) {
            try {
                $this->start([$id]);
                $this->fail("$id should not be accepted");
            } catch (NotFoundException $e) {
                $this->assertSame("File $id not found", $e->getMessage());
            }
        }
    }

    public function testAFolderPassedAsAFileIsRejected(): void {
        $this->folder(20, []);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageIsOrContains('20 is a folder; pass it in folderIds');
        $this->start([20]);
    }

    public function testMoreIdsThanTheCapAreRejectedNamingTheLimit(): void {
        try {
            $this->start(range(1, BatchRunService::MAX_ITEMS + 1));
            $this->fail('over the cap');
        } catch (BatchRunLimitException $e) {
            $this->assertSame(BatchRunLimitException::ITEMS, $e->kind);
            $this->assertSame(200, $e->limit);
            $this->assertStringContainsString('at most 200 files', $e->getMessage());
        }
    }

    public function testTheHourlyCapIsEnforced(): void {
        $this->file(11, 'a.txt');
        $this->recentRuns = BatchRunService::MAX_RUNS_PER_HOUR;
        try {
            $this->start([11]);
            $this->fail('over the hourly cap');
        } catch (BatchRunLimitException $e) {
            $this->assertSame(BatchRunLimitException::RATE, $e->kind);
            $this->assertSame(12, $e->limit);
        }
    }

    public function testAnOutputFolderThatIsNotWritableIsRejected(): void {
        $this->file(11, 'a.txt');
        $readOnly = $this->createStub(Folder::class);
        $readOnly->method('isCreatable')->willReturn(false);
        $this->userFolder->method('get')->willReturn($readOnly);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageIsOrContains('is not a folder you can write to');
        $this->start([11], [], null, 'summarize', ['outputFolder' => '/Shared/RO']);
    }

    // ── Execute ───────────────────────────────────────────────────────────

    /**
     * A file picked by hand is processed even with fresh output; the same
     * rule in a folder skips it. A file reached twice counts once, and a
     * picked file that is not text is skipped with a reason.
     */
    public function testMixedSelectionOfFilesAndFolders(): void {
        $picked = $this->file(11, 'picked.txt');
        $this->written['picked.summary.md'] = 'fresh';           // mtime 500 > 100
        $inFolder = $this->file(12, 'new.txt');
        $this->written['done.summary.md'] = 'fresh';
        $done = $this->file(13, 'done.txt');
        $this->file(14, 'photo.jpg', 'x', 'image/jpeg');
        $this->folder(20, [$inFolder, $done, $picked]);

        $captured = null;
        $this->provider->method('submitBatch')->willReturnCallback(function (array $requests) use (&$captured): string {
            $captured = $requests;
            return 'batch_M';
        });

        $run = $this->start([11, 14], [20]);
        $this->service->execute($run->getId());

        $this->assertSame(['f11', 'f12'], array_column($captured, 'custom_id'));
        $this->assertSame(CoworkerRun::STATUS_PENDING, $run->getStatus());
        $this->assertSame('batch_M', $run->getPendingBatchId());
        $this->assertSame(2, $run->getItemsTotal());
        $log = $run->getDecodedLog();
        $this->assertContains(['Skipped: Not a text document', '/Docs/photo.jpg'], array_map(fn ($e) => [$e['message'], $e['item']], $log));
        $this->assertSame(300, BatchRunService::serialize($run)['pollIntervalSeconds']);
    }

    public function testImmediateModeWorksThroughTheFilesAndRecordsOutputs(): void {
        $this->file(11, 'a.txt', 'alpha');
        $this->file(12, 'b.txt', 'beta');
        $this->provider->method('ask')->willReturnCallback(fn (string $prompt): array => ['response' => 'S:' . substr($prompt, -5), 'usage' => []]);
        $this->provider->method('submitBatch')->willThrowException(new \LogicException('immediate must not batch'));

        $run = $this->start([11, 12], [], 'immediate');
        $this->service->execute($run->getId());

        $this->assertSame(CoworkerRun::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(2, $run->getItemsProcessed());
        $this->assertSame(['/Docs/a.summary.md', '/Docs/b.summary.md'], $run->getDecodedOutputs());
        $this->assertSame('S:alpha', $this->written['a.summary.md']);
        $this->assertStringContainsString('immediate — full price', (string)$run->getSummary());
        $this->assertNull($run->getState());
        $this->assertNull(BatchRunService::serialize($run)['pollIntervalSeconds']);
    }

    /** A source in a read-only folder is skipped before anything is billed for it. */
    public function testASourceWhoseFolderIsReadOnlyIsSkippedUpFront(): void {
        $this->parent(false);
        $this->file(11, 'a.txt');
        $this->provider->method('ask')->willThrowException(new \LogicException('must not be billed'));

        $run = $this->start([11], [], 'immediate');
        $this->service->execute($run->getId());

        $this->assertSame(CoworkerRun::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(0, $run->getItemsTotal());
        $this->assertStringContainsString('read-only', $run->getDecodedLog()[1]['message']);
    }

    public function testAFileDeletedBeforeTheRunStartsIsLogged(): void {
        $this->file(11, 'a.txt');
        $run = $this->start([11]);
        unset($this->nodes[11]);

        $this->service->execute($run->getId());

        $this->assertSame(CoworkerRun::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame('file 11', $run->getDecodedLog()[1]['item']);
    }

    public function testACancelledQueuedRunNeverStarts(): void {
        $this->file(11, 'a.txt');
        $this->provider->method('submitBatch')->willThrowException(new \LogicException('must not start'));

        $run = $this->start([11]);
        $this->service->cancel($run->getId(), 'alice');
        $this->service->execute($run->getId());

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
    }

    // ── Resume ────────────────────────────────────────────────────────────

    public function testResumeAfterTheBatchEndsWritesOutputsAndFinishes(): void {
        $this->file(11, 'a.txt');
        $this->file(12, 'b.txt');
        $this->provider->method('submitBatch')->willReturn('batch_R');
        $this->provider->method('getBatchStatus')->willReturnOnConsecutiveCalls(
            ['status' => 'in_progress', 'ended' => false, 'counts' => []],
            ['status' => 'ended', 'ended' => true, 'counts' => []],
        );
        $this->provider->method('fetchBatchResults')->willReturn([
            'f12' => ['response' => 'sum b', 'usage' => []],
            'f11' => ['error' => 'Declined', 'error_type' => 'refused'],
        ]);

        $run = $this->start([11, 12], [], null, 'summarize', ['style' => 'bullets']);
        $this->service->execute($run->getId());
        $this->assertSame(CoworkerRun::STATUS_PENDING, $run->getStatus());

        $this->service->resume($run);
        $this->assertSame(CoworkerRun::STATUS_PENDING, $run->getStatus(), 'still running');

        $this->service->resume($run);
        $this->assertSame(CoworkerRun::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(1, $run->getItemsProcessed());
        $this->assertSame(1, $run->getItemsFailed());
        $this->assertSame('1 of 2 item(s) failed', $run->getError());
        $this->assertSame(['/Docs/b.summary.md'], $run->getDecodedOutputs());
        $this->assertSame(['b.summary.md' => 'sum b'], $this->written);
    }

    // ── Cancel ────────────────────────────────────────────────────────────

    public function testCancellingAPendingRunCancelsItsBatch(): void {
        $this->file(11, 'a.txt');
        $this->provider = $this->createMock(ClaudeSDKService::class);
        $this->provider->method('supportsBatch')->willReturn(true);
        $this->provider->method('submitBatch')->willReturn('batch_C');
        $this->provider->expects($this->once())->method('cancelBatch')->with('batch_C', 'alice')->willReturn(true);

        $run = $this->start([11]);
        $this->service->execute($run->getId());
        $this->service->cancel($run->getId(), 'alice');

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame('Batch batch_C cancelled', $run->getDecodedLog()[array_key_last($run->getDecodedLog())]['message']);
    }

    public function testAnEndedRunCannotBeCancelled(): void {
        $this->file(11, 'a.txt');
        $run = $this->start([11]);
        $run->setStatus(CoworkerRun::STATUS_SUCCESS);

        $this->expectException(NoActiveRunException::class);
        $this->service->cancel($run->getId(), 'alice');
    }
}
