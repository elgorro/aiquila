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
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PDFs in the batch docs pipeline: a `document` block per PDF, uploaded to
 * the Files API (and deleted again) or inlined as base64, and passed over
 * with a reason on a provider without document input.
 */
class BatchDocumentRunnerPdfTest extends TestCase {
    private const PDF = "%PDF-1.7\n1 0 obj << >> endobj\n%%EOF\n";

    private ?LLMProviderInterface $provider = null;
    private $userFolder;
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?int}> */
    private array $uploads = [];
    /** @var list<string> */
    private array $deleted = [];
    /** @var list<array<string, mixed>> */
    private array $submitted = [];
    private bool $filesApi = true;
    private ?\Throwable $submitError = null;

    protected function setUp(): void {
        $this->userFolder = $this->createStub(Folder::class);
    }

    private function claude(): ClaudeSDKService {
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('supportsBatch')->willReturn(true);
        $p->method('supportsFilesApi')->willReturnCallback(fn (): bool => $this->filesApi);
        $p->method('uploadFile')->willReturnCallback(function (string $bytes, string $name, string $mime, ?string $uid = null, ?int $ttl = null): array {
            $this->uploads[] = [$bytes, $name, $mime, $uid, $ttl];
            return ['id' => 'file_' . count($this->uploads), 'expiresAt' => null];
        });
        $p->method('deleteFile')->willReturnCallback(function (string $id): void {
            $this->deleted[] = $id;
        });
        $p->method('submitBatch')->willReturnCallback(function (array $requests): string {
            if ($this->submitError !== null) {
                throw $this->submitError;
            }
            $this->submitted = $requests;
            return 'batch_1';
        });
        return $p;
    }

    private function runner(): BatchDocumentRunner {
        $this->provider ??= $this->claude();
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturnCallback(fn () => $this->provider);

        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);

        $tags = $this->createStub(ISystemTagManager::class);
        $tags->method('getTag')->willThrowException(new TagNotFoundException());
        $tags->method('createTag')->willReturnCallback(function (string $name) {
            $t = $this->createStub(\OCP\SystemTag\ISystemTag::class);
            $t->method('getId')->willReturn('t:' . $name);
            return $t;
        });

        return new BatchDocumentRunner(
            $root,
            $factory,
            $tags,
            $this->createStub(ISystemTagObjectMapper::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(UsageStatService::class),
        );
    }

    private function ctx(array $options = [], bool $allowBatch = true): BatchContext {
        return new BatchContext(
            userId: 'bob',
            operation: isset($options['targetLanguage']) ? new TranslateOperation() : new SummarizeOperation(),
            options: $options,
            requestOptions: static fn (): array => ['system' => 'instance rules'],
            allowBatch: $allowBatch,
        );
    }

    private function file(int $id, string $name, string $content, string $mime = 'application/pdf', ?int $size = null): File {
        $parent = $this->createStub(Folder::class);
        $parent->method('nodeExists')->willReturn(false);
        $parent->method('newFile')->willReturnCallback(function () {
            return $this->createStub(File::class);
        });
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/bob/files/In/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn($size ?? strlen($content));
        $file->method('getMTime')->willReturn(100);
        $file->method('getParent')->willReturn($parent);
        return $file;
    }

    private static function noProgress(): callable {
        return static function (int $p, int $t): void {
        };
    }

    public function testPdfsAreReadByDefault(): void {
        $this->assertContains('application/pdf', BatchDocumentRunner::DEFAULT_MIME_TYPES);
        $pdf = $this->file(5, 'a.pdf', self::PDF);
        $this->assertSame([$pdf], $this->runner()->collect($this->ctx(), $pdf));
    }

    /** On the Files API the batch carries a file_id, and the upload expires by itself as a backstop. */
    public function testABatchUploadsEachPdfAndReferencesItByFileId(): void {
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'a.pdf', self::PDF),
            $this->file(6, 'b.txt', 'plain text', 'text/plain'),
        ], self::noProgress());

        $this->assertSame([[self::PDF, 'a.pdf', 'application/pdf', 'bob', BatchDocumentRunner::UPLOAD_TTL_SECONDS]], $this->uploads);
        $this->assertGreaterThanOrEqual(24 * 3600, BatchDocumentRunner::UPLOAD_TTL_SECONDS);

        $pdf = $this->submitted[0]['messages'][0]['content'];
        $this->assertSame(['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'file_1']], $pdf[0]);
        $this->assertSame('text', $pdf[1]['type']);
        $this->assertStringContainsString('Summarize the attached document', $pdf[1]['text']);
        $this->assertSame(['system' => 'instance rules'], $this->submitted[0]['options']);

        // Text documents stay inline in the prompt.
        $this->assertIsString($this->submitted[1]['messages'][0]['content']);
        $this->assertStringContainsString("Document:\nplain text", $this->submitted[1]['messages'][0]['content']);

        $this->assertSame(['file_1'], $result['state']['uploaded_files']);
        $this->assertSame(['file_1'], BatchDocumentRunner::uploadsFromState($result['state']));
    }

    /** Without the Files API (switched off, or zero data retention) the PDF goes inline. */
    public function testWithoutTheFilesApiAPdfIsInlinedAsBase64(): void {
        $this->filesApi = false;
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [$this->file(5, 'a.pdf', self::PDF)], self::noProgress());

        $this->assertSame([], $this->uploads);
        $this->assertSame(
            ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(self::PDF)],
            $this->submitted[0]['messages'][0]['content'][0]['source'],
        );
        $this->assertSame([], $result['state']['uploaded_files']);
    }

    public function testAFailedSubmissionDeletesTheUploads(): void {
        $this->submitError = new \RuntimeException('batch rejected');
        try {
            $this->runner()->process($this->ctx(), new CoworkerRun(), [
                $this->file(5, 'a.pdf', self::PDF),
                $this->file(6, 'b.pdf', self::PDF),
            ], self::noProgress());
            $this->fail('the submission error was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('batch rejected', $e->getMessage());
        }
        $this->assertSame(['file_1', 'file_2'], $this->deleted);
    }

    public function testAFailedUploadSkipsThatPdfOnly(): void {
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('supportsBatch')->willReturn(true);
        $p->method('supportsFilesApi')->willReturn(true);
        $p->method('uploadFile')->willThrowException(new \RuntimeException('quota'));
        $p->method('submitBatch')->willReturnCallback(function (array $requests): string {
            $this->submitted = $requests;
            return 'batch_1';
        });
        $this->provider = $p;

        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'a.pdf', self::PDF),
            $this->file(6, 'b.txt', 'text', 'text/plain'),
        ], self::noProgress());

        $this->assertCount(1, $this->submitted);
        $this->assertSame([['item' => '/In/a.pdf', 'reason' => 'Could not be uploaded: quota']], $result['state']['skipped']);
    }

    /** Collecting the results ends what the uploads were for, whatever happens to the outputs. */
    public function testResumeDeletesTheUploadsOnceTheBatchHasEnded(): void {
        $this->provider = $this->claude();
        $this->provider->method('getBatchStatus')->willReturn(['status' => 'ended', 'ended' => true, 'counts' => []]);
        $this->provider->method('fetchBatchResults')->willThrowException(new \RuntimeException('results unavailable'));

        try {
            $this->runner()->resume($this->ctx(), new CoworkerRun(), [
                'batch_id' => 'batch_1',
                'file_ids' => ['f5' => 5],
                'submitted_at' => time(),
                'uploaded_files' => ['file_1', 'file_2'],
            ], self::noProgress());
            $this->fail('the results error was swallowed');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['file_1', 'file_2'], $this->deleted);
    }

    public function testAPendingBatchKeepsItsUploads(): void {
        $this->provider = $this->claude();
        $this->provider->method('getBatchStatus')->willReturn(['status' => 'in_progress', 'ended' => false, 'counts' => []]);

        $result = $this->runner()->resume($this->ctx(), new CoworkerRun(), [
            'batch_id' => 'batch_1',
            'file_ids' => ['f5' => 5],
            'submitted_at' => time(),
            'uploaded_files' => ['file_1'],
        ], self::noProgress());

        $this->assertTrue($result['pending']);
        $this->assertSame([], $this->deleted);
    }

    public function testALostBatchDeletesItsUploads(): void {
        $this->provider = $this->claude();
        $this->provider->method('getBatchStatus')->willReturn(['status' => 'in_progress', 'ended' => false, 'counts' => []]);

        try {
            $this->runner()->resume($this->ctx(), new CoworkerRun(), [
                'batch_id' => 'batch_1',
                'file_ids' => ['f5' => 5],
                'submitted_at' => time() - BatchDocumentRunner::BATCH_DEADLINE_SECONDS - 1,
                'uploaded_files' => ['file_1'],
            ], self::noProgress());
            $this->fail('a lost batch was kept pending');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['file_1'], $this->deleted);
    }

    /** A run that does not batch sends one request per PDF, inline, with the run's request options. */
    public function testTheSynchronousPathSendsTheDocumentBlockThroughChat(): void {
        $calls = [];
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('chat')->willReturnCallback(function (array $messages, ?string $system, ?string $uid, array $options) use (&$calls): array {
            $calls[] = [$messages, $system, $uid, $options];
            return ['response' => 'Summary', 'usage' => []];
        });
        $this->provider = $p;

        $result = $this->runner()->process($this->ctx([], false), new CoworkerRun(), [$this->file(5, 'a.pdf', self::PDF)], self::noProgress());

        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertCount(1, $calls);
        [$messages, $system, $uid, $options] = $calls[0];
        $this->assertSame('base64', $messages[0]['content'][0]['source']['type']);
        $this->assertSame(base64_encode(self::PDF), $messages[0]['content'][0]['source']['data']);
        $this->assertNull($system);
        $this->assertSame('bob', $uid);
        $this->assertSame(['system' => 'instance rules'], $options);
        $this->assertSame([], $this->uploads);
    }

    public function testANonAnthropicProviderPassesPdfsOver(): void {
        $p = $this->createStub(LLMProviderInterface::class);
        $p->method('getLabel')->willReturn('Mistral');
        $this->provider = $p;
        $runner = $this->runner();

        // A coworker's walk leaves them for a provider that can read them.
        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([
            $this->file(5, 'a.pdf', self::PDF),
            $text = $this->file(6, 'b.txt', 'text', 'text/plain'),
        ]);
        $this->assertSame([$text], $runner->collect($this->ctx(), $folder));

        // A manual selection says why.
        $selection = $runner->collectSelection($this->ctx(), [$this->file(7, 'c.pdf', self::PDF)], []);
        $this->assertSame([], $selection['files']);
        $this->assertSame([['item' => '/In/c.pdf', 'reason' => 'PDF input needs the Anthropic provider; Mistral is configured']], $selection['skipped']);
    }

    public function testAFileNamedPdfThatIsNotOneIsSkippedBeforeUpload(): void {
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'a.pdf', '<html>not a pdf</html>'),
            $this->file(6, 'b.txt', 'text', 'text/plain'),
        ], self::noProgress());

        $this->assertSame([], $this->uploads);
        $this->assertSame([['item' => '/In/a.pdf', 'reason' => 'Not a PDF, although its name says so']], $result['state']['skipped']);
    }

    public function testAnOversizedPdfIsSkippedWithItsLimit(): void {
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'big.pdf', self::PDF, 'application/pdf', BatchDocumentRunner::MAX_PDF_BYTES + 1),
            $this->file(6, 'b.txt', 'text', 'text/plain'),
        ], self::noProgress());

        $this->assertSame([], $this->uploads);
        $this->assertStringStartsWith('Too large', $result['state']['skipped'][0]['reason']);
        $this->assertStringContainsString('the limit is 32 MB', $result['state']['skipped'][0]['reason']);
    }

    /** A PDF's translation comes back as Markdown, not as a PDF. */
    public function testTheTranslatePromptForAPdfAsksForMarkdown(): void {
        $prompt = (new TranslateOperation())->buildDocumentPrompt(['targetLanguage' => 'German']);
        $this->assertStringContainsString('attached document into German, as Markdown', $prompt);
        $this->assertStringNotContainsString('Document:', $prompt);
    }

    public function testUploadsFromStateIgnoresWhatIsNotAnId(): void {
        $this->assertSame(['file_1'], BatchDocumentRunner::uploadsFromState(['uploaded_files' => ['file_1', '', 3, null]]));
        $this->assertSame([], BatchDocumentRunner::uploadsFromState([]));
    }
}
