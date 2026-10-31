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
 * Recent Talk messages per conversation, for the AIquila Talk bot.
 *
 * Talk hands a bot each message as it is sent but offers no way to read the
 * history, so the bot keeps the last few messages of every conversation it is
 * enabled in and uses them as context when it is addressed.
 */
class Version0017Date20261031120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('aiquila_talk_messages')) {
            return null;
        }

        $table = $schema->createTable('aiquila_talk_messages');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('room_token', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('message_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('actor_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        $table->addColumn('from_bot', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
        $table->addColumn('content', Types::TEXT, ['notnull' => true]);
        $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['room_token', 'id'], 'aiq_talkmsg_room_idx');
        return $schema;
    }
}
