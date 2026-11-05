<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCA\AIquila\Service\CredentialService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Warns admins of an instance with a Hetzner token that experiment tokens end.
 *
 * Only the instance token is looked at: a setup check runs without a user, and
 * personal tokens get the same notice on their settings field and in errors.
 */
class HetznerExperimentEnding implements ISetupCheck {
    public function __construct(
        private CredentialService $credentials,
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('Hetzner Inference experiment');
    }

    public function getCategory(): string {
        return 'system';
    }

    public function run(): SetupResult {
        if (!$this->credentials->hasApiKey(null, 'hetzner')) {
            return SetupResult::success($this->l10n->t('No Hetzner Inference token is configured.'));
        }

        return SetupResult::warning(
            $this->l10n->t('Hetzner is replacing the Inference experiment with an official product. Experiment tokens stop working on 8 December 2026 and no new ones are issued; Hetzner will announce the migration details at launch. Migrate when it does, or choose another provider under Settings → Administration → AIquila.')
        );
    }
}
