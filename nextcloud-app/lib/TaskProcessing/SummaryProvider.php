<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TaskProcessing\ISynchronousProvider;

/**
 * Summary TaskProcessing Provider (core:text2text:summary)
 */
class SummaryProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:summary';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:summary';
    }

    public function getExpectedRuntime(): int {
        // Batch round-trip: typically a few seconds, can stretch to a few minutes
        // under load. The framework uses this to size its job-runner timeout.
        return 120;
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
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('No input text provided');
        }

        $provider = $this->providers->resolve($userId);

        if ($provider instanceof ClaudeSDKService) {
            // Anthropic's Batch API halves the cost and this runs in a background
            // job, so the wait is affordable. No other provider has an equivalent,
            // hence the branch rather than a method on LLMProviderInterface.
            $result = $provider->summarizeViaBatch($text, $userId, $reportProgress);
        } else {
            $reportProgress(0.1);
            $result = $provider->ask("Summarize the following content concisely:\n\n" . $text, '', $userId);
        }

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
