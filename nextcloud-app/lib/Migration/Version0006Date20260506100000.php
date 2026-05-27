<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0006Date20260506100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('aiquila_file_uploads')) {
            return null;
        }

        $table = $schema->createTable('aiquila_file_uploads');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('sha256', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('anthropic_file_id', Types::STRING, [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('uploaded_at', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['user_id', 'sha256'], 'aiq_fileupload_user_sha_idx');
        $table->addIndex(['uploaded_at'], 'aiq_fileupload_age_idx');

        return $schema;
    }
}
