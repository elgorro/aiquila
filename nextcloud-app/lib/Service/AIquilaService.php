<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Service;

use OCA\AIquila\Public\IAIquila;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * AIquila Internal Service API
 *
 * This service provides internal access to Claude AI functionality
 * for use by other Nextcloud apps, background jobs, and workflows.
 */
class AIquilaService implements IAIquila {
    private ClaudeSDKService $claudeSDKService;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private IConfig $config;
    private INotificationManager $notificationManager;
    private LoggerInterface $logger;
    private string $appName = 'aiquila';

    public function __construct(
        ClaudeSDKService $claudeSDKService,
        FileService $fileService,
        ImageOptimizer $imageOptimizer,
        IConfig $config,
        INotificationManager $notificationManager,
        LoggerInterface $logger
    ) {
        $this->claudeSDKService = $claudeSDKService;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->config = $config;
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
    }

    /**
     * Ask Claude a question with optional context
     *
     * @param string $prompt The question to ask
     * @param string $context Optional context (file content, etc.)
     * @param string|null $userId User ID (for user-specific API key)
     * @return array ['response' => string] or ['error' => string]
     */
    public function ask(string $prompt, string $context = '', ?string $userId = null): array {
        $this->logger->info('AIquila: Ask request', [
            'user' => $userId,
            'prompt_length' => strlen($prompt),
            'context_length' => strlen($context),
        ]);

        try {
            $result = $this->claudeSDKService->ask($prompt, $context, $userId);

            if (isset($result['error'])) {
                $this->logger->error('AIquila: Claude API error', ['error' => $result['error']]);
            } else {
                $this->logger->info('AIquila: Successful response');
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('AIquila: Exception in ask()', ['exception' => $e->getMessage()]);
            return ['error' => 'Internal error: ' . $e->getMessage()];
        }
    }

    /**
     * Summarize content
     *
     * @param string $content Content to summarize
     * @param string|null $userId User ID (for user-specific API key)
     * @return array ['response' => string] or ['error' => string]
     */
    public function summarize(string $content, ?string $userId = null): array {
        $this->logger->info('AIquila: Summarize request', [
            'user' => $userId,
            'content_length' => strlen($content),
        ]);

        try {
            return $this->claudeSDKService->summarize($content, $userId);
        } catch (\Exception $e) {
            $this->logger->error('AIquila: Exception in summarize()', ['exception' => $e->getMessage()]);
            return ['error' => 'Internal error: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze a file with Claude
     *
     * @param string $filePath Nextcloud file path (e.g., '/Documents/file.txt')
     * @param string $prompt What to ask about the file
     * @param string|null $userId User ID who owns the file
     * @return array ['response' => string] or ['error' => string]
     */
    public function analyzeFile(string $filePath, string $prompt, ?string $userId = null): array {
        $this->logger->info('AIquila: File analysis request', [
            'user' => $userId,
            'file' => $filePath,
        ]);

        try {
            $fileData = $this->fileService->getContent($filePath, $userId);
            $mimeType = $fileData['mimeType'];

            if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                $optimized = $this->imageOptimizer->optimize(
                    base64_decode($fileData['content']),
                    $mimeType
                );
                return $this->claudeSDKService->askWithImage(
                    $prompt,
                    $optimized['data'],
                    $optimized['mimeType'],
                    $userId
                );
            }

            // Text and other files: pass content as context
            $context = "File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes)\n\n"
                     . $fileData['content'];
            return $this->claudeSDKService->ask($prompt, $context, $userId);

        } catch (NotFoundException $e) {
            return ['error' => 'File not found: ' . $filePath];
        } catch (\Exception $e) {
            $this->logger->error('AIquila: Exception in analyzeFile()', ['exception' => $e->getMessage()]);
            return ['error' => 'File analysis error: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze multiple files with Claude (multi-image vision, document, text)
     *
     * @param string[] $filePaths Array of Nextcloud file paths
     * @param string $prompt What to ask about the files
     * @param string|null $userId User ID who owns the files
     * @return array ['response' => string] or ['error' => string]
     */
    public function analyzeFiles(array $filePaths, string $prompt, ?string $userId = null): array {
        $this->logger->info('AIquila: Multi-file analysis request', [
            'user' => $userId,
            'file_count' => count($filePaths),
        ]);

        try {
            $images = [];
            $textParts = [];

            foreach ($filePaths as $filePath) {
                $fileData = $this->fileService->getContent($filePath, $userId);
                $mimeType = $fileData['mimeType'];

                if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                    $optimized = $this->imageOptimizer->optimize(
                        base64_decode($fileData['content']),
                        $mimeType
                    );
                    $images[] = ['base64' => $optimized['data'], 'mimeType' => $optimized['mimeType']];
                } else {
                    $textParts[] = "--- File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes) ---\n{$fileData['content']}";
                }
            }

            if (count($images) > ImageOptimizer::MAX_IMAGES) {
                return ['error' => 'Too many images. Maximum ' . ImageOptimizer::MAX_IMAGES . ' per request.'];
            }

            // Images only
            if (!empty($images) && empty($textParts)) {
                if (count($images) === 1) {
                    return $this->claudeSDKService->askWithImage($prompt, $images[0]['base64'], $images[0]['mimeType'], $userId);
                }
                return $this->claudeSDKService->askWithImages($prompt, $images, $userId);
            }

            // Text only or mixed — use text context
            $context = implode("\n\n", $textParts);
            if (!empty($images)) {
                // Mixed: use askWithImages and prepend text context to prompt
                $promptWithContext = $context . "\n\n" . $prompt;
                return $this->claudeSDKService->askWithImages($promptWithContext, $images, $userId);
            }

            return $this->claudeSDKService->ask($prompt, $context, $userId);

        } catch (NotFoundException $e) {
            return ['error' => 'File not found: ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('AIquila: Exception in analyzeFiles()', ['exception' => $e->getMessage()]);
            return ['error' => 'File analysis error: ' . $e->getMessage()];
        }
    }

    /**
     * Check if AIquila is configured and ready to use
     *
     * @param string|null $userId Optional user ID to check for user-specific key
     * @return bool True if API key is configured
     */
    public function isConfigured(?string $userId = null): bool {
        $apiKey = $this->claudeSDKService->getApiKey($userId);
        return !empty($apiKey);
    }

    /**
     * Get current configuration status
     *
     * @return array Configuration details
     */
    public function getStatus(): array {
        $config = $this->claudeSDKService->getConfiguration();

        return [
            'configured' => !empty($config['api_key']),
            'model' => $config['model'],
            'max_tokens' => $config['max_tokens'],
            'timeout' => $config['timeout'],
        ];
    }

    /**
     * Send a notification to a user
     *
     * @param string $userId User to notify
     * @param string $subject Notification subject
     * @param string $message Notification message
     */
    private function notify(string $userId, string $subject, string $message): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp($this->appName)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('aiquila', 'response')
            ->setSubject($subject, [$message]);

        $this->notificationManager->notify($notification);
    }

    /**
     * Process a Claude request asynchronously (useful for long-running operations)
     *
     * @param string $prompt The prompt
     * @param string $context Optional context
     * @param string $userId User ID
     * @param bool $notify Whether to send notification on completion
     * @return array ['status' => 'queued', 'message' => string]
     */
    public function askAsync(string $prompt, string $context, string $userId, bool $notify = true): array {
        $this->logger->info('AIquila: Async request queued', ['user' => $userId]);

        // TODO: Implement background job for async processing
        // For now, just run synchronously
        $result = $this->ask($prompt, $context, $userId);

        if ($notify && isset($result['response'])) {
            $this->notify($userId, 'Claude AI Response', substr($result['response'], 0, 100));
        }

        return $result;
    }
}
