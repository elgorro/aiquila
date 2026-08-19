<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * A write would clobber something that is already there.
 *
 * Exists so controllers can map the condition to HTTP 409 by type. The status
 * used to be derived with str_contains($e->getMessage(), 'already exists'),
 * which silently broke the moment error messages stopped being echoed verbatim.
 */
class DestinationExistsException extends \RuntimeException {
}
