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
 * Authorization response issuer validation (RFC 9207; MCP 2026-07-28,
 * "Authorization Response Validation").
 *
 * On `aiquila_mcp_servers`, recorded next to `oauth_state` and
 * `oauth_code_verifier` when a sign-in starts:
 *  - `oauth_flow_issuer`: the `issuer` of the authorization server the
 *    request was sent to, which the callback's `iss` must equal.
 *  - `oauth_flow_iss_required`: whether that server advertised
 *    `authorization_response_iss_parameter_supported`, so a response
 *    without `iss` is rejected.
 */
class Version0026Date20261111120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_mcp_servers')) {
            return null;
        }

        $table = $schema->getTable('aiquila_mcp_servers');
        $changed = false;

        if (!$table->hasColumn('oauth_flow_issuer')) {
            $table->addColumn('oauth_flow_issuer', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if (!$table->hasColumn('oauth_flow_iss_required')) {
            $table->addColumn('oauth_flow_iss_required', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
