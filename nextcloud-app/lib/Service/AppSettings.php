<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCP\Config\Exceptions\TypeConflictException;
use OCP\Config\IUserConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * AIquila's app and user settings, on IAppConfig and IUserConfig.
 *
 * The app reads and writes every value as a string, as it always has under the
 * IConfig API these methods mirror: flags are '1'/'0', 'true'/'false' or
 * 'yes'/'no' depending on the key, and numbers are cast at the call site.
 * Keeping them strings means no typed getter ever meets a value stored under a
 * different type — which IAppConfig turns into an exception.
 *
 * Values written before this class existed are stored as "mixed" and read back
 * as strings unchanged; the first write turns them into strings.
 *
 * Secrets are not kept here. API keys, tokens and passphrases live encrypted in
 * the credential manager (CredentialService); RepairMovePlaintextApiKeys moves
 * the last plaintext `api_key` values left from early releases.
 */
class AppSettings {
    public function __construct(
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function getAppValue(string $app, string $key, string $default = ''): string {
        try {
            return $this->appConfig->getValueString($app, $key, $default);
        } catch (AppConfigTypeConflictException $e) {
            // Only an admin storing the key with an explicit --type gets here.
            $this->logger->warning('AIquila: app setting {key} is not stored as a string; using the default', ['key' => $key, 'exception' => $e]);
            return $default;
        }
    }

    public function setAppValue(string $app, string $key, string $value): void {
        $this->appConfig->setValueString($app, $key, $value);
    }

    public function deleteAppValue(string $app, string $key): void {
        $this->appConfig->deleteKey($app, $key);
    }

    public function getUserValue(string $userId, string $app, string $key, string $default = ''): string {
        try {
            return $this->userConfig->getValueString($userId, $app, $key, $default);
        } catch (TypeConflictException $e) {
            $this->logger->warning('AIquila: user setting {key} is not stored as a string; using the default', ['key' => $key, 'exception' => $e]);
            return $default;
        }
    }

    public function setUserValue(string $userId, string $app, string $key, string $value): void {
        $this->userConfig->setValueString($userId, $app, $key, $value);
    }

    public function deleteUserValue(string $userId, string $app, string $key): void {
        $this->userConfig->deleteUserConfig($userId, $app, $key);
    }
}
