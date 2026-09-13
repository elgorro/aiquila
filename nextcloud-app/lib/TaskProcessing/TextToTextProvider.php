<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use Psr\Log\LoggerInterface;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Free-prompt TaskProcessing Provider (core:text2text)
 *
 * General-purpose text generation. Handles arbitrary prompts in the
 * Nextcloud Assistant, on whichever provider the user has selected.
 */
class TextToTextProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return TextToText::ID;
    }

    public function getExpectedRuntime(): int {
        return 30;
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
        $prompt = $input['input'] ?? '';
        if (!is_string($prompt) || $prompt === '') {
            throw new \RuntimeException('No input text provided');
        }

        $this->logger->debug('AIquila Text2Text: Processing', [
            'prompt_length' => strlen($prompt),
        ]);

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId)->ask($prompt, '', $userId);

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
