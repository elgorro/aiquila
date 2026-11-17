<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * A manual batch run that would exceed a limit. The message names the limit
 * and is safe to return to the client, like a {@see ValidationException}.
 */
class BatchRunLimitException extends \RuntimeException {
    public const ITEMS = 'items';
    public const RATE = 'rate';

    /**
     * @param 'items'|'rate' $kind which limit: files per run, or runs per hour
     */
    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly int $limit,
    ) {
        parent::__construct($message);
    }
}
