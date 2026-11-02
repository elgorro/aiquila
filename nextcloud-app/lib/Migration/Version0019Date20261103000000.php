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
 * A per-item trail for each coworker run.
 *
 * `summary` and `error` say how a run ended, not which files it processed,
 * skipped or failed on the way. `log` holds that trail as a JSON list of
 * `{ts, level, message, item}`, capped by CoworkerRun::LOG_LIMIT. It sits on
 * the run row rather than in its own table because nothing queries entries
 * individually: they are read and written with the run.
 *
 * Nullable, so existing runs simply have no log.
 */
class Version0019Date20261103000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_coworker_runs')) {
            return null;
        }

        $table = $schema->getTable('aiquila_coworker_runs');
        if ($table->hasColumn('log')) {
            return null;
        }

        $table->addColumn('log', Types::TEXT, [
            'notnull' => false,
        ]);
        return $schema;
    }
}
