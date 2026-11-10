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
 * Client registration for MCP servers whose authorization server has no
 * dynamic client registration (MCP 2026-07-28, "Client Registration").
 *
 * On `aiquila_mcp_servers`:
 *  - `oauth_static_client_id` / `oauth_static_client_secret`: a client the
 *    admin registered with the authorization server beforehand. The secret is
 *    optional (public clients have none) and stored encrypted.
 *  - `oauth_client_issuer`: the `issuer` of the authorization server that
 *    `oauth_client_id` belongs to ("Authorization Server Binding"). Null on
 *    existing rows; the next authorization records it.
 */
class Version0024Date20261110120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_mcp_servers')) {
            return null;
        }

        $table = $schema->getTable('aiquila_mcp_servers');
        $changed = false;

        if (!$table->hasColumn('oauth_static_client_id')) {
            $table->addColumn('oauth_static_client_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $changed = true;
        }
        if (!$table->hasColumn('oauth_static_client_secret')) {
            $table->addColumn('oauth_static_client_secret', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if (!$table->hasColumn('oauth_client_issuer')) {
            $table->addColumn('oauth_client_issuer', Types::STRING, ['notnull' => false, 'length' => 255]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
