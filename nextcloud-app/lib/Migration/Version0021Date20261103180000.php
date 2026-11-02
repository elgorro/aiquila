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
 * Remember which tools a conversation declared and which it offers.
 *
 * Changing `tools` between turns rebuilds the whole prompt cache. With
 * mid-conversation tool changes a conversation keeps sending the tools it
 * started with, appends new ones as deferred, and offers or withdraws them
 * in system messages (see Service\ToolChanges). `tool_state` holds that
 * declared list and the names on offer, as JSON. Null until a conversation
 * first uses tools on a model and platform that support the feature.
 */
class Version0021Date20261103180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_conversations')) {
            return null;
        }

        $table = $schema->getTable('aiquila_conversations');
        if ($table->hasColumn('tool_state')) {
            return null;
        }

        $table->addColumn('tool_state', Types::TEXT, [
            'notnull' => false,
        ]);
        return $schema;
    }
}
