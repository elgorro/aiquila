<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service\UsageExport;

use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parameters of a usage export, validated once at the edge.
 */
class UsageExportFilterTest extends TestCase {
    public function testDefaultsToPerRequestCsvOverAllTime(): void {
        $filter = UsageExportFilter::fromInput();

        $this->assertSame('csv', $filter->format);
        $this->assertSame('request', $filter->granularity);
        $this->assertNull($filter->from);
        $this->assertNull($filter->to);
        $this->assertNull($filter->model);
        $this->assertNull($filter->provider);
        $this->assertNull($filter->requestType);
        $this->assertFalse($filter->isAggregated());
    }

    public function testBlankValuesMeanNoFilter(): void {
        $filter = UsageExportFilter::fromInput('', ' ', '', '', '  ', '', '');

        $this->assertSame('csv', $filter->format);
        $this->assertSame('request', $filter->granularity);
        $this->assertNull($filter->model);
    }

    public function testABareDateIsAWholeUtcDay(): void {
        $filter = UsageExportFilter::fromInput(from: '2026-01-01', to: '2026-01-31');

        $this->assertSame(gmmktime(0, 0, 0, 1, 1, 2026), $filter->from);
        // `to` is exclusive internally, so the 31st is included up to its end.
        $this->assertSame(gmmktime(0, 0, 0, 2, 1, 2026), $filter->to);
    }

    public function testADateTimeKeepsItsOffset(): void {
        $filter = UsageExportFilter::fromInput(from: '2026-03-01T10:00:00+02:00', to: '2026-03-01T12:30:00Z');

        $this->assertSame(gmmktime(8, 0, 0, 3, 1, 2026), $filter->from);
        $this->assertSame(gmmktime(12, 30, 0, 3, 1, 2026), $filter->to);
    }

    public function testKeepsFiltersAndAggregation(): void {
        $filter = UsageExportFilter::fromInput('json', 'month', null, null, 'claude-opus-5', 'anthropic', 'coworker');

        $this->assertSame('json', $filter->format);
        $this->assertTrue($filter->isAggregated());
        $this->assertSame('claude-opus-5', $filter->model);
        $this->assertSame('anthropic', $filter->provider);
        $this->assertSame('coworker', $filter->requestType);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidInput(): array {
        return [
            'format' => [['format' => 'xlsx'], 'format must be one of'],
            'granularity' => [['granularity' => 'hour'], 'granularity must be one of'],
            'request type' => [['requestType' => 'embedding'], 'requestType must be one of'],
            'model length' => [['model' => str_repeat('m', 101)], 'model is too long'],
            'provider length' => [['provider' => str_repeat('p', 33)], 'provider is too long'],
            'impossible date' => [['from' => '2026-02-30'], 'from is not a valid date'],
            'impossible date-time' => [['to' => '2026-02-30T00:00:00Z'], 'to is not a valid date-time'],
            'date-time without offset' => [['from' => '2026-01-01T00:00:00'], 'from must be an ISO 8601'],
            'unix seconds' => [['from' => '1767225600'], 'from must be an ISO 8601'],
            'empty period' => [['from' => '2026-02-01', 'to' => '2026-01-31'], 'to must be after from'],
        ];
    }

    /** @param array<string, string> $input */
    #[DataProvider('invalidInput')]
    public function testRejects(array $input, string $message): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);
        UsageExportFilter::fromInput(...$input);
    }
}
