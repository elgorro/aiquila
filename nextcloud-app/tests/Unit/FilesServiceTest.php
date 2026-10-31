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
        $this->claudeService = $this->createStub(ClaudeSDKService::class);
        $this->mapper = $this->createStub(FileUploadMapper::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->buildService();
    }

    private function buildService(bool $filesApi = true): void {
        $this->claudeService->method('supportsFilesApi')->willReturn($filesApi);
        $this->service = new FilesService($this->claudeService, $this->mapper, $this->logger);
    }

    public function testReturnsCachedFileIdWithoutUploading(): void {
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService();

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
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService();

        $sha = hash('sha256', 'pdfbytes');

        $this->mapper->expects($this->once())->method('findByHash')->with('bob', $sha)->willReturn(null);
        $this->claudeService->expects($this->once())
            ->method('uploadFile')
            ->with('pdfbytes', 'doc.pdf', 'application/pdf', 'bob', FilesService::TTL_SECONDS)
            ->willReturn(['id' => 'file_fresh_abc', 'expiresAt' => 1_900_000_000]);

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
        $this->assertSame(1_900_000_000, $insertedRows[0]->getExpiresAt());
    }

    public function testDisabledFilesApiNeverUploads(): void {
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService(false);

        $this->mapper->expects($this->never())->method('findByHash');
        $this->claudeService->expects($this->never())->method('uploadFile');

        $this->assertNull($this->service->getOrUploadFileId('bytes', 'x.pdf', 'application/pdf', 'alice'));
    }

    public function testFileCloseToExpiryIsUploadedAgain(): void {
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService();

        $cached = new FileUpload();
        $cached->setAnthropicFileId('file_old');
        $cached->setUploadedAt(time() - FilesService::TTL_SECONDS);
        $cached->setExpiresAt(time() + 3600);
        $this->mapper->method('findByHash')->willReturn($cached);

        // It expires on its own; no remote delete needed.
        $this->claudeService->expects($this->never())->method('deleteFile');
        $this->mapper->expects($this->once())->method('delete')->with($cached);
        $this->claudeService->expects($this->once())->method('uploadFile')
            ->willReturn(['id' => 'file_new', 'expiresAt' => null]);
        $this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $this->assertSame('file_new', $this->service->getOrUploadFileId('bytes', 'x.pdf', 'application/pdf', 'alice'));
    }

    public function testOldUploadWithoutExpiryIsDeletedRemotelyWhenReplaced(): void {
        $this->claudeService = $this->createMock(ClaudeSDKService::class);
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService();

        $cached = new FileUpload();
        $cached->setAnthropicFileId('file_legacy');
        $cached->setUploadedAt(time() - FilesService::TTL_SECONDS);
        $this->mapper->method('findByHash')->willReturn($cached);

        $this->claudeService->expects($this->once())->method('deleteFile')->with('file_legacy', 'alice');
        $this->mapper->expects($this->once())->method('delete')->with($cached);
        $this->claudeService->method('uploadFile')->willReturn(['id' => 'file_new', 'expiresAt' => null]);
        $this->mapper->method('insert')->willReturnArgument(0);

        $this->assertSame('file_new', $this->service->getOrUploadFileId('bytes', 'x.pdf', 'application/pdf', 'alice'));
    }

    public function testReturnsNullAndLogsOnUploadFailure(): void {
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildService();

        $this->mapper->method('findByHash')->willReturn(null);
        $this->claudeService->method('uploadFile')->willThrowException(new \RuntimeException('boom'));
        $this->mapper->expects($this->never())->method('insert');
        $this->logger->expects($this->once())->method('warning');

        $id = $this->service->getOrUploadFileId('bytes', 'x.png', 'image/png', 'carol');
        $this->assertNull($id);
    }

    public function testEvictByFileIdRemovesRow(): void {
        $this->mapper = $this->createMock(FileUploadMapper::class);
        $this->buildService();

        $this->mapper->expects($this->once())
            ->method('deleteByFileId')
            ->with('file_stale_42')
            ->willReturn(1);

        $this->assertTrue($this->service->evictByFileId('file_stale_42'));
    }

    public function testEvictByFileIdReturnsFalseWhenAbsent(): void {
        $this->mapper->method('deleteByFileId')->willReturn(0);
        $this->assertFalse($this->service->evictByFileId('file_missing'));
    }

    public function testEvictByFileIdSwallowsAndLogsErrors(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildService();

        $this->mapper->method('deleteByFileId')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('debug');
        $this->assertFalse($this->service->evictByFileId('file_x'));
    }

    public function testExtractStaleFileIdMatchesAnthropicMessage(): void {
        $e = new \RuntimeException("file_id 'file_abc123_XYZ' not found");
        $this->assertSame('file_abc123_XYZ', $this->service->extractStaleFileIdFromError($e));
    }

    public function testExtractStaleFileIdReturnsNullWhenAbsent(): void {
        $e = new \RuntimeException('rate limited');
        $this->assertNull($this->service->extractStaleFileIdFromError($e));
    }

    public function testStillReturnsFileIdWhenInsertRaces(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->buildService();

        $this->mapper->method('findByHash')->willReturn(null);
        $this->claudeService->method('uploadFile')->willReturn(['id' => 'file_raced_999', 'expiresAt' => null]);
        $this->mapper->method('insert')->willThrowException(new \RuntimeException('UNIQUE constraint'));
        $this->logger->expects($this->once())->method('debug');

        $id = $this->service->getOrUploadFileId('bytes', 'x.pdf', 'application/pdf', 'dave');
        $this->assertSame('file_raced_999', $id);
    }
}
