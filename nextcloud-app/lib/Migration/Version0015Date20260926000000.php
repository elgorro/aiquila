<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Thinking becomes a three-way setting and assistant messages keep their
 * thinking summary.
 *
 * The `thinking` app value used to be a checkbox stored as 'true'/'false'.
 * "false" never sent anything, which on the 5-series means the model thinks
 * on its own — so it is really "follow the model" and becomes blank, while
 * 'true' becomes 'on'. Leaving 'false' in place would still resolve the same
 * way at request time, but the settings select would show no value.
 *
 * `thinking_summary` holds the readable summary of the model's thinking for
 * an assistant message, so the chat can show it again after a reload.
 * Nullable: older messages and models that did not think have none.
 */
class Version0015Date20260926000000 extends SimpleMigrationStep {
    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_messages')) {
            return null;
        }

        $table = $schema->getTable('aiquila_messages');
        if ($table->hasColumn('thinking_summary')) {
            return null;
        }

        $table->addColumn('thinking_summary', Types::TEXT, [
            'notnull' => false,
        ]);
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $current = $this->config->getAppValue('aiquila', 'thinking', '');
        $migrated = match ($current) {
            'true', '1' => 'on',
            'false', '0' => '',
            default => null,
        };
        if ($migrated !== null) {
            $this->config->setAppValue('aiquila', 'thinking', $migrated);
            $output->info(sprintf('AIquila: thinking default "%s" is now "%s"', $current, $migrated === '' ? 'follow the model' : $migrated));
        }
    }
}
