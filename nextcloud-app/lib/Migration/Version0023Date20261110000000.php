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
 * Let something other than the schedule start a coworker.
 *
 * On `aiquila_coworkers`:
 *  - `scheduled`: whether the cron schedule runs it. 1 for every existing row,
 *    so nothing changes for them.
 *  - `trigger_files`: run when files in the input folder are added or changed.
 *  - `trigger_token_hash`: SHA-256 of the secret its trigger URL takes, or null
 *    when it has none. The token itself is never stored.
 *  - `trigger_due_at` / `trigger_source`: a run a trigger asked for and has not
 *    started yet, and what asked for it.
 *
 * On `aiquila_coworker_runs`, `started_by` records what started each run
 * (`trigger` would be a reserved word in MySQL and PostgreSQL).
 */
class Version0023Date20261110000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('aiquila_coworkers')) {
            $table = $schema->getTable('aiquila_coworkers');
            if (!$table->hasColumn('scheduled')) {
                $table->addColumn('scheduled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
                $changed = true;
            }
            if (!$table->hasColumn('trigger_files')) {
                $table->addColumn('trigger_files', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
                $table->addIndex(['trigger_files'], 'aiq_cw_trigger_files_idx');
                $changed = true;
            }
            if (!$table->hasColumn('trigger_token_hash')) {
                $table->addColumn('trigger_token_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }
            if (!$table->hasColumn('trigger_due_at')) {
                $table->addColumn('trigger_due_at', Types::BIGINT, ['notnull' => false]);
                $changed = true;
            }
            if (!$table->hasColumn('trigger_source')) {
                $table->addColumn('trigger_source', Types::STRING, ['notnull' => false, 'length' => 16]);
                $changed = true;
            }
        }

        if ($schema->hasTable('aiquila_coworker_runs')) {
            $table = $schema->getTable('aiquila_coworker_runs');
            if (!$table->hasColumn('started_by')) {
                $table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 16]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
