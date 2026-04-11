<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCA\AIquila\Service\CredentialService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class ApiKeyConfigured implements ISetupCheck {
    public function __construct(
        private CredentialService $credentials,
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('AIquila API key');
    }

    public function getCategory(): string {
        return 'system';
    }

    public function run(): SetupResult {
        if ($this->credentials->hasApiKey(null)) {
            return SetupResult::success($this->l10n->t('Anthropic API key is configured.'));
        }

        return SetupResult::error(
            $this->l10n->t('No Anthropic API key configured. Go to Settings → Administration → AIquila to add your API key.')
        );
    }
}
