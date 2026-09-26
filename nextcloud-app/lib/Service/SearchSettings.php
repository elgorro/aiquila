<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\AppInfo\Application;
use OCP\IConfig;

/**
 * The "Include AIquila in unified search" admin toggle.
 *
 * Every reader (admin page, capabilities, the search provider) and the writer
 * go through here so they agree on the stored value and on what an unset value
 * means: enabled.
 */
class SearchSettings {
    public const KEY = 'search_enabled';
    public const DEFAULT = '1';

    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY, self::DEFAULT) !== '0';
    }

    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY, $enabled ? '1' : '0');
    }
}
