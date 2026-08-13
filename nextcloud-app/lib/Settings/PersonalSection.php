<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Personal counterpart of {@see AdminSection}.
 *
 * Nextcloud keeps admin and personal sections in separate registries, so the
 * `aiquila` id registered for the admin area does not place the personal form —
 * without this class /settings/user/aiquila 404s and the entry never shows up
 * in the personal settings navigation.
 */
class PersonalSection implements IIconSection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'aiquila';
    }

    public function getName(): string {
        return $this->l->t('AIquila');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('aiquila', 'app-dark.svg');
    }
}
