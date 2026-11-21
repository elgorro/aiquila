<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\StreamTraversableResponse;

/**
 * A file download whose body is produced while it is sent.
 *
 * StreamTraversableResponse writes each chunk and flushes, but an output
 * buffer the framework or php.ini installed would still collect the whole
 * body before it leaves, which is the memory a streamed export exists to
 * avoid. This drains those buffers first, lifts the time limit and releases
 * the session lock, as SSEResponse does for chat streams.
 *
 * @template-extends StreamTraversableResponse<Http::STATUS_OK, array<string, mixed>>
 */
class DownloadStreamResponse extends StreamTraversableResponse {
    /** @param \Traversable<int, string> $chunks */
    public function __construct(\Traversable $chunks, string $contentType, string $filename) {
        parent::__construct($chunks, Http::STATUS_OK, [
            'Content-Type' => $contentType,
            // The filename is ours (ASCII, no quotes), never user input.
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function callback(IOutput $output): void {
        @set_time_limit(0);
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        parent::callback($output);
    }
}
