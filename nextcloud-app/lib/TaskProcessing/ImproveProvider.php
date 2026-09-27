<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;

/**
 * Improve text TaskProcessing Provider (core:text2text:improve)
 *
 * Backs the Assistant's "Improve" button: rewrite `input` following the
 * free-form `instructions`. When the instructions are empty the text is
 * improved generally.
 *
 * The task type is Nextcloud 35+. The id is spelled out rather than read from
 * OCP\TaskProcessing\TaskTypes\TextToTextImprove::ID because that class does
 * not exist on Nextcloud 33 and 34, which the app still declares support for,
 * and a constant on a missing class is a fatal error. There the task type is
 * simply not registered, so this provider is never offered.
 */
class ImproveProvider implements ISynchronousProvider {

    public const DEFAULT_INSTRUCTIONS = 'Improve clarity, flow and correctness while keeping the meaning and the language unchanged.';

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:improve';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:improve';
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
        $instructions = $input['instructions'] ?? '';

        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }
        if (!is_string($instructions) || trim($instructions) === '') {
            $instructions = self::DEFAULT_INSTRUCTIONS;
        }

        $reportProgress(0.1);

        $result = $this->providers->resolve($userId, $this->providers->requestedId($input))->ask(
            "Improve the following text according to these instructions:\n\n" . trim($instructions)
                . "\n\nReturn only the improved text, nothing else.\n\nText:\n\n" . $text,
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
