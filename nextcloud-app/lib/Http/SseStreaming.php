<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;

/**
 * Transport settings that make an upstream Server-Sent Events response arrive
 * incrementally instead of in one piece once generation has finished.
 *
 * Nextcloud's IClientService is the right client to use — it applies the
 * instance CA bundle, the configured proxy and the local-address guard — but
 * two of its defaults are wrong for SSE, and both fail silently: the reply is
 * complete and correct, it just all lands at the end.
 */
trait SseStreaming {
    /**
     * Read granularity for an upstream SSE stream, in bytes. PHP's HTTP stream
     * wrapper only returns whole chunks, so this is the real determinant of
     * streaming latency: at the 8192 default, fread() waits for roughly two
     * hundred SSE events before returning; at 64 the deltas come through as the
     * model produces them.
     */
    protected const SSE_CHUNK_SIZE = 64;

    /**
     * Request options to merge into a `stream => true` IClientService call.
     *
     * - `handler`: Guzzle's cURL handlers cannot stream a response, which is
     *   why Guzzle's default stack routes `stream => true` requests to the
     *   StreamHandler. Nextcloud pins a bare CurlHandler on the client, so
     *   without this override the whole body is downloaded before the first
     *   read returns. Guzzle honours `handler` per request, so everything else
     *   Nextcloud puts on the request still applies.
     * - `version`: Nextcloud pins HTTP/2, which the StreamHandler refuses with
     *   `HTTP/2.0 is not supported by the stream handler`.
     *
     * Guzzle is Nextcloud's HTTP implementation rather than a dependency this
     * app declares, so the handler override is skipped when those classes are
     * not present: the request still works, it just is not streamed.
     *
     * @return array<string, mixed>
     */
    protected function sseTransportOptions(): array {
        $options = ['version' => '1.1'];
        if (class_exists(HandlerStack::class) && class_exists(StreamHandler::class)) {
            $options['handler'] = HandlerStack::create(new StreamHandler());
        }

        return $options;
    }

    /**
     * Apply the SSE read granularity to a stream resource handed back by
     * IResponse::getBody().
     *
     * @param resource $stream
     */
    protected function tuneSseStream($stream): void {
        /** @psalm-suppress UnusedFunctionCall the point is the side effect on $stream */
        @stream_set_chunk_size($stream, static::SSE_CHUNK_SIZE);
    }
}
