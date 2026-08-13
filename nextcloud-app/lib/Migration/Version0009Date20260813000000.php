<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Pin a provider per conversation and per coworker.
 *
 * Until now the provider was resolved globally per user on every request, so
 * switching it in personal settings flipped every existing conversation on its
 * next message and replayed history verbatim to a different model. A nullable
 * `provider` column lets a conversation opt out of that: NULL keeps today's
 * behaviour (follow the user's setting), so existing rows are unaffected.
 *
 * The coworker table gets the same column for a second reason: its `model`
 * column has always held a *provider* id (CoworkerService's templates store
 * 'anthropic' / 'mistral' there), which left no way to pin an actual model for
 * a task. The value is copied across so `model` is free to mean what its name
 * says.
 */
class Version0009Date20260813000000 extends SimpleMigrationStep {
    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('aiquila_conversations')) {
            $table = $schema->getTable('aiquila_conversations');
            if (!$table->hasColumn('provider')) {
                $table->addColumn('provider', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
                $changed = true;
            }
        }

        if ($schema->hasTable('aiquila_coworkers')) {
            $table = $schema->getTable('aiquila_coworkers');
            if (!$table->hasColumn('provider')) {
                $table->addColumn('provider', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }

    /**
     * Move the provider id out of the coworker `model` column.
     *
     * Only rows whose `model` names a registered provider are migrated — a row
     * already holding a real model id is left alone, which keeps the step safe
     * to re-run.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();
        if (!$schema->hasTable('aiquila_coworkers')) {
            return;
        }

        $knownProviders = ['anthropic', 'mistral', 'deepseek', 'hetzner', 'local'];

        $update = $this->db->getQueryBuilder();
        $update->update('aiquila_coworkers')
            ->set('provider', 'model')
            ->set('model', $update->createNamedParameter(null))
            ->where($update->expr()->in('model', $update->createNamedParameter($knownProviders, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($update->expr()->isNull('provider'));

        $migrated = $update->executeStatement();
        if ($migrated > 0) {
            $output->info(sprintf('AIquila: moved the provider id of %d coworker(s) into the new provider column', $migrated));
        }
    }
}
