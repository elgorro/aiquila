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
 * Keep the tool calls behind an assistant answer.
 *
 * `content` holds an answer's prose; the tool_use/tool_result turns that led
 * to it lived only inside the request that made them, so the next turn saw
 * none of what a tool returned. `tool_trace` stores them (JSON, Anthropic
 * block shape; see Service\ToolTrace) next to `citations` and `documents`,
 * which are kept the same way. A column rather than a table: the trace is
 * read and written with its message, never queried on its own.
 *
 * Nullable: answers without tool calls, and every existing message, have none.
 */
class Version0020Date20261103120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_messages')) {
            return null;
        }

        $table = $schema->getTable('aiquila_messages');
        if ($table->hasColumn('tool_trace')) {
            return null;
        }

        $table->addColumn('tool_trace', Types::TEXT, [
            'notnull' => false,
        ]);
        return $schema;
    }
}
