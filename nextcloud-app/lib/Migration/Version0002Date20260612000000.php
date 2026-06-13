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
 * Cowork engine: adds task-type/pause/options columns to coworkers and a run
 * history table for progress tracking.
 */
class Version0002Date20260612000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->extendCoworkersTable($schema);
        $this->createCoworkerRunsTable($schema);

        return $schema;
    }

    private function extendCoworkersTable(ISchemaWrapper $schema): void {
        if (!$schema->hasTable('aiquila_coworkers')) {
            return;
        }

        $table = $schema->getTable('aiquila_coworkers');

        if (!$table->hasColumn('task_type')) {
            $table->addColumn('task_type', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => 'vision:classify',
            ]);
        }
        if (!$table->hasColumn('paused')) {
            $table->addColumn('paused', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
        }
        if (!$table->hasColumn('options')) {
            $table->addColumn('options', Types::TEXT, [
                'notnull' => false,
            ]);
        }
    }

    private function createCoworkerRunsTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('aiquila_coworker_runs')) {
            return;
        }

        $table = $schema->createTable('aiquila_coworker_runs');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('coworker_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'running',
        ]);
        $table->addColumn('items_total', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('items_processed', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('summary', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('error', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('started_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);
        $table->addColumn('finished_at', Types::BIGINT, [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['coworker_id'], 'aiquila_cwrun_coworker_idx');
        $table->addIndex(['user_id'], 'aiquila_cwrun_user_idx');
    }
}
