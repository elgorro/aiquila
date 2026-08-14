<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProviderAccessRule>
 */
class ProviderAccessRuleMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'aiquila_provider_access', ProviderAccessRule::class);
    }

    /**
     * Every rule for every provider.
     *
     * The whole table is read at once because the access check needs all four
     * lists of one provider anyway and the row count is bounded by how many
     * principals an admin names — ProviderAccessService caches the result.
     *
     * @return list<ProviderAccessRule>
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());

        return $this->findEntities($qb);
    }

    /**
     * @return list<ProviderAccessRule>
     */
    public function findByProvider(string $providerId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId)));

        return $this->findEntities($qb);
    }

    /**
     * Make one list of one provider exactly $principalIds.
     *
     * Delete-then-insert rather than a diff: the lists are short, and replacing
     * wholesale is what the settings form submits.
     *
     * @param list<string> $principalIds
     */
    public function replaceList(string $providerId, string $ruleType, string $principalType, array $principalIds): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('provider_id', $qb->createNamedParameter($providerId)))
            ->andWhere($qb->expr()->eq('rule_type', $qb->createNamedParameter($ruleType)))
            ->andWhere($qb->expr()->eq('principal_type', $qb->createNamedParameter($principalType)));
        $qb->executeStatement();

        foreach (array_unique($principalIds) as $principalId) {
            if ($principalId === '') {
                continue;
            }
            $rule = new ProviderAccessRule();
            $rule->setProviderId($providerId);
            $rule->setRuleType($ruleType);
            $rule->setPrincipalType($principalType);
            $rule->setPrincipalId($principalId);
            $this->insert($rule);
        }
    }

    /**
     * Drop every rule naming a principal — used when a user or group is deleted,
     * so a later principal reusing the id does not inherit its permissions.
     */
    public function deleteByPrincipal(string $principalType, string $principalId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('principal_type', $qb->createNamedParameter($principalType)))
            ->andWhere($qb->expr()->eq('principal_id', $qb->createNamedParameter($principalId)));
        $qb->executeStatement();
    }
}
