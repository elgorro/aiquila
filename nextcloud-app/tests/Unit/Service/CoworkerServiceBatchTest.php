<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Cowork\ResumableCoworkerTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The pending-run lifecycle: a task that hands its work to the Batch API
 * cannot finish in the tick that submitted it, so the run stays open and a
 * later tick resumes it.
 */
class CoworkerServiceBatchTest extends TestCase {
    private $mapper;
    private $runMapper;
    private $registry;
    private $providerFactory;
    private $timeFactory;
    private $logger;
    private CoworkerService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(CoworkerMapper::class);
        $this->runMapper = $this->createMock(CoworkerRunMapper::class);
        $this->registry = $this->createMock(CoworkerTaskRegistry::class);
        $this->providerFactory = $this->createMock(LLMProviderFactory::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->timeFactory->method('getTime')->willReturn(1_700_000_000);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->runMapper->method('insert')->willReturnArgument(0);
        $this->runMapper->method('update')->willReturnArgument(0);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->service = new CoworkerService(
            $this->mapper,
            $this->runMapper,
            $this->registry,
            $this->providerFactory,
            $this->timeFactory,
            $this->logger,
        );
    }

    private function coworker(int $id = 7): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('docs:summarize');
        $cw->setCronSchedule('0 3 * * *');
        $cw->setInputPath('/Documents');
        $cw->setOptions('{}');
        $cw->setIsActive(true);
        (new \ReflectionProperty($cw, 'id'))->setValue($cw, $id);
        return $cw;
    }

    private function pendingRun(int $id = 1, array $state = []): CoworkerRun {
        $run = new CoworkerRun();
        $run->setCoworkerId(7);
        $run->setUserId('alice');
        $run->setStatus(CoworkerRun::STATUS_PENDING);
        $run->setStartedAt(1_699_000_000);
        $run->setDecodedState($state);
        (new \ReflectionProperty($run, 'id'))->setValue($run, $id);
        return $run;
    }

    public function testPendingResultLeavesTheRunOpen(): void {
        $task = $this->createMock(ResumableCoworkerTaskType::class);
        $task->method('run')->willReturn([
            'itemsTotal' => 5,
            'itemsProcessed' => 0,
            'summary' => 'Submitted 5 document(s) to a batch.',
            'pending' => true,
            'state' => ['batch_id' => 'batch_01ABC'],
        ]);
        $this->registry->method('get')->willReturn($task);
        $this->runMapper->method('findPendingForCoworker')->willReturn(null);

        $run = $this->service->execute($this->coworker());

        $this->assertSame(CoworkerRun::STATUS_PENDING, $run->getStatus());
        $this->assertNull($run->getFinishedAt(), 'a pending run has not finished');
        $this->assertSame('batch_01ABC', $run->getPendingBatchId());
        $this->assertSame(5, $run->getItemsTotal());
    }

    /**
     * Starting a second batch for the same coworker would bill the folder
     * twice and race two runs onto the same output files.
     */
    public function testExecuteSkipsWhileAPreviousRunIsPending(): void {
        $open = $this->pendingRun(42, ['batch_id' => 'batch_01ABC']);
        $this->runMapper->method('findPendingForCoworker')->willReturn($open);
        $this->runMapper->expects($this->never())->method('insert');
        $this->registry->expects($this->never())->method('get');

        $run = $this->service->execute($this->coworker());

        $this->assertSame(42, $run->getId());
    }

    public function testResumeTerminalisesTheRunWhenTheBatchIsDone(): void {
        $task = $this->createMock(ResumableCoworkerTaskType::class);
        $task->expects($this->once())
            ->method('resume')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->equalTo(['batch_id' => 'batch_01ABC']),
                $this->anything(),
            )
            ->willReturn([
                'itemsTotal' => 5,
                'itemsProcessed' => 5,
                'summary' => 'Summarized 5 document(s).',
            ]);
        $this->registry->method('get')->willReturn($task);
        $this->mapper->method('findByIdAndUser')->willReturn($this->coworker());

        $run = $this->service->resumePending($this->pendingRun(1, ['batch_id' => 'batch_01ABC']));

        $this->assertSame(CoworkerRun::STATUS_SUCCESS, $run->getStatus());
        $this->assertSame(5, $run->getItemsProcessed());
        $this->assertNotNull($run->getFinishedAt());
        $this->assertNull($run->getState(), 'finished runs carry no resume state');
    }

    public function testResumeKeepsTheRunOpenWhileTheBatchIsStillRunning(): void {
        $task = $this->createMock(ResumableCoworkerTaskType::class);
        $task->method('resume')->willReturn([
            'itemsTotal' => 5,
            'itemsProcessed' => 0,
            'summary' => 'Waiting for batch batch_01ABC.',
            'pending' => true,
            'state' => ['batch_id' => 'batch_01ABC', 'polls' => 2],
        ]);
        $this->registry->method('get')->willReturn($task);
        $this->mapper->method('findByIdAndUser')->willReturn($this->coworker());

        $run = $this->service->resumePending($this->pendingRun(1, ['batch_id' => 'batch_01ABC']));

        $this->assertSame(CoworkerRun::STATUS_PENDING, $run->getStatus());
        $this->assertNull($run->getFinishedAt());
        $this->assertSame(2, $run->getDecodedState()['polls']);
    }

    /** The caller is a background job, so a failing resume must not throw. */
    public function testResumeCapturesAFailureOnTheRun(): void {
        $task = $this->createMock(ResumableCoworkerTaskType::class);
        $task->method('resume')->willThrowException(new \RuntimeException('batch expired'));
        $this->registry->method('get')->willReturn($task);
        $this->mapper->method('findByIdAndUser')->willReturn($this->coworker());

        $run = $this->service->resumePending($this->pendingRun(1, ['batch_id' => 'batch_01ABC']));

        $this->assertSame(CoworkerRun::STATUS_ERROR, $run->getStatus());
        $this->assertSame('batch expired', $run->getError());
        $this->assertNotNull($run->getFinishedAt());
    }

    /**
     * Nothing can finish a run whose task type cannot resume, so it is closed
     * rather than left for the poll job to retry forever.
     */
    public function testResumeFailsForANonResumableTaskType(): void {
        $this->registry->method('get')->willReturn($this->createMock(CoworkerTaskType::class));
        $this->mapper->method('findByIdAndUser')->willReturn($this->coworker());

        $run = $this->service->resumePending($this->pendingRun(1, ['batch_id' => 'batch_01ABC']));

        $this->assertSame(CoworkerRun::STATUS_ERROR, $run->getStatus());
        $this->assertStringContainsString('cannot resume', (string)$run->getError());
    }

    public function testResumeClosesARunWhoseCoworkerIsGone(): void {
        $this->mapper->method('findByIdAndUser')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

        $run = $this->service->resumePending($this->pendingRun(1, ['batch_id' => 'batch_01ABC']));

        $this->assertSame(CoworkerRun::STATUS_ERROR, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());
        $this->assertNull($run->getState());
    }
}
