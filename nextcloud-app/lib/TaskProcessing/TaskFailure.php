<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;

/**
 * The exceptions a TaskProcessing provider fails with.
 *
 * Nextcloud stores a failed task's exception message as `errorMessage`, which
 * only the log and AIquila's own notification read. `userFacingErrorMessage` —
 * the field the Assistant and the MCP task tools show — is filled only from a
 * UserFacingProcessingException, so a plain RuntimeException reaches them as
 * "failed" with no reason. Core also logs anything that is not a
 * ProcessingException as an unknown error.
 *
 * Two kinds of failure, two factories:
 *
 * - userFacing(): a message AIquila wrote itself (missing input, a provider
 *   without the needed capability, an empty result). It is shown as it is.
 * - fromProviderError(): an error a provider service handed back. Those embed
 *   upstream text often enough — "Mistral API error: <response body>" — that
 *   they are never shown verbatim. The failure is classified into one of a
 *   fixed set of sentences the user can act on; the full text stays in
 *   `errorMessage`, the log and the notification. An error that fits no class
 *   gets no user-facing message rather than a guessed one.
 */
final class TaskFailure {

    /**
     * Checked in order, first match wins: an error can mention several of
     * these ("403 … quota"), and the earlier ones are the more specific cause.
     * Status codes are matched as whole words so ids and byte counts do not.
     *
     * @var array<string, array{string, string}> class => [pattern, message]
     */
    private const CLASSES = [
        'too_large' => [
            '/\b413\b|too large|too long|too many images|context (?:length|window)|maximum context|token limit|exceeds the maximum/i',
            'The input is too large for the AI provider. Shorten it or split it into smaller parts.',
        ],
        'rate_limited' => [
            '/\b429\b|rate.?limit|quota|too many requests|credit balance|billing/i',
            'The AI provider\'s rate limit or quota was reached. Try again later.',
        ],
        'not_configured' => [
            '/\bnot configured\b(?! with)|\bno [\w ]+ configured\b/i',
            'The AI provider is not set up yet. Configure it in your AIquila settings, or ask your administrator.',
        ],
        'proxy' => [
            '/\b407\b/',
            'The AI provider could not be reached. Try again later; if this keeps happening, ask your administrator to check the AIquila provider settings.',
        ],
        'auth' => [
            '/\b40[13]\b|unauthori[sz]ed|forbidden|authentication|api key|\btoken\b|credential|permission/i',
            'The AI provider rejected AIquila\'s credentials. Check the API key in your AIquila settings, or ask your administrator.',
        ],
        'model' => [
            '/\b404\b|not.?found|does not exist|unknown model|no such model|vision-capable model/i',
            'The AI provider does not offer the configured model. Pick another model in your AIquila settings, or ask your administrator.',
        ],
        'unreachable' => [
            '/connection|could not reach|could not be resolved|resolve host|nothing is listening|timed out|timeout|\btls\b|\bssl\b|certificate|curl error|\b50[0234]\b|\b529\b|unavailable|overloaded|blocked the request|dropped/i',
            'The AI provider could not be reached. Try again later; if this keeps happening, ask your administrator to check the AIquila provider settings.',
        ],
    ];

    /**
     * A failure AIquila describes itself; the message is shown to the user.
     */
    public static function userFacing(string $message, ?\Throwable $previous = null): UserFacingProcessingException {
        return new UserFacingProcessingException($message, 0, $previous, $message);
    }

    /**
     * A failure a provider service reported. $error is kept as the internal
     * message; the user sees the class it falls into, if any.
     */
    public static function fromProviderError(string $error, ?\Throwable $previous = null): ProcessingException {
        $message = self::classify($error);
        return $message === null
            ? new ProcessingException($error, 0, $previous)
            : new UserFacingProcessingException($error, 0, $previous, $message);
    }

    /**
     * The user-facing sentence for $error, or null when it fits no class.
     */
    public static function classify(string $error): ?string {
        foreach (self::CLASSES as [$pattern, $message]) {
            if (preg_match($pattern, $error) === 1) {
                return $message;
            }
        }
        return null;
    }
}
