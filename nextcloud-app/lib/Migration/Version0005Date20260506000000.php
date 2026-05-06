<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0005Date20260506000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_messages')) {
            return null;
        }

        $table = $schema->getTable('aiquila_messages');
        if ($table->hasColumn('citations')) {
            return null;
        }

        $table->addColumn('citations', Types::TEXT, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
