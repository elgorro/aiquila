<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use Psr\Log\LoggerInterface;

/**
 * Image-generation TaskProcessing Provider
 *
 * Registers AIquila as a core:text2image provider, which is what the Assistant's
 * "Generate image" action — and the MCP server's generate_image tool, which goes
 * through Nextcloud's own OCS task API — end up calling.
 *
 * Input:  input (prompt) + numberOfImages
 * Output: images (list of raw image bytes; the framework stores them)
 */
class TextToImageProvider implements ISynchronousProvider {
    /**
     * Ceiling on numberOfImages. Generation is billed per image and runs inside
     * one synchronous task, so an unbounded count is both slow and expensive.
     */
    public const MAX_IMAGES = 4;

    public function __construct(
        private ProviderResolver $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2image';
    }

    public function getName(): string {
        return 'AIquila Image';
    }

    public function getTaskTypeId(): string {
        return TextToImage::ID;
    }

    public function getExpectedRuntime(): int {
        return 120;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. mistral)',
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
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $prompt = $input['input'] ?? '';
        if (!is_string($prompt) || trim($prompt) === '') {
            throw new \RuntimeException('No input text provided');
        }

        $requested = $input['numberOfImages'] ?? 1;
        $count = is_numeric($requested) ? (int)$requested : 1;
        $count = max(1, min(self::MAX_IMAGES, $count));

        $provider = $this->providers->resolveImageGenCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $result = $provider->generateImages($prompt, $count, $userId);
        if (isset($result['error'])) {
            $this->logger->error('AIquila TextToImage: Error', ['error' => $result['error'], 'provider' => $provider->getId()]);
            throw new \RuntimeException($result['error']);
        }

        $images = $result['images'] ?? [];
        if ($images === []) {
            throw new \RuntimeException('No images were generated.');
        }

        $reportProgress(1.0);
        return ['images' => $images];
    }
}
