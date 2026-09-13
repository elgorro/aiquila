<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;

/**
 * Reformat paragraphs TaskProcessing Provider (core:text2text:reformatparagraphs)
 *
 * The task type is Nextcloud 34+. The id is spelled out rather than read from
 * OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs::ID because that
 * class does not exist on Nextcloud 33, which the app still declares support
 * for, and a constant on a missing class is a fatal error. On 33 the task type
 * is simply not registered, so this provider is never offered.
 */
class ReformatParagraphsProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:reformatparagraphs';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:reformatparagraphs';
    }

    public function getExpectedRuntime(): int {
        return 30;
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
            "Reformat the following text into multiple paragraphs, each covering one topic. Keep the wording and meaning unchanged — only change where the paragraph breaks fall. Return only the reformatted text, nothing else:\n\n" . $text,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
