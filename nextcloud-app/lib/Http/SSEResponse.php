<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Server-Sent Events response. Holds an iterable of associative arrays;
 * each one is encoded as a single SSE `data: <json>\n\n` block. Output
 * buffering is drained and `flush()` is called per event so chunks reach the
 * client as they are produced.
 *
 * It is an ICallbackResponse, the framework's path for streamed bodies:
 * the dispatcher calls render(), which writes nothing, then App::main() sends
 * the status line and every header (this response's own plus the CSP and
 * feature-policy ones) and only then calls callback(), which streams. Writing
 * the body from render() instead puts output on the wire before App::main()
 * sends the headers, so the declared Content-Type is lost and every later
 * header() raises "headers already sent".
 *
 * Reverse-proxy buffering is the typical failure mode in production:
 *   - Nginx: respects the `X-Accel-Buffering: no` header (we set it).
 *   - Apache: usually fine; if mod_deflate kicks in, ensure it does
 *     not target text/event-stream responses.
 *   - PHP-FPM: `fastcgi_buffering off` may be needed at the proxy.
 */
/**
 * @template-extends Response<Http::STATUS_OK, array<string, mixed>>
 */
class SSEResponse extends Response implements ICallbackResponse {
    /** @param iterable<array<string, mixed>> $events */
    public function __construct(
        private readonly iterable $events,
    ) {
        parent::__construct(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-cache, no-transform');
        $this->addHeader('Connection', 'keep-alive');
        $this->addHeader('X-Accel-Buffering', 'no');
    }

    /** The body is written by callback(), after the headers. */
    public function render(): string {
        return '';
    }

    public function callback(IOutput $output): void {
        // Long-running response; opt out of the script time limit.
        @set_time_limit(0);

        // Some proxies and SAPIs decide on compression from the request
        // environment rather than the content type. Say no there too, before
        // the first byte.
        if (function_exists('apache_setenv')) {
            /** @psalm-suppress UnusedFunctionCall the point is the side effect on the request env */
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');

        // Release the PHP session lock so other requests for this user can proceed.
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        // Drain any buffer the framework / php.ini installed.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);

        foreach ($this->events as $event) {
            $frame = self::frame($event);
            if ($frame === null) {
                continue;
            }
            echo $frame;
            @flush();

            // If the client has gone away, stop generating work.
            if (connection_aborted() === 1) {
                break;
            }
        }
    }

    /**
     * One event as its wire representation, or null when it cannot be encoded
     * — in which case the event is dropped and the stream carries on, since a
     * half-written frame would desynchronise the client's parser.
     *
     * @param array<string, mixed> $event
     */
    public static function frame(array $event): ?string {
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return null;
        }

        return 'data: ' . $payload . "\n\n";
    }
}
