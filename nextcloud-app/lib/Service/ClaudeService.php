<?php

namespace OCA\AIquila\Service;

use OCP\IConfig;
use OCP\Http\Client\IClientService;

class ClaudeService {
    private IConfig $config;
    private IClientService $clientService;
    private string $appName = 'aiquila';

    // Default configuration values
    private const DEFAULT_API_TIMEOUT = 30; // seconds
    private const DEFAULT_API_MODEL = 'claude-sonnet-4-5-20250929';
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(IConfig $config, IClientService $clientService) {
        $this->config = $config;
        $this->clientService = $clientService;
    }

    /**
     * Get configured API timeout
     */
    private function getApiTimeout(): int {
        return (int)$this->config->getAppValue($this->appName, 'api_timeout', (string)self::DEFAULT_API_TIMEOUT);
    }

    /**
     * Get configured model
     */
    private function getModel(): string {
        return $this->config->getAppValue($this->appName, 'model', self::DEFAULT_API_MODEL);
    }

    /**
     * Get configured max tokens
     */
    private function getMaxTokens(): int {
        return (int)$this->config->getAppValue($this->appName, 'max_tokens', (string)self::DEFAULT_MAX_TOKENS);
    }

    public function getApiKey(?string $userId = null): string {
        // First check user-level key, then admin key
        if ($userId) {
            $userKey = $this->config->getUserValue($userId, $this->appName, 'api_key', '');
            if ($userKey) return $userKey;
        }
        return $this->config->getAppValue($this->appName, 'api_key', '');
    }

    public function ask(string $prompt, string $context = '', ?string $userId = null): array {
        $apiKey = $this->getApiKey($userId);
        if (!$apiKey) {
            return ['error' => 'No API key configured'];
        }

        $client = $this->clientService->newClient();
        $messages = [];

        if ($context) {
            $messages[] = ['role' => 'user', 'content' => "Context:\n$context\n\nQuestion: $prompt"];
        } else {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }

        try {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'body' => json_encode([
                    'model' => $this->getModel(),
                    'max_tokens' => $this->getMaxTokens(),
                    'messages' => $messages,
                ]),
                'timeout' => $this->getApiTimeout(),
            ]);

            $data = json_decode($response->getBody(), true);
            return ['response' => $data['content'][0]['text'] ?? ''];
        } catch (\Exception $e) {
            // Try to extract full error response from HTTP exceptions
            $errorMessage = $e->getMessage();

            // Check if the exception has a getResponse method (Guzzle exceptions)
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                try {
                    $responseBody = (string)$e->getResponse()->getBody();
                    $errorData = json_decode($responseBody, true);
                    if (isset($errorData['error']['message'])) {
                        return ['error' => $errorData['error']['message']];
                    }
                    if ($responseBody) {
                        return ['error' => $responseBody];
                    }
                } catch (\Exception $parseException) {
                    // If we can't parse, fall through to original message
                }
            }

            return ['error' => $errorMessage];
        }
    }

    public function summarize(string $content, ?string $userId = null): array {
        return $this->ask("Summarize the following content concisely:\n\n$content", '', $userId);
    }

    /**
     * Get current configuration for display/testing
     */
    public function getConfiguration(): array {
        return [
            'api_key' => $this->config->getAppValue($this->appName, 'api_key', ''),
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'timeout' => $this->getApiTimeout(),
        ];
    }

    /**
     * Send a message to Claude (wrapper for ask() for testing)
     */
    public function sendMessage(string $prompt, ?string $userId = null, ?string $filePath = null): string {
        $context = '';

        // If file path provided, try to read it
        if ($filePath) {
            // This would need file access implementation
            // For now, just use the prompt
        }

        $result = $this->ask($prompt, $context, $userId);

        if (isset($result['error'])) {
            throw new \Exception($result['error']);
        }

        return $result['response'] ?? 'No response';
    }
}
