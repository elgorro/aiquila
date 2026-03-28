<?php

declare(strict_types=1);

namespace OCA\AIquila\Service;

use Psr\Log\LoggerInterface;

/**
 * Optimizes images before sending to Claude Vision API.
 *
 * Resizes images whose long edge exceeds 1568 px (Claude's optimal resolution)
 * to reduce token usage without losing visual quality.  Uses the GD library
 * (bundled with PHP) and falls back gracefully when GD is unavailable.
 */
class ImageOptimizer {
    /** Claude's recommended maximum long-edge resolution. */
    private const MAX_LONG_EDGE = 1568;

    /** Maximum number of images Claude accepts per request. */
    public const MAX_IMAGES = 20;

    /** MIME types supported by Claude Vision. */
    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check whether a MIME type is supported by Claude Vision.
     */
    public function isSupported(string $mimeType): bool {
        return in_array($mimeType, self::SUPPORTED_MIMES, true);
    }

    /**
     * Optimize an image for Claude Vision.
     *
     * If the long edge exceeds MAX_LONG_EDGE the image is down-scaled while
     * preserving its aspect ratio.  The original format is preserved where
     * possible (JPEG → JPEG, PNG → PNG, WebP → WebP, GIF → PNG when resized).
     *
     * @param string $rawBytes Raw image bytes
     * @param string $mimeType Original MIME type
     * @return array{data: string, mimeType: string, resized: bool}
     *         data is base64-encoded
     */
    public function optimize(string $rawBytes, string $mimeType): array {
        $fallback = [
            'data' => base64_encode($rawBytes),
            'mimeType' => $mimeType,
            'resized' => false,
        ];

        if (!$this->isSupported($mimeType)) {
            return $fallback;
        }

        if (!function_exists('imagecreatefromstring')) {
            $this->logger->warning('AIquila ImageOptimizer: GD not available, skipping resize');
            return $fallback;
        }

        $dims = @getimagesizefromstring($rawBytes);
        if ($dims === false) {
            $this->logger->warning('AIquila ImageOptimizer: Could not read image dimensions, skipping resize');
            return $fallback;
        }

        [$width, $height] = $dims;
        $longEdge = max($width, $height);

        if ($longEdge <= self::MAX_LONG_EDGE) {
            return $fallback;
        }

        // Animated GIFs: skip resize (GD loses animation)
        if ($mimeType === 'image/gif') {
            $this->logger->debug('AIquila ImageOptimizer: Skipping GIF resize (may be animated)', [
                'width' => $width, 'height' => $height,
            ]);
            return $fallback;
        }

        try {
            return $this->resize($rawBytes, $mimeType, $width, $height);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila ImageOptimizer: Resize failed, using original', [
                'error' => $e->getMessage(),
            ]);
            return $fallback;
        }
    }

    /**
     * Perform the actual resize using GD.
     *
     * @return array{data: string, mimeType: string, resized: bool}
     */
    private function resize(string $rawBytes, string $mimeType, int $width, int $height): array {
        $src = @imagecreatefromstring($rawBytes);
        if ($src === false) {
            throw new \RuntimeException('imagecreatefromstring() failed');
        }

        try {
            $scale = self::MAX_LONG_EDGE / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $dst = imagescale($src, $newWidth, $newHeight, IMG_BICUBIC);
            if ($dst === false) {
                throw new \RuntimeException('imagescale() failed');
            }

            try {
                ob_start();
                $outputMime = $mimeType;

                switch ($mimeType) {
                    case 'image/jpeg':
                        imagejpeg($dst, null, 85);
                        break;
                    case 'image/png':
                        imagepng($dst, null, 6);
                        break;
                    case 'image/webp':
                        if (function_exists('imagewebp')) {
                            imagewebp($dst, null, 85);
                        } else {
                            // Fall back to PNG if WebP output not supported
                            imagepng($dst, null, 6);
                            $outputMime = 'image/png';
                        }
                        break;
                    default:
                        // Shouldn't reach here (GIF is handled above), but just in case
                        imagepng($dst, null, 6);
                        $outputMime = 'image/png';
                        break;
                }

                $output = ob_get_clean();
                if ($output === false || $output === '') {
                    throw new \RuntimeException('Image output buffer empty');
                }

                $this->logger->debug('AIquila ImageOptimizer: Resized image', [
                    'from' => "{$width}x{$height}",
                    'to' => "{$newWidth}x{$newHeight}",
                    'originalSize' => strlen($rawBytes),
                    'newSize' => strlen($output),
                ]);

                return [
                    'data' => base64_encode($output),
                    'mimeType' => $outputMime,
                    'resized' => true,
                ];
            } finally {
                imagedestroy($dst);
            }
        } finally {
            imagedestroy($src);
        }
    }
}
