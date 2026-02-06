<?php

declare(strict_types=1);

namespace OCA\AIquila\TextProcessing;

use OCA\AIquila\Service\ClaudeSDKService;
use OCP\TextProcessing\FreePromptTaskType;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithExpectedRuntime;
use OCP\TextProcessing\IProviderWithUserId;
use Psr\Log\LoggerInterface;

/**
 * Claude Text Processing Provider
 * Integrates Claude AI with Nextcloud's native Assistant/Text Processing framework
 */
class ClaudeProvider implements IProvider, IProviderWithExpectedRuntime, IProviderWithUserId {

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
        $this->logger->debug('Claude Provider: Processing prompt', [
            'user' => $this->userId,
            'prompt_length' => strlen($prompt)
        ]);

        $result = $this->claudeService->ask($prompt, '', $this->userId);

        if (isset($result['error'])) {
            $this->logger->error('Claude Provider: Error processing prompt', [
                'error' => $result['error']
            ]);
            throw new \RuntimeException($result['error']);
        }

        return $result['response'] ?? 'No response from Claude';
    }

    public function getTaskType(): string {
        return FreePromptTaskType::class;
    }

    public function getExpectedRuntime(): int {
        // Estimate 30 seconds for API call
        return 30;
    }

    public function setUserId(?string $userId): void {
        $this->userId = $userId;
    }
}
