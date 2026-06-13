<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Coworker>
 */
class CoworkerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_coworkers', Coworker::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): Coworker {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * User-owned coworkers only (those created via the UI / MCP). App-owned
     * coworkers carry a non-null owner_app and are excluded so they don't show
     * up in the user-facing list.
     *
     * @return Coworker[]
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNull('owner_app'))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Resolve a coworker by id, scoped to the owning app. Used by the public
     * ICoworkManager so an app can only read/steer its own coworkers.
     *
     * @throws DoesNotExistException
     */
    public function findByIdAndApp(int $id, string $appId): Coworker {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('owner_app', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * List coworkers owned by an app, optionally filtered to a single user.
     *
     * @return Coworker[]
     */
    public function findAllByApp(string $appId, ?string $userId = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_app', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)));
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        }
        $qb->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Returns all active, non-paused coworkers due for execution. A NULL
     * next_run_at never satisfies the `<= now` comparison, so paused/disabled
     * coworkers (whose next_run_at is cleared) are excluded automatically.
     *
     * @return Coworker[]
     */
    public function findDueForRun(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->eq('paused', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lte('next_run_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }
}
