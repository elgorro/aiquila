<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Batch;

use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Batch\ExtractOperation;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Structured extraction: the field list, the schema built from it, checking
 * an answer into a row, and the runner upserting rows into one CSV per folder.
 */
class ExtractOperationTest extends TestCase {
    private const OPTIONS = [
        'tableName' => 'invoices',
        'fields' => [
            ['name' => 'vendor', 'type' => 'string', 'description' => 'Who issued the invoice'],
            ['name' => 'total', 'type' => 'number'],
            ['name' => 'issued', 'type' => 'date'],
            ['name' => 'paid', 'type' => 'boolean'],
            ['name' => 'items', 'type' => 'integer'],
        ],
    ];

    // ── Options ───────────────────────────────────────────────────────────

    public function testValidOptionsPass(): void {
        (new ExtractOperation())->validateOptions(self::OPTIONS);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badOptions(): iterable {
        $f = static fn (array $field): array => ['fields' => [$field]];
        yield 'no fields' => [[], 'fields is required'];
        yield 'empty list' => [['fields' => []], 'fields is required'];
        yield 'map, not list' => [['fields' => ['a' => ['name' => 'a', 'type' => 'string']]], 'fields is required'];
        yield 'too many' => [['fields' => array_map(static fn (int $i): array => ['name' => "f$i", 'type' => 'string'], range(1, 31))], 'at most 30'];
        yield 'not an object' => [['fields' => ['vendor']], 'must be an object'];
        yield 'bad name' => [$f(['name' => 'Vendor Name', 'type' => 'string']), 'lowercase letters'];
        yield 'reserved name' => [$f(['name' => 'file_id', 'type' => 'string']), 'adds itself'];
        yield 'duplicate' => [['fields' => [['name' => 'a', 'type' => 'string'], ['name' => 'a', 'type' => 'number']]], 'appears twice'];
        yield 'bad type' => [$f(['name' => 'a', 'type' => 'money']), 'type must be one of'];
        yield 'long description' => [$f(['name' => 'a', 'type' => 'string', 'description' => str_repeat('x', 301)]), 'at most 300'];
        yield 'bad table name' => [['fields' => [['name' => 'a', 'type' => 'string']], 'tableName' => '../x'], 'tableName must be'];
    }

    #[DataProvider('badOptions')]
    public function testBadOptionsAreRejected(array $options, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        (new ExtractOperation())->validateOptions($options);
    }

    public function testTheSchemaFollowsTheFieldList(): void {
        $schema = (new ExtractOperation())->jsonSchema(self::OPTIONS);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['vendor', 'total', 'issued', 'paid', 'items'], $schema['required']);
        $this->assertSame(['type' => ['string', 'null'], 'description' => 'Who issued the invoice'], $schema['properties']['vendor']);
        $this->assertSame(['type' => ['string', 'null'], 'format' => 'date'], $schema['properties']['issued']);
        $this->assertSame(['type' => ['integer', 'null']], $schema['properties']['items']);
    }

    public function testThePromptListsTheFieldsAndAsksForJsonOnly(): void {
        $prompt = (new ExtractOperation())->buildPrompt('Invoice 42', self::OPTIONS);
        $this->assertStringContainsString('- vendor (text): Who issued the invoice', $prompt);
        $this->assertStringContainsString('- issued (a date as YYYY-MM-DD)', $prompt);
        $this->assertStringContainsString('Use null for a field the document does not contain', $prompt);
        $this->assertStringEndsWith("Document:\nInvoice 42", $prompt);
    }

    public function testTheDoneTagFollowsTheSchema(): void {
        $op = new ExtractOperation();
        $tag = $op->doneTag(self::OPTIONS);
        $this->assertMatchesRegularExpression('/^aiquila:extracted-[0-9a-f]{8}$/', $tag);
        $changed = self::OPTIONS;
        $changed['fields'][1]['type'] = 'integer';
        $this->assertNotSame($tag, $op->doneTag($changed));
        $this->assertSame('invoices.csv', $op->tableFileName(self::OPTIONS));
        $this->assertSame('extracted.csv', $op->tableFileName(['fields' => self::OPTIONS['fields']]));
    }

