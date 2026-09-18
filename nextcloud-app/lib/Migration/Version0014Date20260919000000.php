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
 * Let a coworker run outlive the tick that started it.
 *
 * A run that hands work to the Anthropic Batch API cannot finish in the job
 * that submitted it — a batch may take up to 24 hours, and the cron worker
 * runs background jobs one after another in a single process. So the run
 * parks what it needs to pick the work back up (the batch id, the map from
 * custom_id to file, when it was submitted) in `state`, reports status
 * `pending`, and returns. A later tick reads it back and finishes the run.
 *
 * The index on `status` exists because that poll now runs every five minutes
 * and looks for exactly one value; without it the lookup is a full scan of
 * the run history, which only grows.
 *
 * Existing rows are unaffected: `state` is nullable and every historical run
 * is already terminal, so nothing reads it.
 */
class Version0014Date20260919000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_coworker_runs')) {
            return null;
        }

        $table = $schema->getTable('aiquila_coworker_runs');
        $changed = false;

        if (!$table->hasColumn('state')) {
            $table->addColumn('state', Types::TEXT, [
                'notnull' => false,
            ]);
            $changed = true;
        }

        // Nextcloud caps index names at 30 characters.
        if (!$table->hasIndex('aiquila_cwrun_status_idx')) {
            $table->addIndex(['status'], 'aiquila_cwrun_status_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
