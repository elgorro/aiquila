<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<McpServer>
 */
class McpServerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_mcp_servers', McpServer::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findById(int $id): McpServer {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return McpServer[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('display_name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return McpServer[]
     */
    public function findAllEnabled(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('is_enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('display_name', 'ASC');

        return $this->findEntities($qb);
    }
}
