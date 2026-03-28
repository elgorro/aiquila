<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Service\ImageOptimizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageOptimizerTest extends TestCase {
    private ImageOptimizer $optimizer;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->optimizer = new ImageOptimizer($this->logger);
    }

    // ── isSupported() ──────────────────────────────────────────────────────

    public function testIsSupportedReturnsTrueForJpeg(): void {
        $this->assertTrue($this->optimizer->isSupported('image/jpeg'));
    }

    public function testIsSupportedReturnsTrueForPng(): void {
        $this->assertTrue($this->optimizer->isSupported('image/png'));
    }

    public function testIsSupportedReturnsTrueForGif(): void {
        $this->assertTrue($this->optimizer->isSupported('image/gif'));
    }

    public function testIsSupportedReturnsTrueForWebp(): void {
        $this->assertTrue($this->optimizer->isSupported('image/webp'));
    }

    public function testIsSupportedReturnsFalseForPdf(): void {
        $this->assertFalse($this->optimizer->isSupported('application/pdf'));
    }

    public function testIsSupportedReturnsFalseForTextPlain(): void {
        $this->assertFalse($this->optimizer->isSupported('text/plain'));
    }

    public function testIsSupportedReturnsFalseForSvg(): void {
        $this->assertFalse($this->optimizer->isSupported('image/svg+xml'));
    }

    // ── optimize() ─────────────────────────────────────────────────────────

    public function testOptimizeReturnsBase64ForSmallImage(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Create a small 100x100 PNG
        $img = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($img);
        $rawBytes = ob_get_clean();
        imagedestroy($img);

        $result = $this->optimizer->optimize($rawBytes, 'image/png');

        $this->assertEquals('image/png', $result['mimeType']);
        $this->assertFalse($result['resized']);
        $this->assertEquals(base64_encode($rawBytes), $result['data']);
    }

    public function testOptimizeResizesLargeImage(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Create a 2000x1000 JPEG (exceeds 1568px long edge)
        $img = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagejpeg($img, null, 90);
        $rawBytes = ob_get_clean();
        imagedestroy($img);

        $result = $this->optimizer->optimize($rawBytes, 'image/jpeg');

        $this->assertEquals('image/jpeg', $result['mimeType']);
        $this->assertTrue($result['resized']);

        // Verify the resized image fits within 1568px
        $resizedBytes = base64_decode($result['data']);
        $dims = getimagesizefromstring($resizedBytes);
        $this->assertLessThanOrEqual(1568, max($dims[0], $dims[1]));
        // Check aspect ratio preserved (2:1)
        $this->assertEqualsWithDelta(2.0, $dims[0] / $dims[1], 0.05);
    }

    public function testOptimizePreservesFormatForPng(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $img = imagecreatetruecolor(2000, 2000);
        ob_start();
        imagepng($img);
        $rawBytes = ob_get_clean();
        imagedestroy($img);

        $result = $this->optimizer->optimize($rawBytes, 'image/png');

        $this->assertEquals('image/png', $result['mimeType']);
        $this->assertTrue($result['resized']);
    }

    public function testOptimizeSkipsResizeForGif(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Create a large GIF — should be skipped (may be animated)
        $img = imagecreatetruecolor(2000, 2000);
        ob_start();
        imagegif($img);
        $rawBytes = ob_get_clean();
        imagedestroy($img);

        $result = $this->optimizer->optimize($rawBytes, 'image/gif');

        $this->assertEquals('image/gif', $result['mimeType']);
        $this->assertFalse($result['resized']);
    }

    public function testOptimizePassesThroughUnsupportedMime(): void {
        $rawBytes = 'not-a-real-image';

        $result = $this->optimizer->optimize($rawBytes, 'application/pdf');

        $this->assertEquals('application/pdf', $result['mimeType']);
        $this->assertFalse($result['resized']);
        $this->assertEquals(base64_encode($rawBytes), $result['data']);
    }

    public function testOptimizeHandlesCorruptImageGracefully(): void {
        $rawBytes = 'definitely-not-a-png';

        $result = $this->optimizer->optimize($rawBytes, 'image/png');

        // Should fall back gracefully
        $this->assertEquals('image/png', $result['mimeType']);
        $this->assertFalse($result['resized']);
        $this->assertEquals(base64_encode($rawBytes), $result['data']);
    }

    // ── MAX_IMAGES constant ────────────────────────────────────────────────

    public function testMaxImagesConstant(): void {
        $this->assertEquals(20, ImageOptimizer::MAX_IMAGES);
    }
}
