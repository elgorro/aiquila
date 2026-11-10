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
 * Authorization server discovery through Protected Resource Metadata
 * (RFC 9728; MCP 2026-07-28, "Authorization Server Discovery").
 *
 * On `aiquila_mcp_servers`:
 *  - `oauth_resource_metadata`: the MCP server's Protected Resource Metadata
 *    document, cached next to `oauth_metadata` (the authorization server's).
 *  - `oauth_scope`: the scope the authorization request asks for, from the
 *    401 challenge or the metadata's `scopes_supported`. Null omits `scope`.
 */
class Version0025Date20261111000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_mcp_servers')) {
            return null;
        }

        $table = $schema->getTable('aiquila_mcp_servers');
        $changed = false;

        if (!$table->hasColumn('oauth_resource_metadata')) {
            $table->addColumn('oauth_resource_metadata', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if (!$table->hasColumn('oauth_scope')) {
            $table->addColumn('oauth_scope', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
