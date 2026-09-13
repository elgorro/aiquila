<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ContextWrite;

/**
 * Context write TaskProcessing Provider (core:contextwrite)
 *
 * Powers the Assistant's "Context write" action: write about one thing in the
 * voice of another. `style_input` is the sample whose tone and style to copy,
 * `source_input` is what the new text should be about.
 */
class ContextWriteProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:contextwrite';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return ContextWrite::ID;
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
        $style = $input['style_input'] ?? '';
        $source = $input['source_input'] ?? '';
        if (!is_string($style) || $style === '') {
            throw new \RuntimeException('No writing style provided');
        }
        if (!is_string($source) || $source === '') {
            throw new \RuntimeException('No source material provided');
        }

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId, $this->providers->requestedId($input))->ask(
            "Write a text about the subject below, imitating the tone, voice and style of the sample. Return only the text you wrote, nothing else.\n\n"
                . "Style sample:\n" . $style . "\n\nSubject:\n" . $source,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
