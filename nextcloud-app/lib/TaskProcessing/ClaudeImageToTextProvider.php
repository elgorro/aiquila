<?php

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\ImageToText;
use Psr\Log\LoggerInterface;

/**
 * Claude Vision TaskProcessing Provider
 *
 * Registers Claude as an image-to-text (vision) provider in Nextcloud's
 * TaskProcessing framework (NC 29+). This enables "Describe this image"
 * right-click actions in Files, Photos, and other NC apps.
 *
 * Input:  image (binary)
 * Output: output (string)
 */
class ClaudeImageToTextProvider implements IProvider {

    private ClaudeSDKService $claudeService;
    private LoggerInterface $logger;

    public function __construct(
        ClaudeSDKService $claudeService,
        LoggerInterface $logger
    ) {
        $this->claudeService = $claudeService;
        $this->logger = $logger;
    }

    public function getId(): string {
        return 'aiquila:image_to_text';
    }

    public function getName(): string {
        return 'Claude Vision (AIquila)';
    }

    public function getTaskTypeId(): string {
        return ImageToText::ID;
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

    public function process(Task $task): void {
        $input = $task->getInput();

        if (empty($input['image'])) {
            throw new \RuntimeException('No image provided in task input');
        }

        // The image input is a file ID in NC's TaskProcessing framework
        // We receive raw bytes as a string
        $imageData = $input['image'];
        $prompt = !empty($input['prompt']) ? $input['prompt'] : 'Describe this image in detail.';

        // Detect MIME type from image bytes
        $mimeType = $this->detectMimeType($imageData);

        $this->logger->debug('Claude ImageToText: Processing image', [
            'mime_type' => $mimeType,
            'prompt_length' => strlen($prompt),
        ]);

        $result = $this->claudeService->askWithImage(
            $prompt,
            base64_encode($imageData),
            $mimeType
        );

        if (isset($result['error'])) {
            $this->logger->error('Claude ImageToText: Error', ['error' => $result['error']]);
            throw new \RuntimeException($result['error']);
        }

        $task->setOutput(['output' => $result['response'] ?? '']);
    }

    /**
     * Detect MIME type from raw image bytes using magic bytes.
     */
    private function detectMimeType(string $data): string {
        if (strlen($data) < 4) {
            return 'image/jpeg';
        }

        $header = substr($data, 0, 4);

        // JPEG: FF D8 FF
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        // PNG: 89 50 4E 47
        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }
        // GIF: GIF8
        if (str_starts_with($header, 'GIF8')) {
            return 'image/gif';
        }
        // WebP: RIFF....WEBP
        if (str_starts_with($header, 'RIFF') && strlen($data) >= 12 && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/jpeg';
    }
}
