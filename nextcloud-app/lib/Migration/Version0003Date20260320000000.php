<?php

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0003Date20260320000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $changed = false;

        if ($schema->hasTable('aiquila_usage_stats')) {
            $table = $schema->getTable('aiquila_usage_stats');
            if (!$table->hasColumn('cache_creation_tokens')) {
                $table->addColumn('cache_creation_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('cache_read_tokens')) {
                $table->addColumn('cache_read_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        if ($schema->hasTable('aiquila_messages')) {
            $table = $schema->getTable('aiquila_messages');
            if (!$table->hasColumn('cache_creation_tokens')) {
                $table->addColumn('cache_creation_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('cache_read_tokens')) {
                $table->addColumn('cache_read_tokens', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
