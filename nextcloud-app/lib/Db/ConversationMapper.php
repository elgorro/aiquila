<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends ChangeTrackingMapper<Conversation>
 */
class ConversationMapper extends ChangeTrackingMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_conversations', Conversation::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByIdAndUser(int $id, string $userId): Conversation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * Write a conversation's tool state if nobody has since it was read:
     * compare-and-swap on `tool_state_version`. On success the entity holds
     * the new state and version; on failure it is unchanged, and the caller
     * re-reads ({@see reloadToolState()}) and plans again.
     */
    public function swapToolState(Conversation $conversation, ?string $state): bool {
        $version = $conversation->getToolStateVersion();
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('tool_state', $qb->createNamedParameter($state, $state === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))
            ->set('tool_state_version', $qb->createNamedParameter($version + 1, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($conversation->getId(), IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('tool_state_version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)));
        if ($qb->executeStatement() !== 1) {
            return false;
        }
        $conversation->adoptToolState($state, $version + 1);
        return true;
    }

    /** Read a conversation's tool state and its version again, e.g. after a lost swap. */
    public function reloadToolState(Conversation $conversation): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('tool_state', 'tool_state_version')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($conversation->getId(), IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if (is_array($row)) {
            $state = $row['tool_state'] ?? null;
            $conversation->adoptToolState(is_string($state) ? $state : null, (int)($row['tool_state_version'] ?? 0));
        }
    }

    /**
     * Look up a conversation by id alone (no user scoping).
     * Used by background indexing jobs that only carry the conversation id.
     *
     * @throws DoesNotExistException
     */
    public function findById(int $id): Conversation {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * All conversations across all users, used for full Context Chat re-imports.
     *
     * @return list<Conversation>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        return $this->findEntities($qb);
    }

    /**
     * @return list<Conversation>
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->orderBy('updated_at', 'DESC');

        return $this->findEntities($qb);
    }

    public function deleteAllByUser(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }

    /**
     * Count all conversations across all users.
     * Used for the server-global OpenMetrics export.
     */
    public function countAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'conversation_count'))
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int)($row['conversation_count'] ?? 0);
    }

    /**
     * Detach all conversations from a project that is going away.
     */
    public function clearProject(int $projectId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('project_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Give a conversation its first title, unless it got one meanwhile: the
     * user may rename it while a reply is still streaming, and that title
     * wins. Returns whether this title was stored.
     */
    public function setTitleIfUntitled(int $id, string $title): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('title', $qb->createNamedParameter($title, IQueryBuilder::PARAM_STR))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('title'),
                $qb->expr()->eq('title', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
            ));
        return $qb->executeStatement() > 0;
    }
}
