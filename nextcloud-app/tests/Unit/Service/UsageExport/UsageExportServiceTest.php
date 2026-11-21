<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service\UsageExport;

use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Export rows from seeded usage: per request, per day / week from SQL sums,
 * and per month rolled up from days; columns, user order and streaming.
 */
class UsageExportServiceTest extends TestCase {
    private const NOW = 1795651200; // 2026-11-26T00:00:00Z

    /**
     * Seeded per-request rows by user, as exportRequestPage() returns them.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $requests = [];

    /** @var array<string, list<array<string, mixed>>> exportAggregates() rows by "user|bucket" */
    private array $aggregates = [];

    /** @var list<array{string, int, int}> exportRequestPage() calls: user, afterId, limit */
    private array $pageCalls = [];

    private function service(?IGroupManager $groups = null): UsageExportService {
        $mapper = $this->createStub(UsageStatMapper::class);
        $mapper->method('exportRequestPage')->willReturnCallback(
            function (string $userId, UsageExportFilter $f, int $afterId, int $limit): array {
                $this->pageCalls[] = [$userId, $afterId, $limit];
                $rows = array_values(array_filter($this->requests[$userId] ?? [], static fn (array $r): bool => $r['id'] > $afterId));
                return array_slice($rows, 0, $limit);
            },
        );
        $mapper->method('exportAggregates')->willReturnCallback(
            fn (string $userId, UsageExportFilter $f, string $bucket): \Generator => (function () use ($userId, $bucket) {
                yield from $this->aggregates[$userId . '|' . $bucket] ?? [];
            })(),
        );
        $mapper->method('exportUserIds')->willReturn(['', 'alice', 'bob']);

        return new UsageExportService($mapper, $groups ?? $this->createStub(IGroupManager::class));
    }

    private static function request(int $id, int $time, string $model = 'claude-opus-5', ?string $provider = 'anthropic', string $type = 'chat', ?int $cache = null): array {
        return [
            'id' => $id,
            'created_at' => $time,
            'provider' => $provider,
            'model' => $model,
            'request_type' => $type,
            'conversation_id' => $type === 'chat' ? 7 : null,
            'input_tokens' => 100 + $id,
            'output_tokens' => 10 + $id,
            'cache_creation_tokens' => $cache,
            'cache_read_tokens' => $cache,
        ];
    }

    private static function day(string $date, string $model, int $requests, int $input, ?int $cacheRead = null, string $type = 'chat', ?string $provider = 'anthropic'): array {
        return [
            'bucket' => (int)strtotime($date . 'T00:00:00Z'),
            'provider' => $provider,
            'model' => $model,
            'request_type' => $type,
            'requests' => $requests,
            'input_tokens' => $input,
            'output_tokens' => $input / 10,
            'cache_creation_tokens' => null,
            'cache_read_tokens' => $cacheRead,
        ];
    }

    /** @return list<array<string, int|string|null>> */
    private static function all(\Generator $rows): array {
        return iterator_to_array($rows, false);
    }

    public function testPerRequestRowsForOneUser(): void {
        $this->requests['alice'] = [
            self::request(1, 1767225600),               // 2026-01-01T00:00:00Z
            self::request(2, 1767229261, 'mistral-large', null, 'task', 50),
        ];
        $filter = UsageExportFilter::fromInput();

        $rows = self::all($this->service()->rows($filter, ['alice'], false));

        $this->assertSame([
            [
                'timestamp' => '2026-01-01T00:00:00Z', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
                'request_type' => 'chat', 'conversation_id' => 7, 'input_tokens' => 101, 'output_tokens' => 11,
                'cache_creation_tokens' => null, 'cache_read_tokens' => null,
            ],
            [
                'timestamp' => '2026-01-01T01:01:01Z', 'provider' => null, 'model' => 'mistral-large',
                'request_type' => 'task', 'conversation_id' => null, 'input_tokens' => 102, 'output_tokens' => 12,
                'cache_creation_tokens' => 50, 'cache_read_tokens' => 50,
            ],
        ], $rows);
        // The personal export has no user column.
        $this->assertSame(
            ['timestamp', 'provider', 'model', 'request_type', 'conversation_id', 'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens'],
            $this->service()->columns($filter, false),
        );
    }

