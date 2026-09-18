<?php
/**
 * Minimal Guzzle stubs for Psalm.
 *
 * Guzzle is Nextcloud's HTTP implementation, shipped in the server's
 * `3rdparty/` tree rather than in this app's vendor directory. Only the two
 * classes the SSE transport touches are declared here; they are never loaded
 * by Psalm at runtime, only resolved.
 *
 * @see \OCA\AIquila\Http\SseStreaming
 */

namespace GuzzleHttp {
    class HandlerStack {
        public static function create(?callable $handler = null): self {
        }
    }
}

namespace GuzzleHttp\Handler {
    class StreamHandler {
        public function __invoke(object $request, array $options): object {
        }
    }
}
