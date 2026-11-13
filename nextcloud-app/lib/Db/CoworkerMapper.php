<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
     * @return list<Coworker>
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
     * A user's personal coworkers, app-owned ones included: everything that
     * runs as them and belongs to no team.
     *
     * @return list<Coworker>
     */
    public function findPersonalByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNull('team_id'));

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
     * @return list<Coworker>
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
     * @return list<Coworker>
     */
    public function findDueForRun(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('paused', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('next_run_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Active, unpaused coworkers that run when files in their input folder
     * change.
     *
     * @return list<Coworker>
     */
    public function findFileTriggered(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('trigger_files', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('paused', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * A row the user owns, or that one of their teams does.
     *
     * @param list<string> $teamIds
     * @throws DoesNotExistException
     */
    public function findAccessible(int $id, string $userId, array $teamIds): Coworker {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->ownedBy($qb, $userId, $teamIds));

        return $this->findEntity($qb);
    }

    /**
     * Like findAllByUser(), plus what the user's teams own. App-owned
     * coworkers stay out of this list too.
     *
     * @param list<string> $teamIds
     * @return list<Coworker>
     */
    public function findAllAccessible(string $userId, array $teamIds): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($this->ownedBy($qb, $userId, $teamIds))
            ->andWhere($qb->expr()->isNull('owner_app'))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): Coworker {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return list<Coworker>
     */
    public function findAllByTeam(string $teamId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_STR)));

        return $this->findEntities($qb);
    }

    /**
     * @param list<string> $teamIds
     */
    private function ownedBy(IQueryBuilder $qb, string $userId, array $teamIds): ICompositeExpression|string {
        $isUser = $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR));
        // IN () is not valid SQL.
        return $teamIds === []
            ? $isUser
            : $qb->expr()->orX($isUser, $qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)));
    }
}
