<?php

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UsageStat>
 */
class UsageStatMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_usage_stats', UsageStat::class);
    }

    /**
     * @return UsageStat[]
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

    /**
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function sumTokensByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            $qb->func()->sum('input_tokens', 'total_input'),
            $qb->func()->sum('output_tokens', 'total_output')
        )
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return [
            'input_tokens' => (int)($row['total_input'] ?? 0),
            'output_tokens' => (int)($row['total_output'] ?? 0),
        ];
    }
}
