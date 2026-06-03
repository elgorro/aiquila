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

    /** Default provider whose key lives under the legacy CREDENTIAL_KEY. */
    private const DEFAULT_PROVIDER = 'anthropic';

    /**
     * Credential-manager key for a provider. The default provider (anthropic)
     * keeps the legacy 'aiquila/api_key' slot for backward compatibility; other
     * providers are namespaced as 'aiquila/api_key/<provider>'.
     */
    private function credentialKey(string $provider): string {
        return $provider === self::DEFAULT_PROVIDER
            ? self::CREDENTIAL_KEY
            : self::CREDENTIAL_KEY . '/' . $provider;
    }

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
     * @param string $provider Provider id (default 'anthropic' = legacy key slot)
     */
    public function getApiKey(?string $userId, string $provider = self::DEFAULT_PROVIDER): string {
        $credUserId = $userId ?? '';
        $credKey = $this->credentialKey($provider);

        // Try secure storage first
        $key = $this->credentialsManager->retrieve($credUserId, $credKey);
        if (is_string($key) && $key !== '') {
            return $key;
        }

        // Plaintext IConfig migration only ever applied to the legacy default key.
        if ($provider === self::DEFAULT_PROVIDER) {
            if ($userId !== null) {
                $plaintext = $this->config->getUserValue($userId, self::APP_NAME, 'api_key', '');
            } else {
                $plaintext = $this->config->getAppValue(self::APP_NAME, 'api_key', '');
            }

            if ($plaintext !== '') {
                // Migrate: store encrypted, delete plaintext
                $this->credentialsManager->store($credUserId, $credKey, $plaintext);
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
        }

        // If user key is empty, fall back to app-scope key
        if ($userId !== null) {
            return $this->getApiKey(null, $provider);
        }

        return '';
    }

    /**
     * Store API key in secure storage, removing any plaintext leftover.
     */
    public function setApiKey(?string $userId, string $key, string $provider = self::DEFAULT_PROVIDER): void {
        $credUserId = $userId ?? '';
        $this->credentialsManager->store($credUserId, $this->credentialKey($provider), $key);

        // Clean up plaintext leftovers (legacy key only).
        if ($provider === self::DEFAULT_PROVIDER) {
            if ($userId !== null) {
                $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
            } else {
                $this->config->deleteAppValue(self::APP_NAME, 'api_key');
            }
        }
    }

    /**
     * Delete API key from both secure and plaintext storage.
     */
    public function deleteApiKey(?string $userId, string $provider = self::DEFAULT_PROVIDER): void {
        $credUserId = $userId ?? '';
        $this->credentialsManager->delete($credUserId, $this->credentialKey($provider));

        if ($provider === self::DEFAULT_PROVIDER) {
            if ($userId !== null) {
                $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
            } else {
                $this->config->deleteAppValue(self::APP_NAME, 'api_key');
            }
        }
    }

    /**
     * Check if an API key exists in the given scope without exposing its value.
     * Reports the requested scope only (a user-scope check does not consider the
     * app-scope fallback), so callers can distinguish a personal key from an
     * inherited admin key.
     */
    public function hasApiKey(?string $userId, string $provider = self::DEFAULT_PROVIDER): bool {
        $credUserId = $userId ?? '';
        $credKey = $this->credentialKey($provider);

        $key = $this->credentialsManager->retrieve($credUserId, $credKey);
        if (is_string($key) && $key !== '') {
            return true;
        }

        if ($provider !== self::DEFAULT_PROVIDER) {
            return false;
        }

        // Check plaintext fallback too (legacy key only).
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
