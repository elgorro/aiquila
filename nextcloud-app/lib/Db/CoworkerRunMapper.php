<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CoworkerRun>
 */
class CoworkerRunMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_coworker_runs', CoworkerRun::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): CoworkerRun {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * Recent runs for a coworker, newest first.
     *
     * @return CoworkerRun[]
     */
    public function findByCoworker(int $coworkerId, string $userId, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Count all coworker runs grouped by status, across all users.
     * Used for the server-global OpenMetrics export.
     *
     * @return array<string, int> status => count
     */
    public function countByStatus(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status')
            ->selectAlias($qb->func()->count('*'), 'run_count')
            ->from($this->getTableName())
            ->groupBy('status');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(string)($row['status'] ?? '')] = (int)($row['run_count'] ?? 0);
        }
        $result->closeCursor();

        return $counts;
    }

    /**
     * Delete all run history for a coworker (used when the coworker is removed).
     */
    public function deleteByCoworker(int $coworkerId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
