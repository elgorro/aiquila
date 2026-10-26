<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The optional cloud SDKs: `aws/aws-sdk-php` (SigV4 signing, the AWS
 * credential chain) and `google/auth` (service accounts, Application Default
 * Credentials). The Anthropic SDK only `suggest`s them and the app does not
 * bundle them — aws-sdk-php alone is several times the size of the app.
 *
 * Key and token auth works without either. For the rest, an admin installs the
 * package into a directory of their own and points the system config key
 * `aiquila.cloud_sdk_autoload` at its `vendor/autoload.php`. It is read from
 * config.php on purpose: loading a PHP file is code execution, so the path must
 * not be settable through any web endpoint, including the provisioning API that
 * can write app config. An app update replaces the app directory, which is why
 * the packages cannot simply be required into it.
 */
class CloudSdk {
    public const SYSTEM_CONFIG_KEY = 'aiquila.cloud_sdk_autoload';

    public const AWS = 'aws/aws-sdk-php';
    public const GOOGLE = 'google/auth';

    private bool $autoloadAttempted = false;

    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Whether the package is loadable, pulling in the external autoloader first if one is configured. */
    public function isAvailable(string $package): bool {
        if ($this->detect($package)) {
            return true;
        }
        $this->loadExternalAutoloader();
        return $this->detect($package);
    }

    /**
     * @throws \RuntimeException naming the package and how to install it
     */
    public function require(string $package, string $what): void {
        if (!$this->isAvailable($package)) {
            throw new \RuntimeException(sprintf(
                '%s requires the %s package. Install it outside the app directory and set "%s" in config.php to its vendor/autoload.php.',
                $what,
                $package,
                self::SYSTEM_CONFIG_KEY,
            ));
        }
    }

    /** The configured autoloader path, or '' when none is set. */
    public function autoloadPath(): string {
        return trim($this->config->getSystemValueString(self::SYSTEM_CONFIG_KEY, ''));
    }

    protected function detect(string $package): bool {
        return match ($package) {
            self::AWS => class_exists(\Aws\Signature\SignatureV4::class),
            self::GOOGLE => interface_exists(\Google\Auth\FetchAuthTokenInterface::class),
            default => false,
        };
    }

    /**
     * Load the admin's external autoloader, once per process.
     *
     * Composer registers its loader in front of everything else. That would let
     * the external tree shadow Nextcloud's own copies of shared libraries
     * (Guzzle, PSR interfaces) for any class not loaded yet, so it is moved to
     * the back: Nextcloud and the app resolve first, and only what they do not
     * ship comes from the external directory.
     */
    private function loadExternalAutoloader(): void {
        if ($this->autoloadAttempted) {
            return;
        }
        $this->autoloadAttempted = true;

        $path = $this->autoloadPath();
        if ($path === '') {
            return;
        }
        if (!str_starts_with($path, '/') || basename($path) !== 'autoload.php' || !is_file($path) || !is_readable($path)) {
            $this->logger->warning('AIquila: ' . self::SYSTEM_CONFIG_KEY . ' must be the absolute path of a readable vendor/autoload.php', [
                'path' => $path,
            ]);
            return;
        }

        try {
            $loader = require_once $path;
        } catch (\Throwable $e) {
            $this->logger->error('AIquila: could not load the cloud SDK autoloader', ['path' => $path, 'exception' => $e]);
            return;
        }
        if ($loader instanceof \Composer\Autoload\ClassLoader) {
            $loader->unregister();
            $loader->register(false);
        }
    }
}