    public function testAdminRowsCarryTheUserAfterTheTimeAndFollowTheGivenUserOrder(): void {
        $this->requests['bob'] = [self::request(3, 1767225600)];
        $this->requests['alice'] = [self::request(1, 1767225600), self::request(2, 1767225700)];
        $filter = UsageExportFilter::fromInput();

        $rows = self::all($this->service()->rows($filter, ['alice', 'bob', 'nobody'], true));

        $this->assertSame(['alice', 'alice', 'bob'], array_column($rows, 'user'));
        $this->assertSame(['timestamp', 'user', 'provider'], array_slice(array_keys($rows[0]), 0, 3));
        $this->assertSame(array_keys($rows[0]), $this->service()->columns($filter, true));
    }

    public function testPagesWithTheLastIdUntilAShortPage(): void {
        $count = UsageExportService::PAGE_SIZE * 2 + 1;
        for ($i = 1; $i <= $count; $i++) {
            $this->requests['alice'][] = self::request($i, 1767225600 + $i);
        }

        $rows = self::all($this->service()->rows(UsageExportFilter::fromInput(), ['alice'], false));

        $this->assertCount($count, $rows);
        $this->assertSame([
            ['alice', 0, UsageExportService::PAGE_SIZE],
            ['alice', UsageExportService::PAGE_SIZE, UsageExportService::PAGE_SIZE],
            ['alice', UsageExportService::PAGE_SIZE * 2, UsageExportService::PAGE_SIZE],
        ], $this->pageCalls);
    }

    public function testAnExactMultipleOfThePageSizeEndsOnAnEmptyPage(): void {
        for ($i = 1; $i <= UsageExportService::PAGE_SIZE; $i++) {
            $this->requests['alice'][] = self::request($i, 1767225600 + $i);
        }

        $rows = self::all($this->service()->rows(UsageExportFilter::fromInput(), ['alice'], false));

        $this->assertCount(UsageExportService::PAGE_SIZE, $rows);
        $this->assertCount(2, $this->pageCalls);
    }

    public function testDayAndWeekRowsComeFromTheSqlSums(): void {
        $this->aggregates['alice|day'] = [self::day('2026-01-01', 'claude-opus-5', 3, 300, 40)];
        $this->aggregates['alice|week'] = [self::day('2025-12-29', 'claude-opus-5', 9, 900)];

        $day = self::all($this->service()->rows(UsageExportFilter::fromInput(granularity: 'day'), ['alice'], true));
        $week = self::all($this->service()->rows(UsageExportFilter::fromInput(granularity: 'week'), ['alice'], true));

        $this->assertSame([[
            'period_start' => '2026-01-01', 'user' => 'alice', 'provider' => 'anthropic', 'model' => 'claude-opus-5',
            'request_type' => 'chat', 'requests' => 3, 'input_tokens' => 300, 'output_tokens' => 30,
            'cache_creation_tokens' => null, 'cache_read_tokens' => 40,
        ]], $day);
        // The ISO week of 2026-01-01 starts on Monday 2025-12-29.
        $this->assertSame('2025-12-29', $week[0]['period_start']);
        $this->assertSame(9, $week[0]['requests']);
    }

    public function testMonthRowsSumDaysPerModelProviderAndType(): void {
        $this->aggregates['alice|day'] = [
            self::day('2026-01-03', 'claude-opus-5', 1, 100, null),
            self::day('2026-01-03', 'claude-opus-5', 1, 100, null, 'task'),
            self::day('2026-01-20', 'claude-opus-5', 2, 200, 15),
            self::day('2026-01-31', 'a-model', 1, 50, null, 'chat', null),
            self::day('2026-02-01', 'claude-opus-5', 4, 400, null),
        ];

        $rows = self::all($this->service()->rows(UsageExportFilter::fromInput(granularity: 'month'), ['alice'], false));

        $this->assertSame([
            ['2026-01-01', 'a-model', null, 'chat', 1, 50, 5, null],
            ['2026-01-01', 'claude-opus-5', 'anthropic', 'chat', 3, 300, 30, 15],
            ['2026-01-01', 'claude-opus-5', 'anthropic', 'task', 1, 100, 10, null],
            ['2026-02-01', 'claude-opus-5', 'anthropic', 'chat', 4, 400, 40, null],
        ], array_map(static fn (array $r): array => [
            $r['period_start'], $r['model'], $r['provider'], $r['request_type'],
            $r['requests'], $r['input_tokens'], $r['output_tokens'], $r['cache_read_tokens'],
        ], $rows));
        $this->assertSame(array_keys($rows[0]), $this->service()->columns(UsageExportFilter::fromInput(granularity: 'month'), false));
    }

