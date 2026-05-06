<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\FileUpload;
use OCA\AIquila\Db\FileUploadMapper;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\FilesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilesServiceTest extends TestCase {
    private $claudeService;
    private $mapper;
    private $logger;
    private FilesService $service;

    protected function setUp(): void {
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new FilesService($this->claudeService, $this->mapper, $this->logger);
    }

    public function testReturnsCachedFileIdWithoutUploading(): void {
        $cached = new FileUpload();
        $cached->setUserId('alice');
        $cached->setSha256(hash('sha256', 'hello'));
        $cached->setAnthropicFileId('file_cached_xyz');
        $cached->setUploadedAt(time() - 3600);

        $this->mapper->expects($this->once())
            ->method('findByHash')
            ->with('alice', hash('sha256', 'hello'))
            ->willReturn($cached);

        $this->claudeService->expects($this->never())->method('uploadFile');
        $this->mapper->expects($this->never())->method('insert');

        $id = $this->service->getOrUploadFileId('hello', 'doc.txt', 'text/plain', 'alice');
        $this->assertSame('file_cached_xyz', $id);
    }

    public function testUploadsAndCachesOnFirstUse(): void {
        $sha = hash('sha256', 'pdfbytes');

        $this->mapper->method('findByHash')->with('bob', $sha)->willReturn(null);
        $this->claudeService->expects($this->once())
            ->method('uploadFile')
            ->with('pdfbytes', 'doc.pdf', 'application/pdf', 'bob')
            ->willReturn('file_fresh_abc');

        $insertedRows = [];
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (FileUpload $row) use (&$insertedRows) {
                $insertedRows[] = $row;
                return $row;
            });

        $id = $this->service->getOrUploadFileId('pdfbytes', 'doc.pdf', 'application/pdf', 'bob');

        $this->assertSame('file_fresh_abc', $id);
        $this->assertCount(1, $insertedRows);
        $this->assertSame('bob', $insertedRows[0]->getUserId());
        $this->assertSame($sha, $insertedRows[0]->getSha256());
        $this->assertSame('file_fresh_abc', $insertedRows[0]->getAnthropicFileId());
    }

    public function testReturnsNullAndLogsOnUploadFailure(): void {
        $this->mapper->method('findByHash')->willReturn(null);
        $this->claudeService->method('uploadFile')->willThrowException(new \RuntimeException('boom'));
        $this->mapper->expects($this->never())->method('insert');
        $this->logger->expects($this->once())->method('warning');

        $id = $this->service->getOrUploadFileId('bytes', 'x.png', 'image/png', 'carol');
        $this->assertNull($id);
    }

    public function testStillReturnsFileIdWhenInsertRaces(): void {
        $this->mapper->method('findByHash')->willReturn(null);
        $this->claudeService->method('uploadFile')->willReturn('file_raced_999');
        $this->mapper->method('insert')->willThrowException(new \RuntimeException('UNIQUE constraint'));
        $this->logger->expects($this->once())->method('debug');

        $id = $this->service->getOrUploadFileId('bytes', 'x.pdf', 'application/pdf', 'dave');
        $this->assertSame('file_raced_999', $id);
    }
}
