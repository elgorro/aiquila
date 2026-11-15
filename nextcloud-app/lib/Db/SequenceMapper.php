<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends ChangeTrackingMapper<Sequence>
 */
class SequenceMapper extends ChangeTrackingMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_sequences', Sequence::class);
    }

    /**
     * A user's own sequence. Instance sequences are never found here, so a
     * user route can't edit one.
     *
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): Sequence {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Sequence::SCOPE_USER, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findInstanceById(int $id): Sequence {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Sequence::SCOPE_INSTANCE, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * A user's own sequences, by slug.
     *
     * @return list<Sequence>
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Sequence::SCOPE_USER, IQueryBuilder::PARAM_STR)))
            ->orderBy('slug', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Every sequence an admin published for the instance, by slug.
     *
     * @return list<Sequence>
     */
    public function findAllInstance(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('scope', $qb->createNamedParameter(Sequence::SCOPE_INSTANCE, IQueryBuilder::PARAM_STR)))
            ->orderBy('slug', 'ASC');

        return $this->findEntities($qb);
    }
}
