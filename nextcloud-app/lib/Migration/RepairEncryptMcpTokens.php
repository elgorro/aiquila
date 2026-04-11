<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use OCA\AIquila\Db\McpServerMapper;
use OCA\AIquila\Service\CredentialService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class RepairEncryptMcpTokens implements IRepairStep {
    public function __construct(
        private McpServerMapper $mapper,
        private CredentialService $credentials,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Encrypt MCP server tokens at rest';
    }

    public function run(IOutput $output): void {
        $servers = $this->mapper->findAll();
        $migrated = 0;

        foreach ($servers as $server) {
            $changed = false;

            foreach (['getAuthToken', 'getOauthAccessToken', 'getOauthRefreshToken'] as $getter) {
                $value = $server->$getter();
                if ($value === null || $value === '') {
                    continue;
                }

                // Try to decrypt — if it fails, it's plaintext and needs encryption
                $setter = str_replace('get', 'set', $getter);
                try {
                    $this->credentials->decryptToken($value);
                    // If decryptToken returns without exception via ICrypto::decrypt,
                    // the value is already encrypted. But decryptToken also catches
                    // exceptions and returns raw value. We need to check differently.
                    // Try ICrypto::decrypt directly would be cleaner, but we use the
                    // service's encrypt method to re-encrypt plaintext values.
                } catch (\Exception) {
                    // Already handled in decryptToken
                }

                // The simplest approach: encrypt the value. If it was already encrypted,
                // decryptToken will decrypt it fine later (double-encrypted won't work).
                // So we must check: can we decrypt it? If yes, it's already encrypted.
                $decrypted = $this->credentials->decryptToken($value);
                if ($decrypted === $value) {
                    // decryptToken returned raw value (either plaintext or decrypt failed)
                    // This means it's plaintext — encrypt it
                    $encrypted = $this->credentials->encryptToken($value);
                    $server->$setter($encrypted);
                    $changed = true;
                }
                // If $decrypted !== $value, it was successfully decrypted, so already encrypted
            }

            if ($changed) {
                $server->setUpdatedAt(time());
                $this->mapper->update($server);
                $migrated++;
            }
        }

        if ($migrated > 0) {
            $output->info("Encrypted tokens for $migrated MCP server(s)");
            $this->logger->info('RepairEncryptMcpTokens: migrated {count} servers', ['count' => $migrated]);
        }
    }
}
