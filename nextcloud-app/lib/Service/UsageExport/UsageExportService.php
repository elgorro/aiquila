<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\UsageExport;

use OCA\AIquila\Db\UsageStatMapper;
use OCP\IGroupManager;

/**
 * Turns `aiquila_usage_stats` into export rows, one user at a time.
 *
 * Everything is a generator: per-request rows come from the mapper in pages
 * (keyset on the row id), day and week sums straight off an SQL GROUP BY, and
 * month sums from rolling consecutive day buckets together. Memory stays
 * bounded by one page, or by the model × provider × type combinations of one
 * user's month, whatever the period covers.
 *
 * Who may see which users is decided by the caller (the controller or occ);
 * this class exports whatever user list it is given.
 */
class UsageExportService {
    public const PAGE_SIZE = 1000;

    public const REQUEST_COLUMNS = ['timestamp', 'user', 'provider', 'model', 'request_type', 'conversation_id',
        'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens'];
    public const AGGREGATE_COLUMNS = ['period_start', 'user', 'provider', 'model', 'request_type', 'requests',
        'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens'];

    public function __construct(
        private UsageStatMapper $mapper,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * The columns of an export, in order. `user` only appears when the export
     * can span several users.
     *
     * @return list<string>
     */
    public function columns(UsageExportFilter $filter, bool $includeUser): array {
        $columns = $filter->isAggregated() ? self::AGGREGATE_COLUMNS : self::REQUEST_COLUMNS;
        return $includeUser ? $columns : array_values(array_filter($columns, static fn (string $c): bool => $c !== 'user'));
    }

    /**
     * Every user with usage matching $filter.
     *
     * @return list<string>
     */
    public function allUserIds(UsageExportFilter $filter): array {
        return $this->mapper->exportUserIds($filter);
    }

    /**
     * The members of $groupId, sorted, or null when there is no such group.
     *
     * @return list<string>|null
     */
    public function groupUserIds(string $groupId): ?array {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return null;
        }
        $ids = array_map(static fn ($user): string => $user->getUID(), $group->getUsers());
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * The export rows for $userIds, in that order, each keyed by column name
     * with `user` left out unless $includeUser.
     *
     * @param list<string> $userIds
     * @return \Generator<int, array<string, int|string|null>>
     */
    public function rows(UsageExportFilter $filter, array $userIds, bool $includeUser): \Generator {
        foreach ($userIds as $userId) {
            $rows = match ($filter->granularity) {
                UsageExportFilter::GRANULARITY_REQUEST => $this->requestRows($userId, $filter),
                UsageExportFilter::GRANULARITY_MONTH => self::monthRows($this->dayRows($userId, $filter, 'day')),
                UsageExportFilter::GRANULARITY_WEEK => $this->dayRows($userId, $filter, 'week'),
                default => $this->dayRows($userId, $filter, 'day'),
            };
            foreach ($rows as $row) {
                yield $includeUser ? self::withUser($row, $userId) : $row;
            }
        }
    }

    /**
     * The whole export as encoded chunks: rows() through UsageExportWriter,
     * with the export's parameters as the JSON head.
     *
     * @param list<string> $userIds
     * @return \Generator<int, string>
     */
    public function stream(UsageExportFilter $filter, array $userIds, bool $includeUser, int $now): \Generator {
        $meta = [
            'granularity' => $filter->granularity,
            'from' => $filter->from === null ? null : gmdate('Y-m-d\TH:i:s\Z', $filter->from),
            'to' => $filter->to === null ? null : gmdate('Y-m-d\TH:i:s\Z', $filter->to),
            'model' => $filter->model,
            'provider' => $filter->provider,
            'request_type' => $filter->requestType,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
        return UsageExportWriter::chunks(
            $filter->format,
            $this->columns($filter, $includeUser),
            $this->rows($filter, $userIds, $includeUser),
            $meta,
        );
    }

    /** Download name, e.g. `aiquila-usage-day-2026-11-21.csv`. */
    public static function filename(UsageExportFilter $filter, int $now): string {
        return sprintf('aiquila-usage-%s-%s.%s', $filter->granularity, gmdate('Y-m-d', $now), $filter->format);
    }

    /** @return \Generator<int, array<string, int|string|null>> */
    private function requestRows(string $userId, UsageExportFilter $filter): \Generator {
        $afterId = 0;
        do {
            $page = $this->mapper->exportRequestPage($userId, $filter, $afterId, self::PAGE_SIZE);
            foreach ($page as $row) {
                $afterId = $row['id'];
                yield [
                    'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $row['created_at']),
                    'provider' => $row['provider'],
                    'model' => $row['model'],
                    'request_type' => $row['request_type'],
                    'conversation_id' => $row['conversation_id'],
                    'input_tokens' => $row['input_tokens'],
                    'output_tokens' => $row['output_tokens'],
                    'cache_creation_tokens' => $row['cache_creation_tokens'],
                    'cache_read_tokens' => $row['cache_read_tokens'],
                ];
            }
        } while (count($page) === self::PAGE_SIZE);
    }

    /**
     * @param 'day'|'week' $bucket
     * @return \Generator<int, array<string, int|string|null>>
     */
    private function dayRows(string $userId, UsageExportFilter $filter, string $bucket): \Generator {
        foreach ($this->mapper->exportAggregates($userId, $filter, $bucket) as $row) {
            yield [
                'period_start' => gmdate('Y-m-d', $row['bucket']),
                'provider' => $row['provider'],
                'model' => $row['model'],
                'request_type' => $row['request_type'],
                'requests' => $row['requests'],
                'input_tokens' => $row['input_tokens'],
                'output_tokens' => $row['output_tokens'],
                'cache_creation_tokens' => $row['cache_creation_tokens'],
                'cache_read_tokens' => $row['cache_read_tokens'],
            ];
        }
    }

    /**
     * Rolls day rows (oldest first) up into calendar months. A month is
     * emitted once the first day of the next one arrives, sorted by model,
     * provider and request type like the day and week exports.
     *
     * @param iterable<array<string, int|string|null>> $days
     * @return \Generator<int, array<string, int|string|null>>
     */
    public static function monthRows(iterable $days): \Generator {
        $month = null;
        /** @var array<string, array<string, int|string|null>> $sums */
        $sums = [];
        foreach ($days as $day) {
            $dayMonth = substr((string)$day['period_start'], 0, 7) . '-01';
            if ($month !== null && $dayMonth !== $month) {
                yield from self::flushMonth($sums);
                $sums = [];
            }
            $month = $dayMonth;
            $key = implode("\0", [(string)$day['model'], (string)$day['provider'], (string)$day['request_type']]);
            if (!isset($sums[$key])) {
                $sums[$key] = ['period_start' => $dayMonth] + $day;
                continue;
            }
            foreach (['requests', 'input_tokens', 'output_tokens'] as $column) {
                $sums[$key][$column] = (int)$sums[$key][$column] + (int)$day[$column];
            }
            foreach (['cache_creation_tokens', 'cache_read_tokens'] as $column) {
                if ($day[$column] !== null) {
                    $sums[$key][$column] = (int)($sums[$key][$column] ?? 0) + (int)$day[$column];
                }
            }
        }
        yield from self::flushMonth($sums);
    }

    /**
     * @param array<string, array<string, int|string|null>> $sums
     * @return \Generator<int, array<string, int|string|null>>
     */
    private static function flushMonth(array $sums): \Generator {
        ksort($sums, SORT_STRING);
        foreach ($sums as $row) {
            yield $row;
        }
    }

    /**
     * $row with `user` inserted after the first (time) column.
     *
     * @param array<string, int|string|null> $row
     * @return array<string, int|string|null>
     */
    private static function withUser(array $row, string $userId): array {
        $first = array_slice($row, 0, 1, true);
        return $first + ['user' => $userId] + array_slice($row, 1, null, true);
    }
}
