<?php

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;

/**
 * Claude formalization TaskProcessing Provider (core:text2text:formalization)
 */
class ClaudeFormalizationProvider implements ISynchronousProvider {

    public function __construct(
        private ClaudeSDKService $claudeService,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:formalization';
    }

    public function getName(): string {
        return 'Claude (AIquila)';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:formalization';
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
        if (empty($text)) {
            throw new \RuntimeException('No input text provided');
        }

        $reportProgress(0.1);

        $result = $this->claudeService->ask(
            "Rewrite the following text in a formal, professional tone. Return only the formalized text, nothing else:\n\n" . $text,
            '',
            $userId,
        );

        if (isset($result['error'])) {
            throw new \RuntimeException($result['error']);
        }

        return ['output' => $result['response'] ?? ''];
    }
}
