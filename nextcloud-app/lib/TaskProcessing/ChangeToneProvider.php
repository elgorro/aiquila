<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\ISynchronousProvider;

/**
 * Change-tone TaskProcessing Provider (core:text2text:changetone)
 */
class ChangeToneProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:changetone';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:changetone';
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
        $text = $input['input'] ?? '';
        $tone = $input['tone'] ?? 'formal';

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }
        if (!is_string($tone) || $tone === '') {
            $tone = 'formal';
        }

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId)->ask(
            "Rewrite the following text in a {$tone} tone. Return only the rewritten text, nothing else:\n\n" . $text,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
