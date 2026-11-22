<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * A model's answer that is not what the operation asked for, such as a table
 * row with a value of the wrong type. Reported per file as "invalid".
 */
final class InvalidAnswerException extends \RuntimeException {
}
