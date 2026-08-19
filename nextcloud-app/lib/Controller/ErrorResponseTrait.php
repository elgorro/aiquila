<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Builds the app's single error-response shape.
 *
 * Controllers used to return `$e->getMessage()` straight to the client from bare
 * `catch (\Throwable)` blocks. Exceptions the app never authored — ZipArchive,
 * tempnam, stream failures — carry absolute server paths in their message, so
 * that leaked filesystem layout to any caller who could provoke a 500.
 *
 * Every error now returns a fixed, human-meaningful message plus a short random
 * `errorId`. The full exception, with the same id, goes to the Nextcloud log, so
 * an admin can match a user's report to the stack trace without the client ever
 * seeing internals.
 *
 * Consumers must expose a logger:
 *
 * ```php
 * private LoggerInterface $logger;
 * ```
 *
 * @psalm-type TErrorStatus = 400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511
 */
trait ErrorResponseTrait {

    /**
     * Logs the exception and returns the sanitized response for the client.
     *
     * @template TStatus of TErrorStatus
     * @param TStatus $status HTTP status to return
     * @param string $publicMessage Safe text for the client. Must not interpolate
     *                              an exception message or a filesystem path.
     * @return JSONResponse<TStatus, array{error: string, errorId: string}, array{}>
     */
    private function errorResponse(
        \Throwable $e,
        int $status,
        string $publicMessage,
        string $context = '',
    ): JSONResponse {
        $errorId = $this->newErrorId();

        $this->logger->error(
            ($context !== '' ? $context : static::class) . ' failed [' . $errorId . ']',
            ['exception' => $e, 'errorId' => $errorId],
        );

        return new JSONResponse(
            ['error' => $publicMessage, 'errorId' => $errorId],
            $status,
        );
    }

    /**
     * Error response for a condition the app detected itself, with no exception
     * behind it — a validation failure, a missing parameter. The message is
     * written by us and therefore safe to show; it is not logged as an error.
     *
     * @template TStatus of TErrorStatus
     * @param TStatus $status
     * @return JSONResponse<TStatus, array{error: string, errorId: string}, array{}>
     */
    private function clientError(int $status, string $publicMessage): JSONResponse {
        /** @var JSONResponse<TStatus, array{error: string, errorId: string}, array{}> $response */
        $response = new JSONResponse(
            ['error' => $publicMessage, 'errorId' => ''],
            $status,
        );

        return $response;
    }

    /**
     * Short correlation id shared between the response and the log line.
     *
     * Not a secret and not required to be globally unique — it only has to be
     * findable in a log covering the same few days.
     */
    private function newErrorId(): string {
        return bin2hex(random_bytes(4));
    }
}
