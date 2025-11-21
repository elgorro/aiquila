<?php

namespace OCA\NextClaude\Service;

use OCP\IConfig;
use OCP\Http\Client\IClientService;

class ClaudeService {
    private IConfig $config;
    private IClientService $clientService;
    private string $appName = 'nextclaude';

    public function __construct(IConfig $config, IClientService $clientService) {
        $this->config = $config;
        $this->clientService = $clientService;
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
                    'model' => 'claude-sonnet-4-20250514',
                    'max_tokens' => 4096,
                    'messages' => $messages,
                ]),
            ]);

            $data = json_decode($response->getBody(), true);
            return ['response' => $data['content'][0]['text'] ?? ''];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function summarize(string $content, ?string $userId = null): array {
        return $this->ask("Summarize the following content concisely:\n\n$content", '', $userId);
    }
}
