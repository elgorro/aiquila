<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCP\Security\ICrypto;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class CredentialService {
    private const APP_NAME = 'aiquila';
    private const CREDENTIAL_KEY = 'aiquila/api_key';
    private const NATIVE_MCP_EXTRA_TOKEN_KEY = 'aiquila/native_mcp_extra_token';
    /** Namespace for the generic named secrets; see secretKey(). */
    private const SECRET_KEY_PREFIX = 'aiquila/secret/';

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
        private AppSettings $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get API key from secure storage, migrating a plaintext app or user setting if needed.
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

        // Plaintext migration only ever applied to the legacy default key.
        if ($provider === self::DEFAULT_PROVIDER) {
            $migrated = $this->migratePlaintextApiKey($userId, false);
            if ($migrated !== '') {
                return $migrated;
            }
        }

        // If user key is empty, fall back to app-scope key
        if ($userId !== null) {
            return $this->getApiKey(null, $provider);
        }

        return '';
    }

    /**
     * Move a plaintext `api_key` setting left by early releases into the
     * credential manager and delete it. A key already in the credential manager
     * wins; the plaintext copy is deleted either way.
     *
     * @param bool|null $hasStoredKey Whether the credential manager already holds
     *                                a key for this scope; null looks it up
     * @return string The key that was moved, or '' when there was nothing to move
     */
    public function migratePlaintextApiKey(?string $userId, ?bool $hasStoredKey = null): string {
        $plaintext = $userId !== null
            ? $this->config->getUserValue($userId, self::APP_NAME, 'api_key', '')
            : $this->config->getAppValue(self::APP_NAME, 'api_key', '');
        if ($plaintext === '') {
            return '';
        }

        $credUserId = $userId ?? '';
        if ($hasStoredKey === null) {
            $stored = $this->credentialsManager->retrieve($credUserId, self::CREDENTIAL_KEY);
            $hasStoredKey = is_string($stored) && $stored !== '';
        }
        if (!$hasStoredKey) {
            $this->credentialsManager->store($credUserId, self::CREDENTIAL_KEY, $plaintext);
        }
        if ($userId !== null) {
            $this->config->deleteUserValue($userId, self::APP_NAME, 'api_key');
        } else {
            $this->config->deleteAppValue(self::APP_NAME, 'api_key');
        }
        $this->logger->info('Migrated API key from plaintext to secure storage', [
            'scope' => $userId !== null ? 'user' : 'app',
        ]);
        return $hasStoredKey ? '' : $plaintext;
    }

    /**
     * Store API key in secure storage, removing any plaintext leftover.
     */
    public function setApiKey(?string $userId, #[\SensitiveParameter] string $key, string $provider = self::DEFAULT_PROVIDER): void {
        $credUserId = $userId ?? '';
        $this->credentialsManager->store($credUserId, $this->credentialKey($provider), $key);
        $this->logger->info('AIquila: API key stored for ' . $provider, [
            'provider' => $provider,
            'scope' => $userId === null ? 'admin' : 'user',
        ]);

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
        $this->logger->info('AIquila: API key cleared for ' . $provider, [
            'provider' => $provider,
            'scope' => $userId === null ? 'admin' : 'user',
        ]);

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

    // ── Named app-scope secrets ─────────────────────────────────────────────

    /**
     * Generic instance-scope secret slot, addressed by name rather than by
     * provider.
     *
     * The per-provider API key covers "the one credential a provider needs";
     * some providers need a second one — the local provider's extra request
     * headers (a Cloudflare Access client secret lives in there) and its client
     * key passphrase. Those go here rather than into app config so they stay
     * encrypted at rest, and rather than into credentialKey() so they cannot
     * collide with a provider id.
     *
     * Instance scope only: every field backed by one of these is SCOPE_ADMIN.
     */
    private function secretKey(string $name): string {
        return self::SECRET_KEY_PREFIX . $name;
    }

    public function getSecret(string $name): string {
        $value = $this->credentialsManager->retrieve('', $this->secretKey($name));
        return is_string($value) ? $value : '';
    }

    public function setSecret(string $name, #[\SensitiveParameter] string $value): void {
        $this->credentialsManager->store('', $this->secretKey($name), $value);
        $this->logger->info('AIquila: secret stored', ['secret' => $name]);
    }

    public function deleteSecret(string $name): void {
        $this->credentialsManager->delete('', $this->secretKey($name));
        $this->logger->info('AIquila: secret cleared', ['secret' => $name]);
    }

    public function hasSecret(string $name): bool {
        return $this->getSecret($name) !== '';
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
