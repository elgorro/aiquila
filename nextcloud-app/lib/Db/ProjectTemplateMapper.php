<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProjectTemplate>
 */
class ProjectTemplateMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_project_templates', ProjectTemplate::class);
    }

    /**
     * A user's own template; never an instance one.
     *
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): ProjectTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Prompt::SCOPE_USER, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findInstanceById(int $id): ProjectTemplate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Prompt::SCOPE_INSTANCE, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * @return list<ProjectTemplate>
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Prompt::SCOPE_USER, IQueryBuilder::PARAM_STR)))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return list<ProjectTemplate>
     */
    public function findAllInstance(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('scope', $qb->createNamedParameter(Prompt::SCOPE_INSTANCE, IQueryBuilder::PARAM_STR)))
            ->orderBy('title', 'ASC');

        return $this->findEntities($qb);
    }
}
