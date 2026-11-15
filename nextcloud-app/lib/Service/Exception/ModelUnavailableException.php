<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * A request failed because of the model it named: not found, retired, not
 * enabled for the account or deployment. The message is the user-facing
 * sentence from ModelCompatibility (or the platform's equivalent); the
 * provider's raw error stays on getPrevious() and in the log.
 */
final class ModelUnavailableException extends \RuntimeException {
    public function __construct(string $message, public readonly string $model, ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
    }
}
