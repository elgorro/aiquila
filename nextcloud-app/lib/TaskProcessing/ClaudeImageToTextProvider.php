<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\ImageOptimizer;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use Psr\Log\LoggerInterface;

/**
 * Claude Vision TaskProcessing Provider (single image)
 *
 * Registers Claude as an image-to-text (vision) provider in Nextcloud's
 * TaskProcessing framework (NC 29+). This enables "Describe this image"
 * actions in Files, Photos, and the Nextcloud Assistant.
 *
 * Input:  image (binary)
 * Output: output (string)
 */
class ClaudeImageToTextProvider implements ISynchronousProvider {

    public function __construct(
        private ClaudeSDKService $claudeService,
        private ImageOptimizer $imageOptimizer,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:image_to_text';
    }

    public function getName(): string {
        return 'Claude Vision (AIquila)';
    }

    public function getTaskTypeId(): string {
        return 'core:image2text';
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function getOptionalInputShape(): array {
        return [
            'prompt' => new ShapeDescriptor(
                'Prompt',
                'Optional question or instruction about the image',
                EShapeType::Text
            ),
        ];
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
        return ['prompt' => 'Describe this image in detail.'];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        if (empty($input['image'])) {
            throw new \RuntimeException('No image provided in task input');
        }

        $imageData = $input['image'];
        $prompt = !empty($input['prompt']) ? $input['prompt'] : 'Describe this image in detail.';

        $mimeType = $this->detectMimeType($imageData);

        $this->logger->debug('Claude ImageToText: Processing image', [
            'mime_type' => $mimeType,
            'prompt_length' => strlen($prompt),
        ]);

        $reportProgress(0.3);

        // Optimize image for Claude Vision
        if ($this->imageOptimizer->isSupported($mimeType)) {
            $optimized = $this->imageOptimizer->optimize($imageData, $mimeType);
            $base64 = $optimized['data'];
            $mimeType = $optimized['mimeType'];
        } else {
            $base64 = base64_encode($imageData);
        }

        $reportProgress(0.5);

        $result = $this->claudeService->askWithImage(
            $prompt,
            $base64,
            $mimeType,
            $userId,
        );

        if (isset($result['error'])) {
            $this->logger->error('Claude ImageToText: Error', ['error' => $result['error']]);
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
