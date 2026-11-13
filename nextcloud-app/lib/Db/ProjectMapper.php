<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends ChangeTrackingMapper<Project>
 */
class ProjectMapper extends ChangeTrackingMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_projects', Project::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): Project {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * @return list<Project>
     */
    public function findAllByUser(string $userId, bool $activeOnly = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        if ($activeOnly) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('updated_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * A row the user owns, or that one of their teams does.
     *
     * @param list<string> $teamIds
     * @throws DoesNotExistException
     */
    public function findAccessible(int $id, string $userId, array $teamIds): Project {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($this->ownedBy($qb, $userId, $teamIds));

        return $this->findEntity($qb);
    }

    /**
     * @param list<string> $teamIds
     * @return list<Project>
     */
    public function findAllAccessible(string $userId, array $teamIds): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($this->ownedBy($qb, $userId, $teamIds))
            ->orderBy('updated_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return list<Project>
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
