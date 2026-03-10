<?php

declare(strict_types=1);

namespace OCA\AIquila\TextProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithExpectedRuntime;
use OCP\TextProcessing\IProviderWithUserId;
use OCP\TextProcessing\TopicsTaskType;
use Psr\Log\LoggerInterface;

/**
 * Claude Topics Text Processing Provider
 * Registers Claude as a topic/keyword extractor in Nextcloud's text processing framework.
 */
class ClaudeTopicsProvider implements IProvider, IProviderWithExpectedRuntime, IProviderWithUserId {

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
        $this->logger->debug('Claude TopicsProvider: Processing prompt', [
            'user' => $this->userId,
            'prompt_length' => strlen($prompt),
        ]);

        $topicsPrompt = "Extract the main topics and keywords from the following text. Return them as a comma-separated list, ordered by relevance. Return only the list, nothing else:\n\n{$prompt}";
        $result = $this->claudeService->ask($topicsPrompt, '', $this->userId);

        if (isset($result['error'])) {
            $this->logger->error('Claude TopicsProvider: Error processing prompt', [
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error']);
        }

        return $result['response'] ?? 'No response from Claude';
    }

    public function getTaskType(): string {
        return TopicsTaskType::class;
    }

    public function getExpectedRuntime(): int {
        return 25;
    }

    public function setUserId(?string $userId): void {
        $this->userId = $userId;
    }
}
