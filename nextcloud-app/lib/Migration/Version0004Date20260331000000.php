<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0004Date20260331000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $changed = false;

        // 1. Create aiquila_projects table
        if (!$schema->hasTable('aiquila_projects')) {
            $table = $schema->createTable('aiquila_projects');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length' => 1000,
            ]);
            $table->addColumn('system_prompt', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('is_active', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'aiquila_proj_user_idx');
            $changed = true;
        }

        // 2. Create aiquila_project_paths table
        if (!$schema->hasTable('aiquila_project_paths')) {
            $table = $schema->createTable('aiquila_project_paths');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('project_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);
            $table->addColumn('path_type', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id'], 'aiquila_projpath_proj_idx');
            $changed = true;
        }

        // 3. Add project_id to conversations
        if ($schema->hasTable('aiquila_conversations')) {
            $table = $schema->getTable('aiquila_conversations');
            if (!$table->hasColumn('project_id')) {
                $table->addColumn('project_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        // 4. Add latency_ms to messages
        if ($schema->hasTable('aiquila_messages')) {
            $table = $schema->getTable('aiquila_messages');
            if (!$table->hasColumn('latency_ms')) {
                $table->addColumn('latency_ms', Types::INTEGER, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
