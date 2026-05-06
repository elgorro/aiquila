<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FileUpload>
 */
class FileUploadMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_file_uploads', FileUpload::class);
    }

    /**
     * Look up a cached upload by (user, sha256). Returns null if not present.
     */
    public function findByHash(string $userId, string $sha256): ?FileUpload {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('sha256', $qb->createNamedParameter($sha256, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Delete cache rows older than $olderThan (unix seconds). Used by the
     * cleanup job — Anthropic file IDs expire after the API's retention
     * window, so stale rows would point to deleted files.
     */
    public function deleteOlderThan(int $olderThan): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('uploaded_at', $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }
}
