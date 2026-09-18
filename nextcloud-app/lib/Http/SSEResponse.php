<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

/**
 * Server-Sent Events response. Holds an iterable of associative arrays;
 * each one is encoded as a single SSE `data: <json>\n\n` block during
 * render(). Output buffering is drained and `flush()` is called per
 * event so chunks reach the client as they are produced.
 *
 * The headers are emitted by render() rather than left to the framework —
 * see emitHeaders() for why that is not optional here.
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
class SSEResponse extends Response {
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

    public function render(): string {
        // Long-running response; opt out of the script time limit.
        @set_time_limit(0);

        $this->emitHeaders();

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

        return '';
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

    /**
     * Put this response's headers on the wire before the first event.
     *
     * Nextcloud's dispatcher calls render() and only afterwards does
     * App::main() emit the response headers — but render() writes straight to
     * the output stream, so PHP has already flushed *its* defaults by then and
     * every header this response declared is silently dropped. The stream then
     * goes out as `Content-Type: text/html`, which is not merely wrong: the
     * stock Apache mod_deflate configuration compresses text/html, and a
     * compressed response is a buffered response — the whole reply lands in one
     * piece at the end, which is exactly the symptom SSE has to avoid.
     */
    private function emitHeaders(): void {
        if (headers_sent()) {
            return;
        }
        foreach ($this->getHeaders() as $name => $value) {
            header($name . ': ' . $value, true);
        }

        // Some proxies and SAPIs decide on compression from the request
        // environment rather than the content type. Say no there too.
        if (function_exists('apache_setenv')) {
            /** @psalm-suppress UnusedFunctionCall the point is the side effect on the request env */
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
    }
}
