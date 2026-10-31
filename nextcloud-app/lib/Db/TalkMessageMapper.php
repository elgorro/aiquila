<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TalkMessage>
 */
class TalkMessageMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_talk_messages', TalkMessage::class);
    }

    /**
     * The newest $limit messages of a conversation, oldest first.
     *
     * @return list<TalkMessage>
     */
    public function findRecent(string $roomToken, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)))
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);
        return array_reverse($this->findEntities($qb));
    }

    /** Keep only the newest $keep messages of a conversation. */
    public function prune(string $roomToken, int $keep): void {
        $recent = $this->findRecent($roomToken, $keep);
        if (count($recent) < $keep) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('room_token', $qb->createNamedParameter($roomToken)))
            ->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($recent[0]->getId(), IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** Forget every conversation, when the bot is uninstalled. */
    public function deleteAll(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        $qb->executeStatement();
    }
}
