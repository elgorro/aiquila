<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\Exception\NoActiveRunException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stopping a coworker's run in flight: marked here, honoured by the worker
 * at its next item, and never overwritten by a late success.
 */
class CoworkerServiceStopTest extends TestCase {
    private const NOW = 1_700_000_000;

    private $mapper;
    private $runMapper;
    private $registry;
    private $providerFactory;
    /** Status the database holds for the run, as the worker reads it fresh. */
    private ?string $storedStatus = CoworkerRun::STATUS_RUNNING;

    protected function setUp(): void {
        $this->mapper = $this->createStub(CoworkerMapper::class);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->runMapper = $this->createStub(CoworkerRunMapper::class);
        $this->runMapper->method('insert')->willReturnCallback(function (CoworkerRun $run): CoworkerRun {
            (new \ReflectionProperty($run, 'id'))->setValue($run, 42);
            return $run;
        });
        $this->runMapper->method('update')->willReturnArgument(0);
        $this->runMapper->method('findStatus')->willReturnCallback(fn () => $this->storedStatus);
        $this->registry = $this->createStub(CoworkerTaskRegistry::class);
        $this->providerFactory = $this->createStub(LLMProviderFactory::class);
    }

    private function service(): CoworkerService {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new CoworkerService(
            $this->mapper,
            $this->runMapper,
            $this->registry,
            $this->providerFactory,
            $time,
            $this->createStub(LoggerInterface::class),
        );
    }

