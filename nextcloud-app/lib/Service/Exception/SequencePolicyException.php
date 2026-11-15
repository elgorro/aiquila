<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * The admin's sequence policy forbids the change (personal sequences are
 * switched off). Maps to HTTP 403. The message is written by the app and safe
 * to show.
 */
class SequencePolicyException extends \RuntimeException {
}
