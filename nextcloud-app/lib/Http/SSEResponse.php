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
 * Reverse-proxy buffering is the typical failure mode in production:
 *   - Nginx: respects the `X-Accel-Buffering: no` header (we set it).
 *   - Apache: usually fine; if mod_deflate kicks in, ensure it does
 *     not target text/event-stream responses.
 *   - PHP-FPM: `fastcgi_buffering off` may be needed at the proxy.
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
            $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                continue;
            }
            echo 'data: ' . $payload . "\n\n";
            @flush();

            // If the client has gone away, stop generating work.
            if (connection_aborted() === 1) {
                break;
            }
        }

        return '';
    }
}
