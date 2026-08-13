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
 * Hold an optional RFC 7591 initial access token per MCP server.
 *
 * A server may gate dynamic client registration behind such a token (aiquila-mcp
 * does this via MCP_REGISTRATION_TOKEN), in which case POST /register answers 401
 * without an `Authorization: Bearer` header and the OAuth flow never reaches the
 * consent screen. The column is nullable, so servers with open registration keep
 * behaving exactly as before.
 *
 * TEXT rather than STRING because the value is stored as ICrypto ciphertext, the
 * same as `auth_token`.
 */
class Version0010Date20260813120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();

        if (!$schema->hasTable('aiquila_mcp_servers')) {
            return null;
        }

        $table = $schema->getTable('aiquila_mcp_servers');
        if ($table->hasColumn('oauth_registration_token')) {
            return null;
        }

        $table->addColumn('oauth_registration_token', Types::TEXT, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
