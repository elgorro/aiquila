<?php

declare(strict_types=1);

namespace OCA\AIquila\TextProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithExpectedRuntime;
use OCP\TextProcessing\IProviderWithUserId;
use OCP\TextProcessing\SummaryTaskType;
use Psr\Log\LoggerInterface;

/**
 * Claude Summary Text Processing Provider
 * Registers Claude as a summarization provider in Nextcloud's text processing framework.
 */
class ClaudeSummaryProvider implements IProvider, IProviderWithExpectedRuntime, IProviderWithUserId {

    private ClaudeSDKService $claudeService;
    private LoggerInterface $logger;
    private ?string $userId = null;

    public function __construct(
        ClaudeSDKService $claudeService,
        LoggerInterface $logger
    ) {
        $this->claudeService = $claudeService;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'Claude (AIquila)';
    }

    public function process(string $prompt): string {
        $this->logger->debug('Claude SummaryProvider: Processing prompt', [
            'user' => $this->userId,
            'prompt_length' => strlen($prompt),
        ]);

        $result = $this->claudeService->summarize($prompt, $this->userId);

        if (isset($result['error'])) {
            $this->logger->error('Claude SummaryProvider: Error processing prompt', [
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error']);
        }

        return $result['response'] ?? 'No response from Claude';
    }

    public function getTaskType(): string {
        return SummaryTaskType::class;
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function setUserId(?string $userId): void {
        $this->userId = $userId;
    }
}
