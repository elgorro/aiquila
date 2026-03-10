<?php

declare(strict_types=1);

namespace OCA\AIquila\TextProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TextProcessing\HeadlineTaskType;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithExpectedRuntime;
use OCP\TextProcessing\IProviderWithUserId;
use Psr\Log\LoggerInterface;

/**
 * Claude Headline Text Processing Provider
 * Registers Claude as a headline/title generator in Nextcloud's text processing framework.
 */
class ClaudeHeadlineProvider implements IProvider, IProviderWithExpectedRuntime, IProviderWithUserId {

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
        $this->logger->debug('Claude HeadlineProvider: Processing prompt', [
            'user' => $this->userId,
            'prompt_length' => strlen($prompt),
        ]);

        $headlinePrompt = "Generate a concise, compelling headline or title for the following text. Return only the headline, nothing else:\n\n{$prompt}";
        $result = $this->claudeService->ask($headlinePrompt, '', $this->userId);

        if (isset($result['error'])) {
            $this->logger->error('Claude HeadlineProvider: Error processing prompt', [
                'error' => $result['error'],
            ]);
            throw new \RuntimeException($result['error']);
        }

        return $result['response'] ?? 'No response from Claude';
    }

    public function getTaskType(): string {
        return HeadlineTaskType::class;
    }

    public function getExpectedRuntime(): int {
        return 20;
    }

    public function setUserId(?string $userId): void {
        $this->userId = $userId;
    }
}