    public function testMonthRollupStartsFreshForEachUser(): void {
        $this->aggregates['alice|day'] = [self::day('2026-01-05', 'm', 1, 10)];
        $this->aggregates['bob|day'] = [self::day('2026-01-06', 'm', 2, 20)];

        $rows = self::all($this->service()->rows(UsageExportFilter::fromInput(granularity: 'month'), ['alice', 'bob'], true));

        $this->assertSame([['alice', 1], ['bob', 2]], array_map(static fn (array $r): array => [$r['user'], $r['requests']], $rows));
    }

    public function testGroupMembersAreSortedAndAnUnknownGroupIsNull(): void {
        $user = function (string $uid): IUser {
            $u = $this->createStub(IUser::class);
            $u->method('getUID')->willReturn($uid);
            return $u;
        };
        $group = $this->createStub(IGroup::class);
        $group->method('getUsers')->willReturn([$user('zoe'), $user('alice'), $user('mike')]);
        $groups = $this->createStub(IGroupManager::class);
        $groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'finance' ? $group : null);

        $this->assertSame(['alice', 'mike', 'zoe'], $this->service($groups)->groupUserIds('finance'));
        $this->assertNull($this->service($groups)->groupUserIds('nope'));
    }

    public function testStreamWritesTheMetaHeadInJson(): void {
        $this->requests['alice'] = [self::request(1, 1767225600)];
        $filter = UsageExportFilter::fromInput('json', 'request', '2026-01-01', '2026-01-31', null, 'anthropic', 'chat');

        $doc = json_decode(implode('', iterator_to_array($this->service()->stream($filter, ['alice'], false, self::NOW), false)), true);

        $this->assertSame('request', $doc['granularity']);
        $this->assertSame('2026-01-01T00:00:00Z', $doc['from']);
        $this->assertSame('2026-02-01T00:00:00Z', $doc['to']);
        $this->assertSame('anthropic', $doc['provider']);
        $this->assertSame('chat', $doc['request_type']);
        $this->assertSame('2026-11-26T00:00:00Z', $doc['generated_at']);
        $this->assertCount(1, $doc['rows']);
        $this->assertSame('aiquila-usage-request-2026-11-26.json', UsageExportService::filename($filter, self::NOW));
    }

    public function testAHundredThousandRowsStreamInBoundedMemory(): void {
        // Rows are generated on demand by the mapper double, a page at a time,
        // exactly as the database hands them out.
        $total = 100_000;
        $mapper = $this->createStub(UsageStatMapper::class);
        $mapper->method('exportRequestPage')->willReturnCallback(
            static function (string $u, UsageExportFilter $f, int $afterId, int $limit) use ($total): array {
                $page = [];
                for ($id = $afterId + 1; $id <= min($total, $afterId + $limit); $id++) {
                    $page[] = self::request($id, 1767225600 + $id, 'claude-opus-5', 'anthropic', 'chat', $id);
                }
                return $page;
            },
        );
        $service = new UsageExportService($mapper, $this->createStub(IGroupManager::class));

        foreach (['csv', 'json'] as $format) {
            $filter = UsageExportFilter::fromInput($format);
            gc_collect_cycles();
            $before = memory_get_usage();
            $peak = 0;
            $bytes = 0;
            $lines = 0;
            foreach ($service->stream($filter, ['alice'], true, self::NOW) as $chunk) {
                $bytes += strlen($chunk);
                $lines += substr_count($chunk, "\n");
                $peak = max($peak, memory_get_usage() - $before);
            }

            // CSV: header + one line per row. JSON: the head line, one line
            // per row and the closing line.
            $this->assertSame($format === 'csv' ? $total + 1 : $total + 2, $lines, $format);
            // Several MB went out; what was held at any point stays at a
            // page of rows plus one chunk.
            $this->assertGreaterThan(5_000_000, $bytes, $format);
            $this->assertLessThan(4 * 1024 * 1024, $peak, $format);
        }
    }
}
