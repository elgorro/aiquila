<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Thrown when a user is denied every registered provider.
 *
 * Provider resolution fails closed: rather than handing back a provider the
 * admin has blocked, the factory raises this and the caller turns it into a 403.
 * Callers that render a page catch it and show the "no provider available" state.
 */
class NoPermittedProviderException extends \RuntimeException {
    public const USER_MESSAGE = 'No AI provider is available for your account. Ask your administrator for access.';

    public function __construct(?string $userId = null) {
        parent::__construct(
            $userId === null
                ? self::USER_MESSAGE
                : self::USER_MESSAGE . ' (user: ' . $userId . ')',
        );
    }
}
