<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Batch;

use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Batch\SummarizeOperation;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\Conversion\ConversionMimeProvider;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Office documents in the batch docs pipeline: converted to PDF through
 * Nextcloud's conversion API into appdata, read back, deleted, and sent like
 * any PDF; skipped with a reason when no converter can do it.
 */
class BatchDocumentRunnerOfficeTest extends TestCase {
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const PDF = "%PDF-1.7\n%%EOF\n";

    private LLMProviderInterface $provider;
    /** @var list<ConversionMimeProvider> */
    private array $pairs;
    private bool $hasProviders = true;
    private string $converted = self::PDF;
    private ?\Throwable $conversionError = null;
    /** @var list<array{0: int, 1: string, 2: ?string}> convert() calls: source id, target mime, destination */
    private array $conversionsDone = [];
    /** @var array<string, string> path => content of files in appdata */
    private array $appdata = [];
    /** @var list<string> */
    private array $deletedPaths = [];
    /** @var list<array<string, mixed>> */
    private array $submitted = [];
    /** @var list<string> */
    private array $uploadedNames = [];

    protected function setUp(): void {
        $this->pairs = [new ConversionMimeProvider(self::DOCX, 'application/pdf', 'pdf', 'PDF')];
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('supportsBatch')->willReturn(true);
        $p->method('supportsFilesApi')->willReturn(true);
        $p->method('uploadFile')->willReturnCallback(function (string $bytes, string $name): array {
            $this->uploadedNames[] = $name;
            return ['id' => 'file_' . count($this->uploadedNames), 'expiresAt' => null];
        });
        $p->method('submitBatch')->willReturnCallback(function (array $requests): string {
            $this->submitted = $requests;
            return 'batch_1';
        });
        $this->provider = $p;
    }

