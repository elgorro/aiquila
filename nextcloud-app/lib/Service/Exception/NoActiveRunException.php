<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The coworker exists but has no run in flight (`running` or `pending`) to
 * stop. A DoesNotExistException, so a caller that only distinguishes found
 * from not found keeps working; the message tells the two apart.
 */
class NoActiveRunException extends DoesNotExistException {
}
