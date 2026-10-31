<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Message>
 */
class MessageMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_messages', Message::class);
    }

    /**
     * Messages of a conversation, oldest first.
     *
     * `system` rows are context notes for the model (see RuntimeContextService),
     * not part of the visible conversation, so they are left out unless the
     * caller rebuilds the provider history or copies the conversation.
     *
     * @return list<Message>
     */
    public function findByConversation(int $conversationId, bool $includeSystem = false): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            // Rows written in the same second keep their insertion order.
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('id', 'ASC');
        if (!$includeSystem) {
            $qb->andWhere($qb->expr()->neq('role', $qb->createNamedParameter('system')));
        }

        return $this->findEntities($qb);
    }

    /** The newest `system` row of a conversation, or null. */
    public function findLatestSystem(int $conversationId): ?Message {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('role', $qb->createNamedParameter('system')))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * @return list<Message>
     */
    public function search(string $userId, string $query, int $limit, int $cursor): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.*')
            ->from($this->getTableName(), 'm')
            ->innerJoin('m', 'aiquila_conversations', 'c', $qb->expr()->eq('m.conversation_id', 'c.id'))
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->iLike('m.content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
            ->andWhere($qb->expr()->neq('m.role', $qb->createNamedParameter('system')))
            ->orderBy('m.created_at', 'DESC')
            ->setMaxResults($limit);

        if ($cursor > 0) {
            $qb->andWhere($qb->expr()->lt('m.id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }

    public function deleteByConversation(int $conversationId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
