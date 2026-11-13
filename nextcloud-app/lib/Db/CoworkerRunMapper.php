<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends ChangeTrackingMapper<CoworkerRun>
 */
class CoworkerRunMapper extends ChangeTrackingMapper {
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
    public function findByCoworker(int $coworkerId, ?string $userId, int $limit = 20): array {
        $qb = $this->db->getQueryBuilder();
        // Everything but `log`: the list never shows it, and a full log is
        // the largest thing a run row carries.
        $qb->select('id', 'coworker_id', 'user_id', 'status', 'items_total', 'items_processed', 'items_failed', 'summary', 'error', 'started_at', 'finished_at', 'state', 'started_by')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)));
        // A team coworker's runs are the team's, whoever each one ran as.
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        }
        $qb->orderBy('started_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): CoworkerRun {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
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
     * The coworker's run in flight — `running` or `pending` — newest first,
     * or null when there is none.
     */
    public function findActiveForCoworker(int $coworkerId): ?CoworkerRun {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('status', $qb->createNamedParameter(
                [CoworkerRun::STATUS_RUNNING, CoworkerRun::STATUS_PENDING],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * The status as stored right now, read fresh for a worker that holds the
     * row in memory: another request may have stopped the run meanwhile.
     */
    public function findStatus(int $runId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('status')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $status = $result->fetchOne();
        $result->closeCursor();
        return is_string($status) ? $status : null;
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
     * How many runs of a coworker a trigger started since $since, for the
     * trigger rate limit.
     *
     * @param list<string> $startedBy
     */
    public function countStartedBySince(int $coworkerId, array $startedBy, int $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'run_count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('coworker_id', $qb->createNamedParameter($coworkerId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('started_by', $qb->createNamedParameter($startedBy, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
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
