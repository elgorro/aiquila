<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\Provider\HetznerProvider;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes a `hetzner_base_url` override that still names the retired endpoint.
 *
 * Hetzner moved the Inference API from `/api/v1` to `/v1`. An admin who typed
 * the old URL into the override would otherwise keep calling a dead endpoint,
 * and the override would take precedence over the corrected default. Deleting
 * the key puts the instance back on that default. Any other override is left
 * alone.
 */
class RepairHetznerBaseUrl implements IRepairStep {
    private const RETIRED_BASE_URL = 'https://inference.hetzner.com/api/v1';

    public function __construct(
        private AppSettings $settings,
    ) {
    }

    public function getName(): string {
        return 'Remove the retired Hetzner Inference endpoint override';
    }

    public function run(IOutput $output): void {
        $stored = $this->settings->getAppValue('aiquila', 'hetzner_base_url', '');
        if (HetznerProvider::normalizeBaseUrl($stored) !== self::RETIRED_BASE_URL) {
            return;
        }
        $this->settings->deleteAppValue('aiquila', 'hetzner_base_url');
        $output->info('Removed the retired Hetzner Inference endpoint override; the default endpoint is used now.');
    }
}
