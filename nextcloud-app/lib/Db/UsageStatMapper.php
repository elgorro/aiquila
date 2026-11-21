<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends ChangeTrackingMapper<UsageStat>
 */
class UsageStatMapper extends ChangeTrackingMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_usage_stats', UsageStat::class);
    }

    /**
     * @return list<UsageStat>
     */
    public function findByUser(string $userId, int $limit = 100, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    public function countByUser(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->count(), 'n')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    public function deleteByUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * @return array{input_tokens: int, output_tokens: int, cache_creation_tokens: int, cache_read_tokens: int}
     */
    public function sumTokensByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->sum('input_tokens'), 'total_input')
            ->selectAlias($qb->func()->sum('output_tokens'), 'total_output')
            ->selectAlias($qb->func()->sum('cache_creation_tokens'), 'total_cache_creation')
            ->selectAlias($qb->func()->sum('cache_read_tokens'), 'total_cache_read')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return [
            'input_tokens' => (int)($row['total_input'] ?? 0),
            'output_tokens' => (int)($row['total_output'] ?? 0),
            'cache_creation_tokens' => (int)($row['total_cache_creation'] ?? 0),
            'cache_read_tokens' => (int)($row['total_cache_read'] ?? 0),
        ];
    }

    /**
     * Sum input/output tokens for a user since a given Unix timestamp (inclusive).
     *
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function sumTokensByUserSince(string $userId, int $since): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->sum('input_tokens'), 'total_input')
            ->selectAlias($qb->func()->sum('output_tokens'), 'total_output')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return [
            'input_tokens' => (int)($row['total_input'] ?? 0),
            'output_tokens' => (int)($row['total_output'] ?? 0),
        ];
    }

    /**
     * Aggregate token usage across all users, grouped by model and request type.
     * Used for the server-global OpenMetrics export (no per-user breakdown).
     *
     * Cache tokens are summed separately from `input_tokens`: providers that
     * report them (Anthropic) count cached prefix tokens outside input.
     *
     * @return list<array{model: string, request_type: string, input_tokens: int, output_tokens: int, cache_creation_tokens: int, cache_read_tokens: int}>
     */
    public function sumTokensByModelAndType(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('model', 'request_type')
            ->selectAlias($qb->func()->sum('input_tokens'), 'total_input')
            ->selectAlias($qb->func()->sum('output_tokens'), 'total_output')
            ->selectAlias($qb->func()->sum('cache_creation_tokens'), 'total_cache_creation')
            ->selectAlias($qb->func()->sum('cache_read_tokens'), 'total_cache_read')
            ->from($this->getTableName())
            ->groupBy('model', 'request_type');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'model' => (string)($row['model'] ?? ''),
                'request_type' => (string)($row['request_type'] ?? ''),
                'input_tokens' => (int)($row['total_input'] ?? 0),
                'output_tokens' => (int)($row['total_output'] ?? 0),
                'cache_creation_tokens' => (int)($row['total_cache_creation'] ?? 0),
                'cache_read_tokens' => (int)($row['total_cache_read'] ?? 0),
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * The users with at least one row matching $filter, sorted. Talk guests
     * are stored under '' and come first.
     *
     * The export walks these one at a time, so its output stays grouped by
     * user and no single query has to sort the whole table.
     *
     * @return list<string>
     */
    public function exportUserIds(UsageExportFilter $filter): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from($this->getTableName());
        $this->applyExportFilter($qb, $filter);
        $qb->orderBy('user_id', 'ASC');

        $result = $qb->executeQuery();
        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (string)$row['user_id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * One page of $userId's rows matching $filter, oldest first, after row id
     * $afterId (keyset pagination: the caller passes the last id it got).
     *
     * @return list<array{id: int, created_at: int, provider: ?string, model: string, request_type: string, conversation_id: ?int, input_tokens: int, output_tokens: int, cache_creation_tokens: ?int, cache_read_tokens: ?int}>
     */
    public function exportRequestPage(string $userId, UsageExportFilter $filter, int $afterId, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'created_at', 'provider', 'model', 'request_type', 'conversation_id',
            'input_tokens', 'output_tokens', 'cache_creation_tokens', 'cache_read_tokens')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)));
        $this->applyExportFilter($qb, $filter);
        $qb->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = [
                'id' => (int)$row['id'],
                'created_at' => (int)$row['created_at'],
                'provider' => self::nullableString($row['provider'] ?? null),
                'model' => (string)$row['model'],
                'request_type' => (string)$row['request_type'],
                'conversation_id' => self::nullableInt($row['conversation_id'] ?? null),
                'input_tokens' => (int)$row['input_tokens'],
                'output_tokens' => (int)$row['output_tokens'],
                'cache_creation_tokens' => self::nullableInt($row['cache_creation_tokens'] ?? null),
                'cache_read_tokens' => self::nullableInt($row['cache_read_tokens'] ?? null),
            ];
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * $userId's rows matching $filter, summed per UTC day or ISO week
     * (Monday start) × provider × model × request type, oldest bucket first.
     *
     * The bucket is computed in SQL as the Unix time of its start, with
     * integer arithmetic every supported database shares, so a year of rows
     * never reaches PHP. The cursor stays open while the caller iterates.
     *
     * Cache sums are null when no row in the bucket reported them.
     *
     * @param 'day'|'week' $bucket
     * @return \Generator<int, array{bucket: int, provider: ?string, model: string, request_type: string, requests: int, input_tokens: int, output_tokens: int, cache_creation_tokens: ?int, cache_read_tokens: ?int}>
     */
    public function exportAggregates(string $userId, UsageExportFilter $filter, string $bucket): \Generator {
        $qb = $this->db->getQueryBuilder();
        $time = $qb->getColumnName('created_at');
        // 1970-01-01 was a Thursday; shifting by three days puts the start of
        // a 604800-second window on Monday 00:00 UTC.
        $bucketExpr = $bucket === 'week'
            ? sprintf('(%1$s - %2$s)', $time, $this->modulo($time . ' + 259200', 604800))
            : sprintf('(%1$s - %2$s)', $time, $this->modulo($time, 86400));

        $qb->selectAlias($qb->createFunction($bucketExpr), 'bucket')
            ->addSelect('provider', 'model', 'request_type')
            ->selectAlias($qb->func()->count(), 'requests')
            ->selectAlias($qb->func()->sum('input_tokens'), 'input_tokens')
            ->selectAlias($qb->func()->sum('output_tokens'), 'output_tokens')
            ->selectAlias($qb->func()->sum('cache_creation_tokens'), 'cache_creation_tokens')
            ->selectAlias($qb->func()->sum('cache_read_tokens'), 'cache_read_tokens')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $this->applyExportFilter($qb, $filter);
        // Group on the expression, not its alias: Oracle rejects aliases in GROUP BY.
        $qb->groupBy($qb->createFunction($bucketExpr))
            ->addGroupBy('provider', 'model', 'request_type')
            ->orderBy('bucket', 'ASC')
            ->addOrderBy('model', 'ASC')
            ->addOrderBy('provider', 'ASC')
            ->addOrderBy('request_type', 'ASC');

        $result = $qb->executeQuery();
        try {
            while (($row = $result->fetch()) !== false) {
                yield [
                    'bucket' => (int)$row['bucket'],
                    'provider' => self::nullableString($row['provider'] ?? null),
                    'model' => (string)$row['model'],
                    'request_type' => (string)$row['request_type'],
                    'requests' => (int)$row['requests'],
                    'input_tokens' => (int)($row['input_tokens'] ?? 0),
                    'output_tokens' => (int)($row['output_tokens'] ?? 0),
                    'cache_creation_tokens' => self::nullableInt($row['cache_creation_tokens'] ?? null),
                    'cache_read_tokens' => self::nullableInt($row['cache_read_tokens'] ?? null),
                ];
            }
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * The period, model, provider and request type conditions of an export.
     * Every value goes in as a bound parameter.
     */
    private function applyExportFilter(IQueryBuilder $qb, UsageExportFilter $filter): void {
        if ($filter->from !== null) {
            $qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($filter->from, IQueryBuilder::PARAM_INT)));
        }
        if ($filter->to !== null) {
            $qb->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($filter->to, IQueryBuilder::PARAM_INT)));
        }
        if ($filter->model !== null) {
            $qb->andWhere($qb->expr()->eq('model', $qb->createNamedParameter($filter->model, IQueryBuilder::PARAM_STR)));
        }
        if ($filter->provider !== null) {
            $qb->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($filter->provider, IQueryBuilder::PARAM_STR)));
        }
        if ($filter->requestType !== null) {
            $qb->andWhere($qb->expr()->eq('request_type', $qb->createNamedParameter($filter->requestType, IQueryBuilder::PARAM_STR)));
        }
    }

    /** `$expr mod $divisor` in the database's syntax: Oracle has only MOD(), SQLite only `%`. */
    private function modulo(string $expr, int $divisor): string {
        return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_ORACLE
            ? sprintf('MOD(%s, %d)', $expr, $divisor)
            : sprintf('((%s) %% %d)', $expr, $divisor);
    }

    private static function nullableInt(mixed $value): ?int {
        return $value === null ? null : (int)$value;
    }

    private static function nullableString(mixed $value): ?string {
        return $value === null || $value === '' ? null : (string)$value;
    }
}
