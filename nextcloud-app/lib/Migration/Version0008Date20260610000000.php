<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0008Date20260610000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_conversations')) {
            return null;
        }

        $table = $schema->getTable('aiquila_conversations');
        $changed = false;

        if (!$table->hasColumn('effort')) {
            $table->addColumn('effort', Types::STRING, [
                'notnull' => false,
                'length' => 16,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('thinking')) {
            $table->addColumn('thinking', Types::BOOLEAN, [
                'notnull' => false,
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