    // ── Answers ───────────────────────────────────────────────────────────

    public function testAnAnswerBecomesATypedRow(): void {
        $row = (new ExtractOperation())->parseRow(
            "Here you go:\n```json\n{\"vendor\": \"ACME, Inc.\", \"total\": \"1234.50\", \"issued\": \"2026-03-01\", \"paid\": \"false\", \"items\": 3.0, \"extra\": 1}\n```",
            self::OPTIONS,
        );
        $this->assertSame(['vendor' => 'ACME, Inc.', 'total' => 1234.5, 'issued' => '2026-03-01', 'paid' => false, 'items' => 3], $row);
    }

    public function testAMissingOrNullFieldIsEmpty(): void {
        $row = (new ExtractOperation())->parseRow('{"vendor": null, "total": ""}', self::OPTIONS);
        $this->assertSame(['vendor' => null, 'total' => null, 'issued' => null, 'paid' => null, 'items' => null], $row);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function badAnswers(): iterable {
        yield 'no json' => ['I could not find an invoice.', 'no JSON object'];
        yield 'broken json' => ['{"vendor": ', 'no JSON object'];
        yield 'a list' => ['[{"vendor": "x"}]', 'not a JSON object'];
        yield 'number as words' => ['{"total": "about twelve"}', 'total: expected number'];
        yield 'impossible date' => ['{"issued": "2026-02-30"}', 'issued: expected date'];
        yield 'date in another format' => ['{"issued": "1 March 2026"}', 'issued: expected date'];
        yield 'fraction for an integer' => ['{"items": 2.5}', 'items: expected integer'];
        yield 'yes for a boolean' => ['{"paid": "yes"}', 'paid: expected boolean'];
        yield 'object for text' => ['{"vendor": {"name": "x"}}', 'vendor: expected string'];
    }

    #[DataProvider('badAnswers')]
    public function testAnInvalidAnswerIsRejected(string $answer, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        (new ExtractOperation())->parseRow($answer, self::OPTIONS);
    }

    // ── CSV ───────────────────────────────────────────────────────────────

    /** Text a spreadsheet would run as a formula is neutralised; numbers are not. */
    public function testCellsCannotCarryFormulas(): void {
        $this->assertSame("'=HYPERLINK(\"http://x\")", BatchDocumentRunner::cell('=HYPERLINK("http://x")'));
        $this->assertSame("'+1", BatchDocumentRunner::cell('+1'));
        $this->assertSame("'-2", BatchDocumentRunner::cell('-2'));
        $this->assertSame("'@SUM(A1)", BatchDocumentRunner::cell('@SUM(A1)'));
        $this->assertSame('-2', BatchDocumentRunner::cell(-2));
        $this->assertSame('1234.5', BatchDocumentRunner::cell(1234.5));
        $this->assertSame('false', BatchDocumentRunner::cell(false));
        $this->assertSame('', BatchDocumentRunner::cell(null));
        $this->assertSame("'=x", BatchDocumentRunner::cell("'=x"), 'already neutralised stays as it is');
    }

    /** RFC 4180: CRLF, and commas, quotes and line breaks survive a round trip. */
    public function testTheCsvRoundTrips(): void {
        $csv = BatchDocumentRunner::csvEncode(['file_id', 'file', 'note'], [
            '7' => ['file_id' => '7', 'file' => '/In/a.pdf', 'note' => "ACME, \"Inc.\"\nline two"],
            '8' => ['file_id' => '8', 'file' => '/In/b.pdf'],
        ]);
        $this->assertStringStartsWith("file_id,file,note\r\n7,/In/a.pdf,\"ACME, \"\"Inc.\"\"\nline two\"\r\n", $csv);
        $this->assertSame([
            7 => ['file_id' => '7', 'file' => '/In/a.pdf', 'note' => "ACME, \"Inc.\"\nline two"],
            8 => ['file_id' => '8', 'file' => '/In/b.pdf', 'note' => ''],
        ], BatchDocumentRunner::csvRows($csv));
    }

    // ── Runner ────────────────────────────────────────────────────────────

    /** @var array<string, string> name => content of files in the folder */
    private array $folderFiles = [];
    /** @var array<string, array<string, true>> file id => tag ids */
    private array $tagged = [];
    private bool $failWrites = false;

    private function runner(LLMProviderInterface $provider): BatchDocumentRunner {
        $known = [];
        $tags = $this->createStub(ISystemTagManager::class);
        $tags->method('getTag')->willReturnCallback(function (string $name) use (&$known) {
            if (!isset($known[$name])) {
                throw new TagNotFoundException();
            }
            return $this->tag($name);
        });
        $tags->method('createTag')->willReturnCallback(function (string $name) use (&$known) {
            $known[$name] = true;
            return $this->tag($name);
        });
        $mapper = $this->createStub(ISystemTagObjectMapper::class);
        $mapper->method('assignTags')->willReturnCallback(function (string $id, string $type, $tagIds): void {
            foreach ((array)$tagIds as $t) {
                $this->tagged[$id][$t] = true;
            }
        });
        $mapper->method('haveTag')->willReturnCallback(fn (array $ids, string $type, string $tagId): bool => isset($this->tagged[$ids[0]][$tagId]));
        $mapper->method('getTagIdsForObjects')->willReturn([]);

        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($provider);

        return new BatchDocumentRunner(
            $this->createStub(IRootFolder::class),
            $factory,
            $tags,
            $mapper,
            $this->createStub(LoggerInterface::class),
            $this->createStub(UsageStatService::class),
        );
    }

    private function tag(string $name): ISystemTag {
        $t = $this->createStub(ISystemTag::class);
        $t->method('getId')->willReturn('t:' . $name);
        return $t;
    }

    private function folder(): Folder {
        $folder = $this->createStub(Folder::class);
        $folder->method('getPath')->willReturn('/bob/files/In');
        $folder->method('nodeExists')->willReturnCallback(fn (string $n): bool => isset($this->folderFiles[$n]));
        $folder->method('get')->willReturnCallback(fn (string $n) => $this->csvFile($n));
        $folder->method('newFile')->willReturnCallback(function (string $n, $content = null) {
            if ($this->failWrites) {
                throw new \RuntimeException('disk full');
            }
            $this->folderFiles[$n] = (string)$content;
            return $this->csvFile($n);
        });
        return $folder;
    }

    private function csvFile(string $name): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn(999);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/bob/files/In/' . $name);
        $file->method('getContent')->willReturnCallback(fn (): string => $this->folderFiles[$name] ?? '');
        $file->method('putContent')->willReturnCallback(function ($content) use ($name): void {
            if ($this->failWrites) {
                throw new \RuntimeException('disk full');
            }
            $this->folderFiles[$name] = (string)$content;
        });
        return $file;
    }

