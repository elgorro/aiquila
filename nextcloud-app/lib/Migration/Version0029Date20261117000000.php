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
 * Manual batch runs on `aiquila_coworker_runs`.
 *
 * A run started over a selection in Files has no coworker: `coworker_id` is 0
 * and `started_by` is `selection`.
 *
 * - `task_type`: the operation it runs (`summarize`, `translate`). Null on
 *   coworker runs, whose coworker row says what they run.
 * - `outputs`: JSON list of the files it wrote, as paths in the user's Files.
 */
class Version0029Date20261117000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_coworker_runs')) {
            return null;
        }

        $table = $schema->getTable('aiquila_coworker_runs');
        $changed = false;
        if (!$table->hasColumn('task_type')) {
            $table->addColumn('task_type', Types::STRING, ['notnull' => false, 'length' => 32]);
            $changed = true;
        }
        if (!$table->hasColumn('outputs')) {
            $table->addColumn('outputs', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if (!$table->hasIndex('aiquila_cwrun_user_start_idx')) {
            // Manual runs are listed and rate limited per user, newest first.
            $table->addIndex(['user_id', 'started_by', 'started_at'], 'aiquila_cwrun_user_start_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
