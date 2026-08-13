<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Mount point for the personal settings Vue app.
 *
 * Everything it shows comes from /api/providers and /api/settings, so no state
 * is passed through the template.
 */
class UserSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('aiquila', 'user', [], '');
    }

    public function getSection(): string {
        return 'aiquila';
    }

    public function getPriority(): int {
        return 10;
    }
}
