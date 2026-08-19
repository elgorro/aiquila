<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * A size limit was exceeded — archive contents, extracted output, or a file
 * read. Maps to HTTP 413 by type rather than by matching on message text.
 */
class ContentTooLargeException extends \RuntimeException {
}
