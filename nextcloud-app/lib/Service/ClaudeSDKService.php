<?php

namespace OCA\AIquila\Service;

use Anthropic\Client;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Claude AI Service using official Anthropic PHP SDK
 *
 * This is the new implementation using the official SDK.
 * Provides better error handling, type safety, and streaming support.
 */
class ClaudeSDKService {
    private IConfig $config;
    private LoggerInterface $logger;
    private string $appName = 'aiquila';

    private const DEFAULT_MAX_RETRIES = 2;

    public function __construct(IConfig $config, LoggerInterface $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Get Anthropic client instance
     */
    protected function getClient(?string $userId = null): Client {
        $apiKey = $this->getApiKey($userId);
        if (!$apiKey) {
            throw new \RuntimeException('No API key configured');
        }

        return new Client(apiKey: $apiKey);
    }

    /**
     * Get API key (user-specific or admin)
     */
    public function getApiKey(?string $userId = null): string {
        if ($userId) {
            $userKey = $this->config->getUserValue($userId, $this->appName, 'api_key', '');
            if ($userKey) return $userKey;
        }
        return $this->config->getAppValue($this->appName, 'api_key', '');
    }

    /**
     * Get configured model
     */
    public function getModel(): string {
        return $this->config->getAppValue($this->appName, 'model', ClaudeModels::DEFAULT_MODEL);
    }

    /**
     * Get configured max tokens, clamped to the model's output ceiling
     */
    public function getMaxTokens(): int {
        $stored = (int)$this->config->getAppValue($this->appName, 'max_tokens', (string)ClaudeModels::DEFAULT_MAX_TOKENS);
        return min($stored, ClaudeModels::getMaxTokenCeiling($this->getModel()));
    }

    /**
     * Build the base request payload for a messages->create() call.
     * Merges model-specific params (thinking, effort, …) so individual
     * calling methods remain model-agnostic.
     */
    private function buildRequestParams(array $messages): array {
        $model = $this->getModel();
        return array_merge(
            [
                'model'      => $model,
                'max_tokens' => $this->getMaxTokens(),
                'messages'   => $messages,
            ],
            ClaudeModels::getModelParams($model)
        );
    }

    /**
     * Ask Claude a question with optional context
     *
     * @param string $prompt The question/prompt
     * @param string $context Optional context
     * @param string|null $userId User ID for user-specific API key
     * @return array ['response' => string] or ['error' => string]
     */
    public function ask(string $prompt, string $context = '', ?string $userId = null): array {
        try {
            $client = $this->getClient($userId);

            // Build messages array
            $messages = [];
            if ($context) {
                $messages[] = [
                    'role' => 'user',
                    'content' => "Context:\n$context\n\nQuestion: $prompt"
                ];
            } else {
                $messages[] = ['role' => 'user', 'content' => $prompt];
            }

            $this->logger->debug('AIquila SDK: Sending request', [
                'model' => $this->getModel(),
                'max_tokens' => $this->getMaxTokens(),
                'user' => $userId
            ]);

            // Make request using SDK
            $response = $client->messages->create($this->buildRequestParams($messages));

            // Extract text from response
            $responseText = '';
            foreach ($response->content as $content) {
                if ($content->type === 'text') {
                    $responseText .= $content->text;
                }
            }

            $this->logger->info('AIquila SDK: Successful response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => [
                    'input_tokens' => $response->usage->inputTokens ?? 0,
                    'output_tokens' => $response->usage->outputTokens ?? 0,
                ]
            ]);

            return ['response' => $responseText];

        } catch (\Exception $e) {
            $this->logger->error('AIquila SDK: Error occurred', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);

            // Handle specific error types based on message content
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'authentication') || str_contains($errorMessage, 'api_key')) {
                return ['error' => 'Invalid API key. Please check your configuration.'];
            } elseif (str_contains($errorMessage, 'rate limit')) {
                return ['error' => 'Rate limit exceeded. Please try again later.'];
            } elseif (str_contains($errorMessage, 'connection') || str_contains($errorMessage, 'timeout')) {
                return ['error' => 'Connection to Claude API failed: ' . $errorMessage];
            } else {
                return ['error' => 'Error: ' . $errorMessage];
            }
        }
    }

    /**
     * Summarize content using Claude
     *
     * @param string $content Content to summarize
     * @param string|null $userId User ID for user-specific API key
     * @return array ['response' => string] or ['error' => string]
     */
    public function summarize(string $content, ?string $userId = null): array {
        return $this->ask("Summarize the following content concisely:\n\n$content", '', $userId);
    }

    /**
     * Send a message to Claude (wrapper for ask() for testing)
     *
     * @param string $prompt The prompt
     * @param string|null $userId User ID
     * @param string|null $filePath File path (not yet implemented)
     * @return string Response text
     * @throws \Exception If error occurs
     */
    public function sendMessage(string $prompt, ?string $userId = null, ?string $filePath = null): string {
        $context = '';

        // File handling to be implemented
        if ($filePath) {
            $this->logger->debug('AIquila SDK: File path provided but not yet implemented', [
                'file' => $filePath
            ]);
        }

        $result = $this->ask($prompt, $context, $userId);

        if (isset($result['error'])) {
            throw new \Exception($result['error']);
        }

        return $result['response'] ?? 'No response';
    }

    /**
     * Get current configuration for display/testing
     *
     * @return array Configuration array
     */
    public function getConfiguration(): array {
        return [
            'api_key' => $this->config->getAppValue($this->appName, 'api_key', ''),
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'timeout' => (int)$this->config->getAppValue($this->appName, 'api_timeout', '30'),
        ];
    }

    /**
     * Ask Claude about an image using vision capabilities.
     *
     * @param string $prompt What to ask about the image
     * @param string $base64Image Base64-encoded image data
     * @param string $mimeType Image mime type (image/jpeg, image/png, image/gif, image/webp)
     * @param string|null $userId User ID for API key
     * @return array ['response' => string] or ['error' => string]
     */
    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null): array {
        try {
            $client = $this->getClient($userId);

            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $base64Image,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ];
            $response = $client->messages->create($this->buildRequestParams($messages));

            $responseText = '';
            foreach ($response->content as $content) {
                if ($content->type === 'text') {
                    $responseText .= $content->text;
                }
            }

            $this->logger->info('AIquila SDK: Image analysis response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => [
                    'input_tokens' => $response->usage->inputTokens ?? 0,
                    'output_tokens' => $response->usage->outputTokens ?? 0,
                ]
            ]);

            return ['response' => $responseText];

        } catch (\Exception $e) {
            $this->logger->error('AIquila SDK: Image analysis error', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);

            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'authentication') || str_contains($errorMessage, 'api_key')) {
                return ['error' => 'Invalid API key. Please check your configuration.'];
            } elseif (str_contains($errorMessage, 'rate limit')) {
                return ['error' => 'Rate limit exceeded. Please try again later.'];
            } else {
                return ['error' => 'Image analysis error: ' . $errorMessage];
            }
        }
    }

    /**
     * Stream response from Claude (NEW FEATURE)
     *
     * @param string $prompt The question/prompt
     * @param string $context Optional context
     * @param string|null $userId User ID for user-specific API key
     * @return \Generator Yields text chunks as they arrive
     * @throws \Exception If error occurs
     */
    public function askStream(string $prompt, string $context = '', ?string $userId = null): \Generator {
        $client = $this->getClient($userId);

        // Build messages array
        $messages = [];
        if ($context) {
            $messages[] = [
                'role' => 'user',
                'content' => "Context:\n$context\n\nQuestion: $prompt"
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }

        $this->logger->debug('AIquila SDK: Starting stream request', [
            'model' => $this->getModel(),
            'user' => $userId
        ]);

        try {
            $stream = $client->messages->createStreamed($this->buildRequestParams($messages));

            foreach ($stream as $event) {
                if ($event->type === 'content_block_delta' && isset($event->delta->text)) {
                    yield $event->delta->text;
                }
            }

            $this->logger->info('AIquila SDK: Stream completed successfully');

        } catch (\Exception $e) {
            $this->logger->error('AIquila SDK: Stream error', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);
            throw new \Exception('Stream error: ' . $e->getMessage());
        }
    }
}
