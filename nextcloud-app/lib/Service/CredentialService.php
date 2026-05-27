<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class CredentialService {
    private const APP_NAME = 'aiquila';
    private const CREDENTIAL_KEY = 'aiquila/api_key';
    private const NATIVE_MCP_EXTRA_TOKEN_KEY = 'aiquila/native_mcp_extra_token';

    public function __construct(
        private ICredentialsManager $credentialsManager,
        private ICrypto $crypto,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get API key from secure storage, migrating from plaintext IConfig if needed.
     *
     * @param string|null $userId User ID, or null for app-scope key
     */
    public function getApiKey(?string $userId): string {
        $credUserId = $userId ?? '';

        // Try secure storage first
        $key = $this->credentialsManager->retrieve($credUserId, self::CREDENTIAL_KEY);
        if (is_string($key) && $key !== '') {
            return $key;
        }

        // Fall back to IConfig (plaintext migration)
        if ($userId !== null) {
            $plaintext = $this->config->getUserValue($userId, self::APP_NAME, 'api_key', '');
        } else {
            $plaintext = $this->config->getAppValue(self::APP_NAME, 'api_key', '');
        }

        if ($plaintext !== '') {
            // Migrate: store encrypted, delete plaintext
            $this->credentialsManager->store($credUserId, self::CREDENTIAL_KEY, $plaintext);
            if ($userId !== null) {
                $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
            } else {
                $this->config->deleteAppValue(self::APP_NAME, 'api_key');
            }
            $this->logger->info('Migrated API key from plaintext to secure storage', [
                'scope' => $userId !== null ? 'user' : 'app',
            ]);
            return $plaintext;
        }

        // If user key is empty, fall back to app-scope key
        if ($userId !== null) {
            return $this->getApiKey(null);
        }

        return '';
    }

    /**
     * Store API key in secure storage, removing any plaintext leftover.
     */
    public function setApiKey(?string $userId, string $key): void {
        $credUserId = $userId ?? '';
        $this->credentialsManager->store($credUserId, self::CREDENTIAL_KEY, $key);

        // Clean up plaintext leftovers
        if ($userId !== null) {
            $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
        } else {
            $this->config->deleteAppValue(self::APP_NAME, 'api_key');
        }
    }

    /**
     * Delete API key from both secure and plaintext storage.
     */
    public function deleteApiKey(?string $userId): void {
        $credUserId = $userId ?? '';
        $this->credentialsManager->delete($credUserId, self::CREDENTIAL_KEY);

        if ($userId !== null) {
            $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
        } else {
            $this->config->deleteAppValue(self::APP_NAME, 'api_key');
        }
    }

    /**
     * Check if an API key exists without exposing its value.
     */
    public function hasApiKey(?string $userId): bool {
        $credUserId = $userId ?? '';

        $key = $this->credentialsManager->retrieve($credUserId, self::CREDENTIAL_KEY);
        if (is_string($key) && $key !== '') {
            return true;
        }

        // Check plaintext fallback too
        if ($userId !== null) {
            return $this->config->getUserValue($userId, self::APP_NAME, 'api_key', '') !== '';
        }
        return $this->config->getAppValue(self::APP_NAME, 'api_key', '') !== '';
    }

    /**
     * Store the optional admin-defined bearer token used to authorize
     * Anthropic against the extra MCP URL configured for the native connector.
     */
    public function setNativeMcpExtraToken(string $token): void {
        $this->credentialsManager->store('', self::NATIVE_MCP_EXTRA_TOKEN_KEY, $token);
    }

    public function deleteNativeMcpExtraToken(): void {
        $this->credentialsManager->delete('', self::NATIVE_MCP_EXTRA_TOKEN_KEY);
    }

    public function getNativeMcpExtraToken(): string {
        $tok = $this->credentialsManager->retrieve('', self::NATIVE_MCP_EXTRA_TOKEN_KEY);
        return is_string($tok) ? $tok : '';
    }

    public function hasNativeMcpExtraToken(): bool {
        return $this->getNativeMcpExtraToken() !== '';
    }

    /**
     * Encrypt a token for database storage. Null passthrough.
     */
    public function encryptToken(?string $plaintext): ?string {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        return $this->crypto->encrypt($plaintext);
    }

    /**
     * Decrypt a token from database storage. Null passthrough.
     * If decryption fails (plaintext value), returns raw value with a warning.
     */
    public function decryptToken(?string $ciphertext): ?string {
        if ($ciphertext === null || $ciphertext === '') {
            return $ciphertext;
        }

        try {
            return $this->crypto->decrypt($ciphertext);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to decrypt token — assuming plaintext (pre-migration value)', [
                'exception' => $e->getMessage(),
            ]);
            return $ciphertext;
        }
    }
}
