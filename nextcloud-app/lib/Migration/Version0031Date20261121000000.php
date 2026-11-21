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
 * Adds `aiquila_usage_stats.provider`.
 *
 * The same model id can be served by several providers (Anthropic, Bedrock,
 * Vertex, …), so the model alone does not say who billed the tokens.
 * UsageStatService fills it from now on; earlier rows keep NULL, which the
 * usage export writes as an empty provider.
 */
class Version0031Date20261121000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_usage_stats')) {
            return null;
        }

        $table = $schema->getTable('aiquila_usage_stats');
        if ($table->hasColumn('provider')) {
            return null;
        }

        $table->addColumn('provider', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);

        return $schema;
    }
}
