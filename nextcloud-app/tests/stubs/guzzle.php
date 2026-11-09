<?php
/**
 * Minimal Guzzle stubs for Psalm.
 *
 * Guzzle is Nextcloud's HTTP implementation, shipped in the server's
 * `3rdparty/` tree rather than in this app's vendor directory. Only the
 * classes the app touches are declared here; they are never loaded by Psalm
 * at runtime, only resolved.
 *
 * @see \OCA\AIquila\Http\SseStreaming
 * @see \OCA\AIquila\Service\AudioService::uploadBody()
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

namespace GuzzleHttp\Psr7 {
    final class Utils {
        /**
         * @param mixed $resource
         * @param array{size?: int, metadata?: array} $options
         */
        public static function streamFor($resource = '', array $options = []): \Psr\Http\Message\StreamInterface {
        }
    }
}
