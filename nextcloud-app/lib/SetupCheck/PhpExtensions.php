<?php

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class PhpExtensions implements ISetupCheck {
    private const REQUIRED_EXTENSIONS = ['curl', 'json', 'mbstring', 'openssl'];

    public function __construct(
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('AIquila PHP extensions');
    }

    public function getCategory(): string {
        return 'system';
    }

    public function run(): SetupResult {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if ($missing === []) {
            return SetupResult::success(
                $this->l10n->t('All required PHP extensions are installed: %s.', [implode(', ', self::REQUIRED_EXTENSIONS)])
            );
        }

        return SetupResult::error(
            $this->l10n->t('Missing PHP extensions required by AIquila: %s. Install them and restart your web server.', [implode(', ', $missing)])
        );
    }
}
