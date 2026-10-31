<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\BackgroundJob\CleanupAnthropicFilesJob;
use OCA\AIquila\Db\FileUpload;
use OCA\AIquila\Db\FileUploadMapper;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FilesService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupAnthropicFilesJobTest extends TestCase {
    private const NOW = 1_750_000_000;

    /**
     * Invoke the job body directly.
     *
     * `TimedJob::start()` is final and resolves a logger through `OCP\Server`,
     * which needs a running Nextcloud, so the protected `run()` is the only
     * unit-testable entry point.
     */
    private function runJob(CleanupAnthropicFilesJob $job): void {
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }

    private function job(FileUploadMapper $mapper, ClaudeSDKService $claude, LoggerInterface $logger): CleanupAnthropicFilesJob {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new CleanupAnthropicFilesJob($time, $mapper, $claude, $logger);
    }

    private function claude(bool $filesApi = true, bool $mock = false): ClaudeSDKService {
        $claude = $mock ? $this->createMock(ClaudeSDKService::class) : $this->createStub(ClaudeSDKService::class);
        $claude->method('supportsFilesApi')->willReturn($filesApi);
        return $claude;
    }

    private function row(string $fileId, int $uploadedAt): FileUpload {
        $row = new FileUpload();
        $row->setUserId('alice');
        $row->setAnthropicFileId($fileId);
        $row->setUploadedAt($uploadedAt);
        return $row;
    }

    public function testDropsExpiredRowsAndDeletesOldUploadsWithoutExpiry(): void {
        $legacy = $this->row('file_legacy', self::NOW - FilesService::TTL_SECONDS - 10);

        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->expects($this->once())->method('deleteExpired')->with(self::NOW)->willReturn(3);
        $mapper->expects($this->once())->method('findToDelete')
            ->with(self::NOW - FilesService::TTL_SECONDS, false, $this->greaterThan(0))
            ->willReturn([$legacy]);
        $mapper->expects($this->once())->method('delete')->with($legacy);

        $claude = $this->claude(true, true);
        $claude->expects($this->once())->method('deleteFile')->with('file_legacy', 'alice');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $this->runJob($this->job($mapper, $claude, $logger));
    }

    public function testSwitchedOffFilesApiDeletesEveryUpload(): void {
        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->method('deleteExpired')->willReturn(0);
        $mapper->expects($this->once())->method('findToDelete')
            ->with($this->anything(), true, $this->anything())
            ->willReturn([]);

        $this->runJob($this->job($mapper, $this->claude(false), $this->createStub(LoggerInterface::class)));
    }

    public function testFailedRemoteDeleteKeepsTheRowForTheNextRun(): void {
        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->method('deleteExpired')->willReturn(0);
        $mapper->method('findToDelete')->willReturn([$this->row('file_x', self::NOW - FilesService::TTL_SECONDS - 10)]);
        $mapper->expects($this->never())->method('delete');

        $claude = $this->claude();
        $claude->method('deleteFile')->willThrowException(new \RuntimeException('no key'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->runJob($this->job($mapper, $claude, $logger));
    }

    public function testRowThatCannotBeDeletedIsEventuallyDropped(): void {
        $stuck = $this->row('file_x', self::NOW - 2 * FilesService::TTL_SECONDS - 10);
        $mapper = $this->createMock(FileUploadMapper::class);
        $mapper->method('deleteExpired')->willReturn(0);
        $mapper->method('findToDelete')->willReturn([$stuck]);
        $mapper->expects($this->once())->method('delete')->with($stuck);

        $claude = $this->claude();
        $claude->method('deleteFile')->willThrowException(new \RuntimeException('no key'));

        $this->runJob($this->job($mapper, $claude, $this->createStub(LoggerInterface::class)));
    }

    public function testRunsSilentlyWhenNothingToDo(): void {
        $mapper = $this->createStub(FileUploadMapper::class);
        $mapper->method('deleteExpired')->willReturn(0);
        $mapper->method('findToDelete')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('warning');

        $this->runJob($this->job($mapper, $this->createStub(ClaudeSDKService::class), $logger));
    }

    public function testLogsWhenMapperThrows(): void {
        $mapper = $this->createStub(FileUploadMapper::class);
        $mapper->method('deleteExpired')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->runJob($this->job($mapper, $this->createStub(ClaudeSDKService::class), $logger));
    }

    public function testIntervalIsDaily(): void {
        $job = $this->job(
            $this->createStub(FileUploadMapper::class),
            $this->createStub(ClaudeSDKService::class),
            $this->createStub(LoggerInterface::class),
        );
        $this->assertSame(86400, $job->getInterval());
    }
}