    private function source(int $id, string $name, Folder $parent, int $mtime = 100): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/bob/files/In/' . $name);
        $file->method('getMimetype')->willReturn('text/plain');
        $file->method('getContent')->willReturn("invoice $id");
        $file->method('getSize')->willReturn(9);
        $file->method('getMTime')->willReturn($mtime);
        $file->method('getParent')->willReturn($parent);
        return $file;
    }

    private function ctx(array $options = self::OPTIONS): BatchContext {
        return new BatchContext(userId: 'bob', operation: new ExtractOperation(), options: $options, requestOptions: static fn (): array => ['system' => 's'], allowBatch: false);
    }

    /** @param array<string, string> $answers document text => answer */
    private function provider(array $answers, array &$seenOptions = []): ClaudeSDKService {
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('ask')->willReturnCallback(function (string $prompt, string $ctx, ?string $uid, array $options) use ($answers, &$seenOptions): array {
            $seenOptions[] = $options;
            foreach ($answers as $text => $answer) {
                if (str_ends_with($prompt, $text)) {
                    return ['response' => $answer, 'usage' => []];
                }
            }
            return ['error' => 'unexpected', 'error_type' => 'errored'];
        });
        return $p;
    }

    /** Valid answers become rows of one table; an invalid one fails only its file. */
    public function testRowsAreCollectedIntoOneTableAndAnInvalidAnswerFailsItsFileOnly(): void {
        $folder = $this->folder();
        $seen = [];
        $runner = $this->runner($this->provider([
            'invoice 1' => '{"vendor": "=cmd", "total": 10, "issued": "2026-01-05", "paid": true, "items": 1}',
            'invoice 2' => '{"vendor": "Beta", "total": "x"}',
            'invoice 3' => '{"vendor": "Gamma, Ltd", "total": 30.25}',
        ], $seen));

        $result = $runner->process($this->ctx(), new CoworkerRun(), [
            $this->source(1, 'a.txt', $folder), $this->source(2, 'b.txt', $folder), $this->source(3, 'c.txt', $folder),
        ], static function (): void {
        });

        $this->assertSame(2, $result['itemsProcessed']);
        $this->assertSame(1, $result['itemsFailed']);
        $this->assertStringContainsString('Invalid: b.txt — total: expected number', $result['summary']);
        $this->assertSame(['/In/invoices.csv'], $result['outputs']);
        $this->assertSame((new ExtractOperation())->jsonSchema(self::OPTIONS), $seen[0]['json_schema'], 'the schema rides along on Claude');
        $this->assertSame('s', $seen[0]['system']);

        $rows = BatchDocumentRunner::csvRows($this->folderFiles['invoices.csv']);
        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertSame('', $rows['2']['vendor'], 'an invalid answer leaves no values');
        $this->assertStringContainsString('total: expected number', $rows['2'][BatchDocumentRunner::TABLE_ERROR]);
        $this->assertSame('', $rows['1'][BatchDocumentRunner::TABLE_ERROR]);
        $this->assertSame("'=cmd", $rows['1']['vendor']);
        $this->assertSame('true', $rows['1']['paid']);
        $this->assertSame('Gamma, Ltd', $rows['3']['vendor']);
        $this->assertSame('30.25', $rows['3']['total']);
        $this->assertSame('', $rows['3']['issued']);
        $this->assertSame('/In/c.txt', $rows['3']['file']);
        $this->assertStringStartsWith("file_id,file,vendor,total,issued,paid,items,_error,extracted_at\r\n", $this->folderFiles['invoices.csv']);
    }

    /**
     * A document answered wrongly is not sent again on the next run, only
     * once it or the fields change; a valid answer then clears its reason,
     * and the reason column goes once no row has one.
     */
    public function testAnInvalidAnswerIsNotRetriedUntilTheSourceOrTheFieldsChange(): void {
        $folder = $this->folder();
        $calls = 0;
        $answer = '{"vendor": "Beta", "total": "x"}';
        $p = $this->createStub(ClaudeSDKService::class);
        $p->method('ask')->willReturnCallback(function () use (&$calls, &$answer): array {
            $calls++;
            return ['response' => $answer, 'usage' => []];
        });
        $runner = $this->runner($p);
        $source = $this->source(2, 'b.txt', $folder, time() - 60);

        $runner->process($this->ctx(), new CoworkerRun(), [$source], static function (): void {
        });
        $this->assertSame(1, $calls);
        $this->assertSame([], $runner->collect($this->ctx(), $source), 'not billed again');

        $edited = $this->source(2, 'b.txt', $folder, time() + 3600);
        $this->assertSame([$edited], $runner->collect($this->ctx(), $edited), 'the source changed');
        $schema = self::OPTIONS;
        $schema['fields'][] = ['name' => 'currency', 'type' => 'string'];
        $this->assertSame([$source], $runner->collect($this->ctx($schema), $source), 'the fields changed');

        $answer = '{"vendor": "Beta", "total": 20}';
        $runner->process($this->ctx(), new CoworkerRun(), [$edited], static function (): void {
        });
        $this->assertStringStartsWith("file_id,file,vendor,total,issued,paid,items,extracted_at\r\n", $this->folderFiles['invoices.csv']);
        $this->assertSame('20', BatchDocumentRunner::csvRows($this->folderFiles['invoices.csv'])['2']['total']);
    }

    /** A rerun replaces a document's row and keeps the others, including rows of an older schema. */
    public function testRowsAreUpsertedByFileId(): void {
        $this->folderFiles['invoices.csv'] = "file_id,file,vendor,old_column,extracted_at\r\n1,/In/a.txt,Old,kept?,2026-01-01T00:00:00Z\r\n5,/In/e.txt,Echo,x,2026-01-01T00:00:00Z\r\n";
        $folder = $this->folder();
        $runner = $this->runner($this->provider(['invoice 1' => '{"vendor": "New"}']));

        $runner->process($this->ctx(), new CoworkerRun(), [$this->source(1, 'a.txt', $folder)], static function (): void {
        });

        $rows = BatchDocumentRunner::csvRows($this->folderFiles['invoices.csv']);
        $this->assertSame([1, 5], array_keys($rows));
        $this->assertSame('New', $rows['1']['vendor']);
        $this->assertSame('Echo', $rows['5']['vendor'], 'another document keeps its row');
        $this->assertArrayNotHasKey('old_column', $rows['5'], 'a column the schema dropped goes');
        $this->assertSame('', $rows['5']['total'], 'a column the schema added is empty until re-extracted');
    }

    /** Done while the row is newer than the source and from this schema; due again after either changes. */
    public function testARowIsCurrentUntilTheSourceOrTheSchemaChanges(): void {
        $folder = $this->folder();
        $runner = $this->runner($this->provider(['invoice 1' => '{"vendor": "A"}']));
        $source = $this->source(1, 'a.txt', $folder, time() - 60);

        $this->assertSame([$source], $runner->collect($this->ctx(), $source));
        $runner->process($this->ctx(), new CoworkerRun(), [$source], static function (): void {
        });
        $this->assertSame([], $runner->collect($this->ctx(), $source), 'extracted and current');

        $edited = $this->source(1, 'a.txt', $folder, time() + 3600);
        $this->assertSame([$edited], $runner->collect($this->ctx(), $edited), 'the source changed after its row');

        $schema = self::OPTIONS;
        $schema['fields'][] = ['name' => 'currency', 'type' => 'string'];
        $this->assertSame([$source], $runner->collect($this->ctx($schema), $source), 'the schema changed');
    }

    /** A CSV of the user's own that has the table's name is neither read nor replaced. */
    public function testAUsersCsvWithTheTablesNameIsLeftAlone(): void {
        $this->folderFiles['invoices.csv'] = $mine = "date,amount\r\n2026-01-01,12\r\n";
        $folder = $this->folder();
        $runner = $this->runner($this->provider(['invoice 1' => '{"vendor": "A"}']));
        $source = $this->source(1, 'a.txt', $folder);

        $skipped = [];
        $this->assertSame([], $runner->collect($this->ctx(), $source, $skipped));
        $this->assertSame([['item' => '/In/a.txt', 'reason' => 'invoices.csv already exists and was not written by AIquila; rename it or choose an output folder']], $skipped);

        // It appeared after the run was submitted.
        $result = $runner->process($this->ctx(), new CoworkerRun(), [$source], static function (): void {
        });
        $this->assertSame(0, $result['itemsProcessed']);
        $this->assertStringContainsString('Unsaved: a.txt — invoices.csv already exists', $result['summary']);
        $this->assertSame($mine, $this->folderFiles['invoices.csv']);
    }

    public function testATableThatCannotBeWrittenLosesItsRows(): void {
        $this->failWrites = true;
        $folder = $this->folder();
        $runner = $this->runner($this->provider(['invoice 1' => '{"vendor": "A"}', 'invoice 2' => '{"vendor": "B"}']));

        $result = $runner->process($this->ctx(), new CoworkerRun(), [$this->source(1, 'a.txt', $folder), $this->source(2, 'b.txt', $folder)], static function (): void {
        });

        $this->assertSame(0, $result['itemsProcessed']);
        $this->assertSame(2, $result['itemsFailed']);
        $this->assertStringContainsString('Unsaved: a.txt — disk full', $result['summary']);
        $this->assertSame([], $this->tagged, 'no source is marked done');
    }
}
