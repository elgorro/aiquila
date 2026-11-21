<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service\UsageExport;

use OCA\AIquila\Service\UsageExport\UsageExportWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV per RFC 4180 and the JSON document, as streamed chunks.
 */
class UsageExportWriterTest extends TestCase {
    private const COLUMNS = ['timestamp', 'user', 'model', 'input_tokens', 'cache_read_tokens'];

    private static function write(string $format, iterable $rows, array $meta = []): string {
        return implode('', iterator_to_array(UsageExportWriter::chunks($format, self::COLUMNS, $rows, $meta), false));
    }

    public function testCsvHasAHeaderAndCrlfLines(): void {
        $csv = self::write('csv', [
            ['timestamp' => '2026-01-01T00:00:00Z', 'user' => 'alice', 'model' => 'm1', 'input_tokens' => 10, 'cache_read_tokens' => null],
        ]);

        // RFC 4180 §2.1 (CRLF), §2.3 (header), and an empty field for null.
        $this->assertSame(
            "timestamp,user,model,input_tokens,cache_read_tokens\r\n"
            . "2026-01-01T00:00:00Z,alice,m1,10,\r\n",
            $csv,
        );
    }

    public function testCsvWithNoRowsIsJustTheHeader(): void {
        $this->assertSame("timestamp,user,model,input_tokens,cache_read_tokens\r\n", self::write('csv', []));
    }

    /** @return array<string, array{int|string|null, string}> */
    public static function csvFields(): array {
        return [
            'plain' => ['alice', 'alice'],
            'integer' => [42, '42'],
            'null' => [null, ''],
            'empty string' => ['', ''],
            // RFC 4180 §2.6: comma, quote and line breaks need quoting; §2.7: inner quotes double.
            'comma' => ['a,b', '"a,b"'],
            'quote' => ['say "hi"', '"say ""hi"""'],
            'line feed' => ["a\nb", "\"a\nb\""],
            'carriage return mid-field' => ["a\rb", "\"a\rb\""],
            'unicode' => ['Zoë', 'Zoë'],
            // OWASP CSV injection: a spreadsheet must not read these as formulas.
            'equals' => ['=HYPERLINK("x")', '"\'=HYPERLINK(""x"")"'],
            'plus' => ['+1', "'+1"],
            'minus' => ['-1', "'-1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\tx", "'\tx"],
            'leading carriage return' => ["\rx", "\"'\rx\""],
            'negative integer stays a number' => [-5, '-5'],
        ];
    }

    #[DataProvider('csvFields')]
    public function testCsvField(int|string|null $value, string $expected): void {
        $this->assertSame($expected, UsageExportWriter::csvField($value));
    }

    public function testCsvRoundTripsThroughAParser(): void {
        $rows = [
            ['timestamp' => 't1', 'user' => 'o\'brien, jr', 'model' => "multi\nline", 'input_tokens' => 1, 'cache_read_tokens' => 2],
            ['timestamp' => 't2', 'user' => 'q"uote', 'model' => 'm', 'input_tokens' => 3, 'cache_read_tokens' => null],
        ];
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, self::write('csv', $rows));
        rewind($handle);
        $parsed = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $parsed[] = $line;
        }
        fclose($handle);

        $this->assertSame(self::COLUMNS, $parsed[0]);
        $this->assertSame(['t1', 'o\'brien, jr', "multi\nline", '1', '2'], $parsed[1]);
        $this->assertSame(['t2', 'q"uote', 'm', '3', ''], $parsed[2]);
    }

    public function testJsonIsOneDocumentWithMetaAndTypedRows(): void {
        $json = self::write('json', [
            ['timestamp' => 't1', 'user' => 'alice', 'model' => 'm1', 'input_tokens' => 10, 'cache_read_tokens' => null],
            ['timestamp' => 't2', 'user' => 'bob', 'model' => 'm2', 'input_tokens' => 20, 'cache_read_tokens' => 5, 'ignored' => 'x'],
        ], ['granularity' => 'request', 'from' => null]);

        $this->assertSame([
            'granularity' => 'request',
            'from' => null,
            'rows' => [
                ['timestamp' => 't1', 'user' => 'alice', 'model' => 'm1', 'input_tokens' => 10, 'cache_read_tokens' => null],
                ['timestamp' => 't2', 'user' => 'bob', 'model' => 'm2', 'input_tokens' => 20, 'cache_read_tokens' => 5],
            ],
        ], json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonWithNoRowsIsAnEmptyArray(): void {
        $this->assertSame(['rows' => []], json_decode(self::write('json', []), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonSubstitutesInvalidUtf8InsteadOfAborting(): void {
        $json = self::write('json', [['timestamp' => 't', 'user' => 'u', 'model' => "bad\xC3", 'input_tokens' => 1, 'cache_read_tokens' => null]]);

        $this->assertSame("bad\u{FFFD}", json_decode($json, true, 512, JSON_THROW_ON_ERROR)['rows'][0]['model']);
    }

    public function testOutputIsBufferedIntoChunks(): void {
        $rows = (function () {
            for ($i = 0; $i < 5000; $i++) {
                yield ['timestamp' => '2026-01-01T00:00:00Z', 'user' => 'alice', 'model' => 'claude-opus-5', 'input_tokens' => $i, 'cache_read_tokens' => $i];
            }
        })();

        $chunks = iterator_to_array(UsageExportWriter::chunks('csv', self::COLUMNS, $rows), false);

        $this->assertGreaterThan(1, count($chunks));
        foreach (array_slice($chunks, 0, -1) as $chunk) {
            $this->assertGreaterThanOrEqual(UsageExportWriter::CHUNK_BYTES, strlen($chunk));
            // A chunk never ends mid-row.
            $this->assertStringEndsWith("\r\n", $chunk);
        }
    }

    public function testContentTypes(): void {
        $this->assertSame('text/csv; charset=utf-8; header=present', UsageExportWriter::contentType('csv'));
        $this->assertSame('application/json; charset=utf-8', UsageExportWriter::contentType('json'));
    }
}
