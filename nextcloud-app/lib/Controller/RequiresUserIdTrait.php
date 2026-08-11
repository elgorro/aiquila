<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

/**
 * Helper for controllers whose routes all require a login.
 *
 * The container injects the user id as `?string` because it has no way to know
 * that a controller is never reached anonymously. Calls that genuinely need a
 * user (mappers scoped by owner, file access) would fatal on null, so funnel
 * them through {@see requireUserId()} instead of passing the property directly.
 */
trait RequiresUserIdTrait {

    /**
     * @throws \RuntimeException if there is no user session, which the route
     *                           attributes already rule out
     */
    private function requireUserId(): string {
        if ($this->userId === null) {
            throw new \RuntimeException('This route requires a logged-in user');
        }

        return $this->userId;
    }
}
