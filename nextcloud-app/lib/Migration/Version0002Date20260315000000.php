<?php

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0002Date20260315000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createMcpServersTable($schema);

        return $schema;
    }

    private function createMcpServersTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('aiquila_mcp_servers')) {
            return;
        }

        $table = $schema->createTable('aiquila_mcp_servers');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('display_name', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('url', Types::STRING, [
            'notnull' => true,
            'length' => 2048,
        ]);
        $table->addColumn('auth_type', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'none',
        ]);
        $table->addColumn('auth_token', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('is_enabled', Types::SMALLINT, [
            'notnull' => true,
            'default' => 1,
        ]);
        $table->addColumn('last_status', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);
        $table->addColumn('last_error', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('tool_count', Types::INTEGER, [
            'notnull' => false,
        ]);
        $table->addColumn('last_connected_at', Types::BIGINT, [
            'notnull' => false,
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
    }
}
