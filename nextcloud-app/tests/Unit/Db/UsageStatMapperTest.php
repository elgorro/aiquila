<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;

class UsageStatMapperTest extends MapperTestCase {

    private function makeMapper(bool $mock = false): UsageStatMapper {
        return $this->partialMapper(UsageStatMapper::class, ['findEntities'], $mock);
    }

    public function testFindByUserCallsFindEntities(): void {
        $stat = new UsageStat();
        $stat->setUserId('eve');

        $mapper = $this->makeMapper(mock: true);
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$stat]);

        $result = $mapper->findByUser('eve');

        $this->assertCount(1, $result);
        $this->assertSame($stat, $result[0]);
    }

    public function testFindByUserAppliesDefaultLimit(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(100)
            ->willReturnSelf();

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(0)
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }

    public function testFindByUserRespectsCustomLimitAndOffset(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(25)
            ->willReturnSelf();

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(50)
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve', 25, 50);
    }

    public function testFindByUserOrdersByCreatedAtDesc(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('created_at', 'DESC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }

    public function testSumTokensByUserReturnsCorrectTotals(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')
            ->willReturn(['total_input' => '1500', 'total_output' => '750', 'total_cache_creation' => '100', 'total_cache_read' => '200']);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $totals = $mapper->sumTokensByUser('eve');

        $this->assertEquals(['input_tokens' => 1500, 'output_tokens' => 750, 'cache_creation_tokens' => 100, 'cache_read_tokens' => 200], $totals);
    }

    public function testSumTokensByUserReturnsZeroWhenNoRows(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $totals = $mapper->sumTokensByUser('eve');

        $this->assertEquals(['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0], $totals);
    }

    public function testSumTokensByModelAndTypeAggregatesRows(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['model' => 'claude-opus-4-8', 'request_type' => 'chat', 'total_input' => '1000', 'total_output' => '500', 'total_cache_creation' => '900', 'total_cache_read' => '2700'],
            // SUM over only-NULL cache columns comes back NULL.
            ['model' => 'claude-haiku-4-5', 'request_type' => 'summary', 'total_input' => '20', 'total_output' => '10', 'total_cache_creation' => null, 'total_cache_read' => null],
            false,
        );
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $rows = $mapper->sumTokensByModelAndType();

        $this->assertSame([
            ['model' => 'claude-opus-4-8', 'request_type' => 'chat', 'input_tokens' => 1000, 'output_tokens' => 500, 'cache_creation_tokens' => 900, 'cache_read_tokens' => 2700],
            ['model' => 'claude-haiku-4-5', 'request_type' => 'summary', 'input_tokens' => 20, 'output_tokens' => 10, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0],
        ], $rows);
    }

    public function testSumTokensByModelAndTypeEmpty(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $this->assertSame([], $mapper->sumTokensByModelAndType());
    }

    public function testTableName(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_usage_stats')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }

    /**
     * The SQL bucket expressions exportAggregates() groups on.
     *
     * @return list<string>
     */
    private function bucketExpressions(string $platform, string $bucket, array $rows = []): array {
        $this->db->method('getDatabaseProvider')->willReturn($platform);
        $functions = [];
        $this->qb->method('getColumnName')->willReturnCallback(static fn (string $c): string => '`' . $c . '`');
        $this->qb->method('createFunction')->willReturnCallback(function (string $sql) use (&$functions) {
            $functions[] = $sql;
            return $this->queryFunction($sql);
        });
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

        $mapper = new UsageStatMapper($this->db);
        iterator_to_array($mapper->exportAggregates('alice', UsageExportFilter::fromInput(granularity: $bucket), $bucket));
        return $functions;
    }

    /** @return array<string, array{string, string, string}> */
    public static function bucketSql(): array {
        return [
            'day, postgres' => [IDBConnection::PLATFORM_POSTGRES, 'day', '(`created_at` - ((`created_at`) % 86400))'],
            'day, sqlite' => [IDBConnection::PLATFORM_SQLITE, 'day', '(`created_at` - ((`created_at`) % 86400))'],
            'day, oracle' => [IDBConnection::PLATFORM_ORACLE, 'day', '(`created_at` - MOD(`created_at`, 86400))'],
            // Monday 00:00 UTC: the epoch was a Thursday, three days later in the week.
            'week, mysql' => [IDBConnection::PLATFORM_MYSQL, 'week', '(`created_at` - ((`created_at` + 259200) % 604800))'],
            'week, oracle' => [IDBConnection::PLATFORM_ORACLE, 'week', '(`created_at` - MOD(`created_at` + 259200, 604800))'],
        ];
    }

    /** The bucket goes in both the SELECT and the GROUP BY (Oracle rejects aliases there). */
    #[DataProvider('bucketSql')]
    public function testExportBucketsUseTheDatabasesModulo(string $platform, string $bucket, string $expected): void {
        $this->assertSame([$expected, $expected], $this->bucketExpressions($platform, $bucket));
    }

    public function testExportBucketArithmeticLandsOnDayAndMondayStarts(): void {
        // The same arithmetic as the SQL, on a Sunday afternoon.
        $t = gmmktime(15, 30, 0, 11, 22, 2026);
        $this->assertSame('2026-11-22 00:00', gmdate('Y-m-d H:i', $t - $t % 86400));
        $this->assertSame('2026-11-16 00:00 Mon', gmdate('Y-m-d H:i D', $t - ($t + 259200) % 604800));
        $monday = gmmktime(0, 0, 0, 11, 23, 2026);
        $this->assertSame($monday, $monday - ($monday + 259200) % 604800);
    }

    public function testExportAggregatesCastsTheRow(): void {
        $this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
        $this->qb->method('getColumnName')->willReturnArgument(0);
        $this->qb->method('createFunction')->willReturnCallback(fn (string $sql) => $this->queryFunction($sql));
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['bucket' => '1767225600', 'provider' => '', 'model' => 'm', 'request_type' => 'chat', 'requests' => '3',
                'input_tokens' => '30', 'output_tokens' => '3', 'cache_creation_tokens' => null, 'cache_read_tokens' => '9'],
            false,
        );

        $rows = iterator_to_array((new UsageStatMapper($this->db))->exportAggregates('alice', UsageExportFilter::fromInput(), 'day'), false);

        $this->assertSame([[
            'bucket' => 1767225600, 'provider' => null, 'model' => 'm', 'request_type' => 'chat', 'requests' => 3,
            'input_tokens' => 30, 'output_tokens' => 3, 'cache_creation_tokens' => null, 'cache_read_tokens' => 9,
        ]], $rows);
    }
}
