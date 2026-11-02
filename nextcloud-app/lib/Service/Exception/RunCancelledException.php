<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * Thrown from a run's progress callback once the run has been stopped, to
 * end the task type's loop at the next item boundary. CoworkerService
 * catches it and closes the run as cancelled; task types let it propagate.
 */
class RunCancelledException extends \RuntimeException {
}
