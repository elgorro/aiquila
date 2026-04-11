<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;

/**
 * Claude translate TaskProcessing Provider (core:text2text:translate)
 */
class ClaudeTranslateProvider implements ISynchronousProvider {

    public function __construct(
        private ClaudeSDKService $claudeService,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:translate';
    }

    public function getName(): string {
        return 'Claude (AIquila)';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:translate';
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
        $originLanguage = $input['origin_language'] ?? '';
        $targetLanguage = $input['target_language'] ?? '';

        if (empty($text)) {
            throw new \RuntimeException('No input text provided');
        }
        if (empty($targetLanguage)) {
            throw new \RuntimeException('No target language provided');
        }

        $reportProgress(0.1);

        $fromClause = !empty($originLanguage) ? " from {$originLanguage}" : '';
        $result = $this->claudeService->ask(
            "Translate the following text{$fromClause} to {$targetLanguage}. Return only the translated text, nothing else:\n\n" . $text,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