    private function coworker(): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('docs:summarize');
        $cw->setCronSchedule('0 3 * * *');
        $cw->setIsActive(true);
        $cw->setNextRunAt(self::NOW - 60); // overdue while its run was open
        (new \ReflectionProperty($cw, 'id'))->setValue($cw, 7);
        return $cw;
    }

    private function activeRun(string $status, array $state = []): CoworkerRun {
        $run = new CoworkerRun();
        $run->setCoworkerId(7);
        $run->setUserId('alice');
        $run->setStatus($status);
        $run->setItemsTotal(10);
        $run->setItemsProcessed(4);
        $run->setDecodedState($state);
        (new \ReflectionProperty($run, 'id'))->setValue($run, 42);
        return $run;
    }

    public function testNothingInFlightIsItsOwnNotFound(): void {
        $this->runMapper->method('findActiveForCoworker')->willReturn(null);

        $this->expectException(NoActiveRunException::class);
        $this->service()->stopEntity($this->coworker());
    }

    public function testARunningRunIsMarkedAndTheScheduleLeftToItsWorker(): void {
        $this->runMapper->method('findActiveForCoworker')->willReturn($this->activeRun(CoworkerRun::STATUS_RUNNING));
        $coworker = $this->coworker();

        $run = $this->service()->stopEntity($coworker);

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame(self::NOW, $run->getFinishedAt());
        $this->assertSame(4, $run->getItemsProcessed(), 'progress so far is kept');
        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $coworker->getLastStatus());
        $this->assertSame(1, $coworker->getIsActive(), 'stopping a run does not disable the coworker');
        $this->assertSame(self::NOW - 60, $coworker->getNextRunAt(), 'the worker closing the run reschedules');
    }

    /**
     * No worker will close a pending run, so stop() cancels the batch and
     * moves the schedule on — otherwise the overdue next run starts again
     * on the next tick.
     */
    public function testAPendingRunCancelsItsBatchAndReschedules(): void {
        $this->runMapper->method('findActiveForCoworker')->willReturn($this->activeRun(CoworkerRun::STATUS_PENDING, ['batch_id' => 'msgbatch_1']));
        $provider = $this->createMock(ClaudeSDKService::class);
        $provider->expects($this->once())->method('cancelBatch')->with('msgbatch_1', 'alice')->willReturn(true);
        $this->providerFactory->method('getProviderForUser')->willReturn($provider);
        $coworker = $this->coworker();

        $run = $this->service()->stopEntity($coworker);

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertNull($run->getPendingBatchId(), 'nothing left to resume');
        $this->assertSame(['Cancelled after 4/10 item(s)', 'Batch msgbatch_1 cancelled'], array_column($run->getDecodedLog(), 'message'));
        $this->assertGreaterThan(self::NOW, $coworker->getNextRunAt());
        $this->assertSame(self::NOW, $coworker->getLastRunAt());
    }

    public function testABatchThatCannotBeCancelledIsStillClosed(): void {
        $this->runMapper->method('findActiveForCoworker')->willReturn($this->activeRun(CoworkerRun::STATUS_PENDING, ['batch_id' => 'msgbatch_1']));
        $provider = $this->createStub(ClaudeSDKService::class);
        $provider->method('cancelBatch')->willReturn(false);
        $this->providerFactory->method('getProviderForUser')->willReturn($provider);

        $run = $this->service()->stopEntity($this->coworker());

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame('warn', $run->getDecodedLog()[1]['level']);
    }

    /** A deleted coworker takes its run with it, and the worker must not carry on. */
    public function testTheWorkerStopsWhenItsRunIsGone(): void {
        $task = $this->createStub(CoworkerTaskType::class);
        $task->method('run')->willReturnCallback(function (Coworker $c, CoworkerRun $r, callable $progress): array {
            $progress(1, 3);
            $this->storedStatus = null;
            $progress(2, 3);
            $progress(3, 3);
            return ['itemsTotal' => 3, 'itemsProcessed' => 3, 'summary' => 'all done'];
        });
        $this->registry->method('get')->willReturn($task);

        $run = $this->service()->execute($this->coworker());

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame(2, $run->getItemsProcessed());
    }

    /** The worker sees the stop at its next progress report and ends there. */
    public function testTheWorkerStopsAtItsNextItem(): void {
        $task = $this->createStub(CoworkerTaskType::class);
        $task->method('run')->willReturnCallback(function (Coworker $c, CoworkerRun $r, callable $progress): array {
            $progress(1, 3);
            $this->storedStatus = CoworkerRun::STATUS_CANCELLED; // stop() from another request
            $progress(2, 3);
            $progress(3, 3);
            return ['itemsTotal' => 3, 'itemsProcessed' => 3, 'summary' => 'all done'];
        });
        $this->registry->method('get')->willReturn($task);
        $coworker = $this->coworker();

        $run = $this->service()->execute($coworker);

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame(2, $run->getItemsProcessed());
        $this->assertSame('Cancelled after 2/3 item(s)', $run->getDecodedLog()[0]['message']);
        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $coworker->getLastStatus());
        $this->assertGreaterThan(self::NOW, $coworker->getNextRunAt());
    }

    /** A stop that lands after the last progress report is not overwritten by success. */
    public function testALateStopIsNotOverwrittenBySuccess(): void {
        $task = $this->createStub(CoworkerTaskType::class);
        $task->method('run')->willReturnCallback(function (): array {
            $this->storedStatus = CoworkerRun::STATUS_CANCELLED;
            return ['itemsTotal' => 3, 'itemsProcessed' => 3, 'summary' => 'all done'];
        });
        $this->registry->method('get')->willReturn($task);

        $run = $this->service()->execute($this->coworker());

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
        $this->assertSame(3, $run->getItemsProcessed());
        $this->assertSame('all done', $run->getSummary());
    }

    /**
     * QBMapper never clears an entity's updated-field marks. The worker's
     * progress updates must not carry `status`, or each one would write
     * `running` back over a stop() made by another request.
     */
    public function testProgressUpdatesDoNotWriteTheStatus(): void {
        $written = [];
        $this->runMapper = $this->createStub(CoworkerRunMapper::class);
        $this->runMapper->method('insert')->willReturnCallback(function (CoworkerRun $run): CoworkerRun {
            (new \ReflectionProperty($run, 'id'))->setValue($run, 42);
            return $run;
        });
        $this->runMapper->method('update')->willReturnCallback(function (CoworkerRun $run) use (&$written): CoworkerRun {
            $written[] = array_keys($run->getUpdatedFields());
            return $run;
        });
        $task = $this->createStub(CoworkerTaskType::class);
        $task->method('run')->willReturnCallback(function (Coworker $c, CoworkerRun $r, callable $progress): array {
            $progress(1, 2);
            return ['itemsTotal' => 2, 'itemsProcessed' => 2, 'summary' => 'ok'];
        });
        $this->registry->method('get')->willReturn($task);

        $this->service()->execute($this->coworker());

        $this->assertNotContains('status', $written[0], 'the first progress update must not rewrite the status');
        $this->assertContains('itemsProcessed', $written[0]);
    }

    /** A coworker may only run one of its owner's saved prompts. */
    public function testAnotherUsersPromptIsRefused(): void {
        $prompts = $this->createStub(\OCA\AIquila\Db\PromptMapper::class);
        $prompts->method('findByIdAndUser')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
        $time = $this->createStub(ITimeFactory::class);
        $service = new CoworkerService($this->mapper, $this->runMapper, $this->registry, $this->providerFactory, $time, $this->createStub(LoggerInterface::class), $prompts);
        $this->mapper->method('findByIdAndUser')->willReturn($this->coworker());

        $this->expectException(\OCA\AIquila\Service\Exception\ValidationException::class);
        $this->expectExceptionMessage('Prompt not found: 9');
        $service->update(7, 'alice', ['prompt_id' => 9]);
    }
}
