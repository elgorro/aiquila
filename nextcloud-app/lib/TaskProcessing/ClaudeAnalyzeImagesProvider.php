<?php

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\ImageOptimizer;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use Psr\Log\LoggerInterface;

/**
 * Claude Vision multi-image TaskProcessing Provider
 *
 * Registers Claude as a core:analyze-images provider in Nextcloud's
 * TaskProcessing framework (NC 30+). This powers the "Analyze images"
 * action in the Nextcloud Assistant, supporting up to 20 images at once.
 *
 * Input:  input (text prompt) + images (list of image files)
 * Output: output (text description/analysis)
 */
class ClaudeAnalyzeImagesProvider implements ISynchronousProvider {

    public function __construct(
        private ClaudeSDKService $claudeService,
        private ImageOptimizer $imageOptimizer,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:analyze_images';
    }

    public function getName(): string {
        return 'Claude Vision (AIquila)';
    }

    public function getTaskTypeId(): string {
        return 'core:analyze-images';
    }

    public function getExpectedRuntime(): int {
        return 60;
    }

    public function getOptionalInputShape(): array {
        return [];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalInputShapeDefaults(): array {
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $prompt = $input['input'] ?? 'Describe these images in detail.';
        $imageList = $input['images'] ?? [];

        if (empty($imageList)) {
            throw new \RuntimeException('No images provided');
        }

        if (count($imageList) > ImageOptimizer::MAX_IMAGES) {
            throw new \RuntimeException('Too many images. Maximum is ' . ImageOptimizer::MAX_IMAGES);
        }

        $this->logger->debug('Claude AnalyzeImages: Processing {count} images', [
            'count' => count($imageList),
            'prompt_length' => strlen($prompt),
        ]);

        $images = [];
        $total = count($imageList);
        foreach ($imageList as $i => $imageData) {
            $mimeType = $this->detectMimeType($imageData);

            if ($this->imageOptimizer->isSupported($mimeType)) {
                $optimized = $this->imageOptimizer->optimize($imageData, $mimeType);
                $images[] = [
                    'base64' => $optimized['data'],
                    'mimeType' => $optimized['mimeType'],
                ];
            } else {
                $images[] = [
                    'base64' => base64_encode($imageData),
                    'mimeType' => $mimeType,
                ];
            }

            $reportProgress(($i + 1) / ($total + 1));
        }

        if (count($images) === 1) {
            $result = $this->claudeService->askWithImage(
                $prompt,
                $images[0]['base64'],
                $images[0]['mimeType'],
                $userId,
            );
        } else {
            $result = $this->claudeService->askWithImages(
                $prompt,
                $images,
                $userId,
            );
        }

        if (isset($result['error'])) {
            $this->logger->error('Claude AnalyzeImages: Error', ['error' => $result['error']]);
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }

    /**
     * Detect MIME type from raw image bytes using magic bytes.
     */
    private function detectMimeType(string $data): string {
        if (strlen($data) < 4) {
            return 'image/jpeg';
        }

        $header = substr($data, 0, 4);

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($header, 'GIF8')) {
            return 'image/gif';
        }
        if (str_starts_with($header, 'RIFF') && strlen($data) >= 12 && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/jpeg';
    }
}
