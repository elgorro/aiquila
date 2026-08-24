<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0012Date20260825000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_conversations')) {
            return null;
        }

        $table = $schema->getTable('aiquila_conversations');

        // Null means "no explicit budget" — thinking stays adaptive.
        if ($table->hasColumn('thinking_budget')) {
            return null;
        }

        $table->addColumn('thinking_budget', Types::INTEGER, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
