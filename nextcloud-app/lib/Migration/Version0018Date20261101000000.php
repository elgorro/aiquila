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
 * Prompt library and project templates.
 *
 * - `aiquila_prompts.scope`: `user` rows belong to their owner, `instance` rows
 *   are published by an admin and readable by everyone. `user_id` stays the
 *   author in both cases.
 * - `aiquila_project_templates`: the fields a new project is copied from, in
 *   the same two scopes. `paths` is a JSON list of `{path, pathType}`.
 */
class Version0018Date20261101000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('aiquila_prompts')) {
            $prompts = $schema->getTable('aiquila_prompts');
            if (!$prompts->hasColumn('scope')) {
                $prompts->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'user']);
                $changed = true;
            }
            if (!$prompts->hasIndex('aiquila_prompt_scope_idx')) {
                $prompts->addIndex(['scope'], 'aiquila_prompt_scope_idx');
                $changed = true;
            }
        }

        if (!$schema->hasTable('aiquila_project_templates')) {
            $table = $schema->createTable('aiquila_project_templates');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'user']);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 1000]);
            $table->addColumn('system_prompt', Types::TEXT, ['notnull' => false]);
            $table->addColumn('paths', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'aiquila_ptpl_user_idx');
            $table->addIndex(['scope'], 'aiquila_ptpl_scope_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
