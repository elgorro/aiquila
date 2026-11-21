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
 * `items_skipped` on `aiquila_coworker_runs`: the files a run passed over
 * without asking the model (too large, not UTF-8, empty, not a text type,
 * gone). They count as neither processed nor failed, and without a column of
 * their own a run that skipped half its input read like a clean one.
 */
class Version0032Date20261121120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_coworker_runs')) {
            return null;
        }

        $table = $schema->getTable('aiquila_coworker_runs');
        if ($table->hasColumn('items_skipped')) {
            return null;
        }
        $table->addColumn('items_skipped', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        return $schema;
    }
}
