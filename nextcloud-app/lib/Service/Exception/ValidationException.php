<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Exception;

/**
 * Input the caller got wrong, with a message written by this app.
 *
 * The message of this exception — and only of this exception — is safe to return
 * to the client verbatim. Everything else reaching a controller may be a PHP,
 * ext, or HTTP-client exception whose message carries absolute server paths, so
 * the marker type is what lets a catch block tell "safe to echo" from "log it
 * and return a fixed string".
 */
class ValidationException extends \InvalidArgumentException {
}