    private function runner(bool $withConverter = true): BatchDocumentRunner {
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturnCallback(fn () => $this->provider);

        $folder = $this->createStub(Folder::class);
        $folder->method('getPath')->willReturn('/appdata_x/aiquila/conversions');
        $folder->method('getDirectoryListing')->willReturn([]);

        $root = $this->createStub(IRootFolder::class);
        $root->method('getAppDataDirectoryName')->willReturn('appdata_x');
        $root->method('newFolder')->willReturn($folder);
        $root->method('get')->willReturnCallback(function (string $path) use ($folder) {
            if ($path === '/appdata_x/aiquila/conversions') {
                return $folder;
            }
            if (!isset($this->appdata[$path])) {
                throw new NotFoundException($path);
            }
            $file = $this->createStub(File::class);
            $file->method('getContent')->willReturn($this->appdata[$path]);
            $file->method('delete')->willReturnCallback(function () use ($path): void {
                $this->deletedPaths[] = $path;
                unset($this->appdata[$path]);
            });
            return $file;
        });

        $conversions = $this->createStub(IConversionManager::class);
        $conversions->method('hasProviders')->willReturnCallback(fn (): bool => $this->hasProviders);
        $conversions->method('getProviders')->willReturnCallback(fn (): array => $this->pairs);
        $conversions->method('convert')->willReturnCallback(function (File $file, string $mime, ?string $destination = null): string {
            if ($this->conversionError !== null) {
                throw $this->conversionError;
            }
            $this->conversionsDone[] = [$file->getId(), $mime, $destination];
            $this->appdata[(string)$destination] = $this->converted;
            return (string)$destination;
        });

        $tags = $this->createStub(ISystemTagManager::class);
        $tags->method('getTag')->willThrowException(new TagNotFoundException());

        return new BatchDocumentRunner(
            $root,
            $factory,
            $tags,
            $this->createStub(ISystemTagObjectMapper::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(UsageStatService::class),
            $withConverter ? $conversions : null,
        );
    }

    private function ctx(): BatchContext {
        return new BatchContext(
            userId: 'bob',
            operation: new SummarizeOperation(),
            options: [],
            requestOptions: static fn (): array => [],
        );
    }

    private function file(int $id, string $name, string $mime = self::DOCX, string $content = 'PK zip bytes', ?int $size = null): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/bob/files/In/' . $name);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getContent')->willReturn($content);
        $file->method('getSize')->willReturn($size ?? strlen($content));
        $file->method('getMTime')->willReturn(100);
        $file->method('getParent')->willReturn($this->createStub(Folder::class));
        return $file;
    }

    private static function noProgress(): callable {
        return static function (int $p, int $t): void {
        };
    }

    public function testOfficeTypesAreReadByDefault(): void {
        foreach ([self::DOCX, 'application/vnd.oasis.opendocument.text', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $mime) {
            $this->assertContains($mime, BatchDocumentRunner::DEFAULT_MIME_TYPES);
        }
        $doc = $this->file(5, 'a.docx');
        $this->assertSame([$doc], $this->runner()->collect($this->ctx(), $doc));
    }

    /** The PDF goes to appdata, never beside the source, and is gone once read. */
    public function testADocumentIsConvertedInAppdataAndSentAsAPdf(): void {
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [$this->file(5, 'report.docx')], self::noProgress());

        $this->assertCount(1, $this->conversionsDone);
        [$id, $mime, $destination] = $this->conversionsDone[0];
        $this->assertSame([5, 'application/pdf'], [$id, $mime]);
        $this->assertStringStartsWith('/appdata_x/aiquila/conversions/c5-', (string)$destination);
        $this->assertStringEndsWith('.pdf', (string)$destination);
        $this->assertSame([(string)$destination], $this->deletedPaths);
        $this->assertSame([], $this->appdata);

        $this->assertSame(['report.pdf'], $this->uploadedNames);
        $this->assertSame(['type' => 'file', 'file_id' => 'file_1'], $this->submitted[0]['messages'][0]['content'][0]['source']);
        $this->assertTrue($result['pending']);
    }

    public function testAFailedConversionSkipsTheFileWithTheReason(): void {
        $this->conversionError = new \RuntimeException('Collabora is unreachable');
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'a.docx'),
            $this->file(6, 'b.txt', 'text/plain', 'text'),
        ], self::noProgress());

        $this->assertSame([['item' => '/In/a.docx', 'reason' => 'Could not be converted to PDF: Collabora is unreachable']], $result['state']['skipped']);
        $this->assertSame([], $this->uploadedNames);
    }

    public function testAConverterThatReturnsNoPdfIsCaught(): void {
        $this->converted = '<html>error page</html>';
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'a.docx'),
            $this->file(6, 'b.txt', 'text/plain', 'text'),
        ], self::noProgress());

        $this->assertSame('The converter did not return a PDF', $result['state']['skipped'][0]['reason']);
        $this->assertSame([], $this->appdata, 'the bad output is still deleted');
    }

    /** Without Nextcloud Office a walk leaves them, and a selection says what to install. */
    public function testWithoutAConverterOfficeDocumentsArePassedOver(): void {
        $this->hasProviders = false;
        $runner = $this->runner();

        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$this->file(5, 'a.docx'), $pdf = $this->file(6, 'b.pdf', 'application/pdf', self::PDF)]);
        $this->assertSame([$pdf], $runner->collect($this->ctx(), $folder));

        $selection = $runner->collectSelection($this->ctx(), [$this->file(7, 'c.docx')], []);
        $this->assertSame([], $selection['files']);
        $this->assertSame('Office documents need a file converter; install Nextcloud Office (richdocuments)', $selection['skipped'][0]['reason']);
    }

    public function testAnOfficeTypeNoConverterHandlesIsNamed(): void {
        $selection = $this->runner()->collectSelection($this->ctx(), [$this->file(7, 'c.odp', 'application/vnd.oasis.opendocument.presentation')], []);
        $this->assertSame('No installed converter turns application/vnd.oasis.opendocument.presentation into PDF', $selection['skipped'][0]['reason']);
    }

    public function testARunnerWithoutTheConversionApiTreatsItAsMissing(): void {
        $selection = $this->runner(false)->collectSelection($this->ctx(), [$this->file(7, 'c.docx')], []);
        $this->assertStringStartsWith('Office documents need a file converter', $selection['skipped'][0]['reason']);
    }

    public function testOnAnotherProviderOfficeDocumentsNeedAnthropic(): void {
        $p = $this->createStub(LLMProviderInterface::class);
        $p->method('getLabel')->willReturn('Mistral');
        $this->provider = $p;

        $selection = $this->runner()->collectSelection($this->ctx(), [$this->file(7, 'c.docx')], []);
        $this->assertSame('Office documents are read as PDF, which needs the Anthropic provider', $selection['skipped'][0]['reason']);
        $this->assertSame([], $this->conversionsDone);
    }

    public function testAnOversizedDocumentIsNotConverted(): void {
        $result = $this->runner()->process($this->ctx(), new CoworkerRun(), [
            $this->file(5, 'big.docx', self::DOCX, 'x', BatchDocumentRunner::MAX_PDF_BYTES + 1),
            $this->file(6, 'b.txt', 'text/plain', 'text'),
        ], self::noProgress());

        $this->assertSame([], $this->conversionsDone);
        $this->assertStringStartsWith('Too large', $result['state']['skipped'][0]['reason']);
    }
}
