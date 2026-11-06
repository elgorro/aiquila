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
 * Let a team own a coworker or a project.
 *
 * `team_id` is the team's circle ID, null for everything owned by one user, so
 * existing rows stay as they are. `user_id` keeps its meaning on both tables:
 * for a team-owned coworker it is the account the coworker runs as.
 */
class Version0022Date20261106000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        foreach (['aiquila_coworkers' => 'aiq_cw_team_idx', 'aiquila_projects' => 'aiq_proj_team_idx'] as $name => $index) {
            if (!$schema->hasTable($name)) {
                continue;
            }
            $table = $schema->getTable($name);
            if ($table->hasColumn('team_id')) {
                continue;
            }
            $table->addColumn('team_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addIndex(['team_id'], $index);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
