<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ImageOptimizer;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use Psr\Log\LoggerInterface;

/**
 * Optical character recognition TaskProcessing Provider
 *
 * Registers AIquila against core:image2text:ocr, the task type behind
 * "Extract text from image" in the Nextcloud Assistant and in Files. The type
 * is a batch: one run carries a list of files and returns one text per file,
 * in the same order.
 *
 * Input:  input (list of image files)
 * Output: output (list of extracted texts)
 */
class ImageToTextProvider implements ISynchronousProvider {

    private const DEFAULT_PROMPT = 'Extract all text visible in this image. Return only the extracted text, with no commentary. If the image contains no text, return an empty response.';

    public function __construct(
        private ProviderResolver $providers,
        private ImageOptimizer $imageOptimizer,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:image_to_text';
    }

    public function getName(): string {
        return 'AIquila Vision';
    }

    public function getTaskTypeId(): string {
        return ImageToTextOpticalCharacterRecognition::ID;
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function getOptionalInputShape(): array {
        return [
            'prompt' => new ShapeDescriptor(
                'Prompt',
                'Optional instruction describing what to extract from each image',
                EShapeType::Text
            ),
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. anthropic, mistral)',
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
        return ['prompt' => self::DEFAULT_PROMPT];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        // The framework resolves ListOfFiles slots to File nodes before calling
        // us — the raw bytes never travel through the task input.
        $files = $input['input'] ?? [];
        if (!is_array($files) || $files === []) {
            throw new \RuntimeException('No images provided');
        }

        if (count($files) > ImageOptimizer::MAX_IMAGES) {
            throw new \RuntimeException('Too many images. Maximum is ' . ImageOptimizer::MAX_IMAGES);
        }

        $prompt = $input['prompt'] ?? '';
        if (!is_string($prompt) || $prompt === '') {
            $prompt = self::DEFAULT_PROMPT;
        }

        $provider = $this->providers->resolveVisionCapable($userId, $this->providers->requestedId($input));

        $this->logger->debug('AIquila ImageToText: Extracting text from {count} image(s)', [
            'count' => count($files),
            'provider' => $provider->getId(),
        ]);

        $texts = [];
        $total = count($files);
        foreach (array_values($files) as $i => $file) {
            if (!$file instanceof File) {
                throw new \RuntimeException('Image ' . ($i + 1) . ' is not a file');
            }

            $image = $this->imageOptimizer->prepare($file->getContent(), $file->getMimetype());

            $result = $provider->askWithImage(
                $prompt,
                $image['base64'],
                $image['mimeType'],
                $userId,
                (string)$file->getId(),
            );

            if (isset($result['error'])) {
                $this->logger->error('AIquila ImageToText: Error', [
                    'error' => $result['error'],
                    'provider' => $provider->getId(),
                    'file' => $file->getId(),
                ]);
                throw new \RuntimeException($result['error']);
            }

            $texts[] = (string)($result['response'] ?? '');
            $reportProgress(($i + 1) / $total);
        }

        return ['output' => $texts];
    }
}
