<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Dashboard;

use OCA\AIquila\Template\ViteAssets;

/**
 * Configurable dashboard widget that renders the latest run summary of a
 * user-selected coworker (weather digest, investment watch, hobby feed, …).
 *
 * Unlike the API widgets this one renders client-side: {@see load()} ships the
 * `aiquila-dashboard` bundle which registers a Vue component via
 * `OCA.Dashboard.register('aiquila_coworker_output', …)`. The component reads
 * and persists the selected coworker through the dashboard endpoints on
 * {@see \OCA\AIquila\Controller\CoworkerController}.
 */
class CoworkerOutputWidget extends AbstractAIquilaWidget {
    public function getId(): string {
        return 'aiquila_coworker_output';
    }

    public function getTitle(): string {
        return $this->l10n->t('AIquila coworker');
    }

    public function getOrder(): int {
        return 40;
    }

    protected function hashRoute(): string {
        return '/cowork';
    }

    public function load(): void {
        ViteAssets::load(self::APP_ID . '-dashboard');
    }
}
