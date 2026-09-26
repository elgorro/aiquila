<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextChangeTone;

/**
 * Change-tone TaskProcessing Provider (core:text2text:changetone)
 *
 * `tone` is an Enum slot; its values are adjectives that read naturally in
 * the prompt ("in a friendly tone").
 */
class ChangeToneProvider implements ISynchronousProvider {

    public const DEFAULT_TONE = 'formal';

    public function __construct(
        private ProviderResolver $providers,
        private IL10N $l,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:changetone';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return TextToTextChangeTone::ID;
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
        return [
            'tone' => [
                new ShapeEnumValue($this->l->t('Formal'), 'formal'),
                new ShapeEnumValue($this->l->t('Friendly'), 'friendly'),
                new ShapeEnumValue($this->l->t('Casual'), 'casual'),
                new ShapeEnumValue($this->l->t('Polite'), 'polite'),
                new ShapeEnumValue($this->l->t('Humorous'), 'humorous'),
                new ShapeEnumValue($this->l->t('Confident'), 'confident'),
                new ShapeEnumValue($this->l->t('Urgent'), 'urgent'),
            ],
        ];
    }

    public function getInputShapeDefaults(): array {
        return ['tone' => self::DEFAULT_TONE];
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
        $tone = $input['tone'] ?? self::DEFAULT_TONE;

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }
        if (!is_string($tone) || $tone === '') {
            $tone = self::DEFAULT_TONE;
        }

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId)->ask(
            "Rewrite the following text in a {$tone} tone. Return only the rewritten text, nothing else:\n\n" . $text,
            '',
            $userId,
            LLMProviderInterface::TASK_OPTIONS,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
