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
 * `aiquila_sequences`: named tool chains the MCP server runs in one call.
 *
 * - `scope` is `user` (the owner's own) or `instance` (published by an admin
 *   to everyone, read-only for users).
 * - `user_id` is the owner: the user for `user` rows, '' for `instance` rows,
 *   so a slug is unique per scope and owner. `author_id` records who wrote it.
 * - `definition` is JSON: `{inputs, steps}`, checked against
 *   `lib/Resources/sequence-catalog.json` on every write.
 * - `pinned`: also listed as its own MCP tool. `enabled`: listed and run at all.
 */
class Version0028Date20261115000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('aiquila_sequences')) {
            return null;
        }

        $table = $schema->createTable('aiquila_sequences');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'user']);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $table->addColumn('author_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('definition', Types::TEXT, ['notnull' => true]);
        $table->addColumn('pinned', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['scope', 'user_id', 'slug'], 'aiquila_seq_slug_uniq');
        $table->addIndex(['user_id'], 'aiquila_seq_user_idx');

        return $schema;
    }
}
