<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops `aiquila_prompts.is_active`.
 *
 * Nothing ever set, returned or filtered on it, so every row holds the
 * default `1`. Deleting a prompt is how it leaves the library.
 */
class Version0030Date20261120000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_prompts')) {
            return null;
        }

        $table = $schema->getTable('aiquila_prompts');
        if (!$table->hasColumn('is_active')) {
            return null;
        }

        $table->dropColumn('is_active');

        return $schema;
    }
}
