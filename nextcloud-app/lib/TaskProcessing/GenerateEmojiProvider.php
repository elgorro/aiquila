<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\GenerateEmoji;

/**
 * Emoji generation TaskProcessing Provider (core:generateemoji)
 *
 * Used by Nextcloud to suggest an emoji for a text — a Talk conversation
 * avatar, a Deck board icon and so on.
 */
class GenerateEmojiProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:generateemoji';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return GenerateEmoji::ID;
    }

    public function getExpectedRuntime(): int {
        return 10;
    }

    public function getOptionalInputShape(): array {
        return [
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
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $text = $input['input'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId, $this->providers->requestedId($input))->ask(
            "Pick the single emoji that best represents the following text. Answer with that one emoji character and nothing else — no words, no punctuation, no explanation:\n\n" . $text,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => trim((string)($result['response'] ?? ''))];
    }
}
