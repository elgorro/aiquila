<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Pseudonymous per-user request attribution.
 *
 * The Anthropic Messages API accepts `metadata.user_id`, an opaque identifier
 * used for abuse detection. We never send the Nextcloud UID itself — it is a
 * login name and therefore personal data. Instead we send
 *
 *     hash_hmac('sha256', $uid, $salt)
 *
 * where `$salt` is a 32-byte random value generated once per instance and held
 * encrypted in the credential manager. Because the salt is instance-specific
 * and secret, the same UID produces different hashes on different AIquila
 * deployments and the mapping cannot be brute-forced from the hash alone —
 * but an admin holding the salt can reconstruct it, which is what makes an
 * Anthropic abuse report actionable.
 *
 * Sending is opt-in (`send_user_metadata`, default off) and the salt can be
 * rotated, which permanently breaks correlation with hashes already sent.
 *
 * See docs/dev/request-metadata.md.
 */
class RequestMetadataService {
    private const APP_NAME = 'aiquila';

    /** Named slot in CredentialService's generic secret namespace. */
    public const SALT_SECRET = 'metadata_salt';

    /** App config key for the opt-in switch. */
    public const ENABLED_KEY = 'send_user_metadata';

    /** Salt length in bytes; rendered as twice as many hex characters. */
    private const SALT_BYTES = 32;

    public function __construct(
        private IConfig $config,
        private CredentialService $credentials,
        private ISecureRandom $random,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether hashed user ids should be attached to outbound requests.
     *
     * Checkboxes have persisted as 'true' or '1' over the app's history, so
     * both are accepted (matching ClaudeSDKService::resolveThinking()).
     */
    public function isEnabled(): bool {
        return in_array(
            $this->config->getAppValue(self::APP_NAME, self::ENABLED_KEY, 'false'),
            ['true', '1'],
            true,
        );
    }

    /**
     * The instance salt, generated on first use.
     */
    public function getSalt(): string {
        $salt = $this->credentials->getSecret(self::SALT_SECRET);
        if ($salt !== '') {
            return $salt;
        }
        return $this->generate('AIquila: generated request-metadata salt');
    }

    /**
     * Replace the salt with a fresh one. Hashes sent before this point can no
     * longer be resolved back to a user.
     */
    public function rotateSalt(): string {
        return $this->generate('AIquila: rotated request-metadata salt');
    }

    private function generate(string $logMessage): string {
        $salt = $this->random->generate(self::SALT_BYTES * 2, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $this->credentials->setSecret(self::SALT_SECRET, $salt);
        $this->logger->info($logMessage);
        return $salt;
    }

    /**
     * Hashed identifier for a user, or null when nothing should be sent.
     *
     * A null/empty $userId is normal: TaskProcessing background jobs, the occ
     * commands and the setup checks all issue requests with no user attached.
     */
    public function hashUserId(?string $userId): ?string {
        if ($userId === null || $userId === '' || !$this->isEnabled()) {
            return null;
        }
        return hash_hmac('sha256', $userId, $this->getSalt());
    }
}
