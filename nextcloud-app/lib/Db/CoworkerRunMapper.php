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
     * @return list<CoworkerRun>
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
     * Runs waiting on work that finishes outside this process — currently a
     * batch submitted to the Anthropic API. Oldest first, so a backlog drains
     * in submission order rather than starving the earliest run.
     *
     * @return list<CoworkerRun>
     */
    public function findPending(int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(CoworkerRun::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
            ->orderBy('started_at', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * The open pending run for a coworker, if it has one.
     *
     * A coworker whose previous batch has not come back must not submit
     * another — that would bill the same folder twice and race two runs onto
     * the same output files.
     */
    public function findPendingForCoworker(int $coworkerId): ?CoworkerRun {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(CoworkerRun::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
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
