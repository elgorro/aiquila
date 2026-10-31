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
     * Rows whose remote file Anthropic has already expired: nothing to delete
     * remotely, only the row.
     */
    public function deleteExpired(int $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->isNotNull('expires_at'))
            ->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }

    /**
     * Rows whose remote file the cleanup job has to delete itself, oldest
     * first: uploads made without an expiry once they are older than
     * $legacyCutoff, or every row when $all is set (the Files API was
     * switched off).
     *
     * @return list<FileUpload>
     */
    public function findToDelete(int $legacyCutoff, bool $all, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('uploaded_at', 'ASC')
            ->setMaxResults($limit);
        if (!$all) {
            $qb->where($qb->expr()->isNull('expires_at'))
                ->andWhere($qb->expr()->lt('uploaded_at', $qb->createNamedParameter($legacyCutoff, IQueryBuilder::PARAM_INT)));
        }
        return $this->findEntities($qb);
    }

    /**
     * Delete the cache row for a specific Anthropic file_id. Used by the
     * lazy-fallback path when the API rejects a cached file_id (the file
     * is gone Anthropic-side before our daily cleanup ran).
     */
    public function deleteByFileId(string $fileId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('anthropic_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_STR)));
        return $qb->executeStatement();
    }
}
