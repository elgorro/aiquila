<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\BackgroundJob\CleanupAnthropicFilesJob;
use OCA\AIquila\Db\FileUploadMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupAnthropicFilesJobTest extends TestCase {
    public function testRunsDeleteOlderThanWith30DayCutoff(): void {
        $now = 1_750_000_000;
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);

        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->expects($this->once())
            ->method('deleteOlderThan')
            ->with($now - 30 * 86400)
            ->willReturn(3);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $job = new CleanupAnthropicFilesJob($time, $mapper, $logger);
        $job->execute();
    }

    public function testRunsSilentlyWhenNothingDeleted(): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_750_000_000);

        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->expects($this->once())->method('deleteOlderThan')->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('warning');

        (new CleanupAnthropicFilesJob($time, $mapper, $logger))->execute();
    }

    public function testRunsLogsWhenMapperThrows(): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_750_000_000);

        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->method('deleteOlderThan')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new CleanupAnthropicFilesJob($time, $mapper, $logger))->execute();
    }

    public function testIntervalIsDaily(): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(0);
        $mapper = $this->createMock(FileUploadMapper::class);
        $logger = $this->createMock(LoggerInterface::class);

        $job = new CleanupAnthropicFilesJob($time, $mapper, $logger);
        $this->assertSame(86400, $job->getInterval());
    }
}
