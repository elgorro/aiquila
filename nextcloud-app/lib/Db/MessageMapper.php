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

    public function deleteByConversation(int $conversationId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
