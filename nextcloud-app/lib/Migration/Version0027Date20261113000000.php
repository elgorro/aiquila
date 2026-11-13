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
 * `items_failed` on `aiquila_coworker_runs`: the items of a run that failed.
 * `items_processed` counts only the ones that succeeded, so a run where
 * everything failed is told apart from one that had nothing to do.
 */
class Version0027Date20261113000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_coworker_runs')) {
            return null;
        }

        $table = $schema->getTable('aiquila_coworker_runs');
        if ($table->hasColumn('items_failed')) {
            return null;
        }
        $table->addColumn('items_failed', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        return $schema;
    }
}
