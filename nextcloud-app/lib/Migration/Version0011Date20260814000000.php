<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-provider access rules (allowed/blocked users and groups).
 *
 * One row per (provider, allow|block, user|group, principal). An empty table
 * means every provider is open to everyone, so an existing instance keeps
 * behaving exactly as before the upgrade.
 */
class Version0011Date20260814000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if ($schema->hasTable('aiquila_provider_access')) {
            return null;
        }

        $table = $schema->createTable('aiquila_provider_access');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('provider_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('rule_type', Types::STRING, [
            'notnull' => true,
            'length' => 8,
        ]);
        $table->addColumn('principal_type', Types::STRING, [
            'notnull' => true,
            'length' => 8,
        ]);
        $table->addColumn('principal_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['provider_id', 'rule_type', 'principal_type', 'principal_id'],
            'aiq_provacc_rule_idx',
        );
        $table->addIndex(['principal_type', 'principal_id'], 'aiq_provacc_principal_idx');

        return $schema;
    }
}
