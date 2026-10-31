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
 * Files API uploads record when Anthropic expires them.
 *
 * Uploads used to be made without `expires_in_seconds`, and such a file lives
 * on Anthropic's side until it is deleted. New uploads carry an expiry and
 * store it here. Existing rows keep NULL, which is how the cleanup job knows
 * their remote file has to be deleted explicitly.
 */
class Version0016Date20261031000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_file_uploads')) {
            return null;
        }

        $table = $schema->getTable('aiquila_file_uploads');
        if ($table->hasColumn('expires_at')) {
            return null;
        }

        $table->addColumn('expires_at', Types::BIGINT, [
            'notnull' => false,
        ]);
        $table->addIndex(['expires_at'], 'aiq_fileupload_exp_idx');
        return $schema;
    }
}
