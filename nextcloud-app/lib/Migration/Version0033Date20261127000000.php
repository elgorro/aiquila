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
 * `tool_state_version` on `aiquila_conversations`: bumped by every write of
 * `tool_state`, which only succeeds against the version it was planned from.
 * Two turns of one conversation (two tabs, a retry while a stream runs) then
 * cannot both plan from the same state and have the later write drop the
 * earlier one's tools.
 */
class Version0033Date20261127000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_conversations')) {
            return null;
        }

        $table = $schema->getTable('aiquila_conversations');
        if ($table->hasColumn('tool_state_version')) {
            return null;
        }
        $table->addColumn('tool_state_version', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        return $schema;
    }
}
