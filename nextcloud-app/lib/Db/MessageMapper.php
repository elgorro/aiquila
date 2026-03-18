<?php

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
     * @return Message[]
     */
    public function findByConversation(int $conversationId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return Message[]
     */
    public function search(string $userId, string $query, int $limit, int $cursor): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.*')
            ->from($this->getTableName(), 'm')
            ->innerJoin('m', 'aiquila_conversations', 'c', $qb->expr()->eq('m.conversation_id', 'c.id'))
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->iLike('m.content', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
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
