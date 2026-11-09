<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

/**
 * Sees every chunk written to a stream, as it is written.
 *
 * This is how a response body can be read while it is still arriving over
 * cURL. Streaming the response the usual way (`stream => true`, see
 * SseStreaming) routes the request through Guzzle's StreamHandler, and that
 * handler reads the whole request body into a string first. For an upload of
 * hundreds of megabytes that is exactly what has to be avoided. The cURL
 * handler streams the upload, but hands the response only to a `sink`. With
 * this filter on the sink, each piece reaches a callback the moment cURL
 * writes it, and the data still lands in the sink unchanged.
 *
 * A PHP stream filter rather than a PSR-7 stream decorator, because the
 * PSR-7 interfaces are loaded from Nextcloud's `3rdparty/` and their method
 * signatures differ between psr/http-message 1.x and 2.x.
 */
final class StreamTap extends \php_user_filter {
    public const NAME = 'aiquila.tap';

    /**
     * Call $onChunk with each chunk written to $stream from now on.
     *
     * @param resource $stream
     * @param callable(string): void $onChunk
     */
    public static function attach($stream, callable $onChunk): void {
        if (!in_array(self::NAME, stream_get_filters(), true) && !stream_filter_register(self::NAME, self::class)) {
            throw new \RuntimeException('Could not register the stream tap.');
        }
        if (stream_filter_append($stream, self::NAME, STREAM_FILTER_WRITE, $onChunk) === false) {
            throw new \RuntimeException('Could not attach the stream tap.');
        }
    }

    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (is_callable($this->params)) {
                ($this->params)($bucket->data);
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
