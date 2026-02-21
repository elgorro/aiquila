<?php

namespace OCA\AIquila\Service;

use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Anthropic\Models\ModelInfo;
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
     * Get configured model, checking user preference first
     */
    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, $this->appName, 'model', '');
            if ($userModel) return $userModel;
        }
        return $this->config->getAppValue($this->appName, 'model', ClaudeModels::DEFAULT_MODEL);
    }

    /**
     * Get configured max tokens, clamped to the model's output ceiling
     */
    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue($this->appName, 'max_tokens', (string)ClaudeModels::DEFAULT_MAX_TOKENS);
        return min($stored, ClaudeModels::getMaxTokenCeiling($this->getModel($userId)));
    }

    /**
     * Build the base request payload for a messages->create() call.
     * Merges model-specific params (thinking, effort, …) so individual
     * calling methods remain model-agnostic.
     *
     * Supported $options keys:
     *   system        (string)  – system prompt text
     *   cache_system  (bool)    – apply ephemeral cache_control to system block
     *   temperature   (float)
     *   top_p         (float)
     *   top_k         (int)
     *   stop_sequences (array)
     */
    private function buildRequestParams(
        array   $messages,
        ?string $userId  = null,
        array   $options = []
    ): array {
        $model = $this->getModel($userId);
        $params = array_merge(
            [
                'model'      => $model,
                'max_tokens' => $this->getMaxTokens($userId),
                'messages'   => $messages,
            ],
            ClaudeModels::getModelParams($model)
        );

        // System prompt
        if (!empty($options['system'])) {
            $systemBlock = ['type' => 'text', 'text' => $options['system']];
            if (!empty($options['cache_system'])) {
                $systemBlock['cache_control'] = ['type' => 'ephemeral'];
            }
            $params['system'] = [$systemBlock];
        }

        // Sampling parameters
        foreach (['temperature', 'top_p', 'top_k', 'stop_sequences'] as $key) {
            if (array_key_exists($key, $options)) {
                $params[$key] = $options[$key];
            }
        }

        return $params;
    }

    /**
     * Dispatch a non-streaming create call. Overridable for testing.
     */
    protected function callCreate(Client $client, array $params): Message {
        return $client->messages->create(
            maxTokens: $params['max_tokens'],
            messages: $params['messages'],
            model: $params['model'],
            system: $params['system'] ?? null,
            thinking: $params['thinking'] ?? null,
            temperature: $params['temperature'] ?? null,
            topP: $params['top_p'] ?? null,
            topK: $params['top_k'] ?? null,
            stopSequences: $params['stop_sequences'] ?? null,
        );
    }

    /**
     * Dispatch a streaming create call. Overridable for testing.
     */
    protected function callCreateStream(Client $client, array $params): BaseStream {
        return $client->messages->createStream(
            maxTokens: $params['max_tokens'],
            messages: $params['messages'],
            model: $params['model'],
            system: $params['system'] ?? null,
            thinking: $params['thinking'] ?? null,
            temperature: $params['temperature'] ?? null,
            topP: $params['top_p'] ?? null,
            topK: $params['top_k'] ?? null,
            stopSequences: $params['stop_sequences'] ?? null,
        );
    }

    /**
     * Dispatch models->list(). Returns the raw items array for easy stubbing.
     * @return list<ModelInfo>
     */
    protected function callListModels(Client $client, array $params): array {
        return $client->models->list(limit: $params['limit'] ?? 1000)->getItems();
    }

    /**
     * Dispatch models->retrieve(). Overridable for testing.
     */
    protected function callRetrieveModel(Client $client, string $modelId): ModelInfo {
        return $client->models->retrieve($modelId);
    }

    /**
     * List all available models for this API key.
     * Returns array of model ID strings, or null on error (caller should fall back).
     */
    public function listModels(?string $userId = null): ?array {
        try {
            $client = $this->getClient($userId);
            $items  = $this->callListModels($client, ['limit' => 1000]);
            return array_map(fn(ModelInfo $m) => $m->id, $items);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila SDK: Could not list models', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retrieve info for a single model ID.
     * Returns ['id' => ..., 'display_name' => ...] or null on error.
     */
    public function retrieveModel(string $modelId, ?string $userId = null): ?array {
        try {
            $client = $this->getClient($userId);
            $info   = $this->callRetrieveModel($client, $modelId);
            return ['id' => $info->id, 'display_name' => $info->displayName];
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila SDK: Could not retrieve model', [
                'model' => $modelId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract text from a Message response content array.
     */
    private function extractText(Message $response): string {
        $text = '';
        foreach ($response->content as $content) {
            if ($content->type === 'text') {
                $text .= $content->text;
            }
        }
        return $text;
    }

    /**
     * Shared exception handler for non-streaming calls.
     * Returns ['error' => string].
     */
    private function handleException(\Throwable $e, string $context = ''): array {
        $prefix = $context ? "AIquila SDK: $context" : 'AIquila SDK';
        if ($e instanceof AuthenticationException) {
            $this->logger->error("$prefix: Authentication error", ['error' => $e->getMessage()]);
            return ['error' => 'Invalid API key. Please check your configuration.'];
        }
        if ($e instanceof PermissionDeniedException) {
            $this->logger->error("$prefix: Permission denied", ['error' => $e->getMessage()]);
            return ['error' => 'Your API key does not have permission to use this resource.'];
        }
        if ($e instanceof RateLimitException) {
            $this->logger->error("$prefix: Rate limit exceeded", ['error' => $e->getMessage()]);
            return ['error' => 'Rate limit exceeded. Please try again later.'];
        }
        if ($e instanceof InternalServerException) {
            $this->logger->error("$prefix: Anthropic server error", ['error' => $e->getMessage()]);
            return ['error' => 'Anthropic API is temporarily unavailable. Please try again later.'];
        }
        if ($e instanceof APIConnectionException || $e instanceof APITimeoutException) {
            $this->logger->error("$prefix: Connection error", ['error' => $e->getMessage()]);
            return ['error' => 'Connection to Claude API failed: ' . $e->getMessage()];
        }
        $this->logger->error("$prefix: Error", ['error' => $e->getMessage(), 'class' => get_class($e)]);
        return ['error' => 'Error: ' . $e->getMessage()];
    }

    /**
     * Ask Claude a question with optional context
     *
     * @param string $prompt The question/prompt
     * @param string $context Optional context
     * @param string|null $userId User ID for user-specific API key
     * @param array $options Optional: system, temperature, top_p, top_k, stop_sequences, cache_system
     * @return array ['response' => string] or ['error' => string]
     */
    public function ask(
        string  $prompt,
        string  $context = '',
        ?string $userId  = null,
        array   $options = []
    ): array {
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
                'model' => $this->getModel($userId),
                'max_tokens' => $this->getMaxTokens($userId),
                'user' => $userId
            ]);

            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId, $options));

            $this->logger->info('AIquila SDK: Successful response', [
                'stop_reason' => $response->stop_reason ?? 'unknown',
                'usage' => [
                    'input_tokens' => $response->usage->input_tokens ?? 0,
                    'output_tokens' => $response->usage->output_tokens ?? 0,
                ]
            ]);

            return ['response' => $this->extractText($response)];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'ask');
        }
    }

    /**
     * Multi-turn conversation: accepts a pre-built messages array.
     *
     * @param array $messages Alternating user/assistant messages, e.g.
     *                        [['role'=>'user','content'=>'...'], ...]
     * @param string|null $system Optional system prompt
     * @param string|null $userId User ID for API key resolution
     * @param array $options Optional: temperature, top_p, top_k, stop_sequences, cache_system
     * @return array ['response' => string, 'usage' => [...]] or ['error' => string]
     */
    public function chat(
        array   $messages,
        ?string $system  = null,
        ?string $userId  = null,
        array   $options = []
    ): array {
        try {
            $client = $this->getClient($userId);

            if ($system !== null) {
                $options['system'] = $system;
            }

            $this->logger->debug('AIquila SDK: chat() request', [
                'model'        => $this->getModel($userId),
                'message_count' => count($messages),
                'has_system'   => $system !== null,
                'user'         => $userId,
            ]);

            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId, $options));

            $this->logger->info('AIquila SDK: chat() response', [
                'stop_reason' => $response->stop_reason ?? 'unknown',
                'usage' => [
                    'input_tokens'  => $response->usage->input_tokens ?? 0,
                    'output_tokens' => $response->usage->output_tokens ?? 0,
                ],
            ]);

            return [
                'response' => $this->extractText($response),
                'usage' => [
                    'input_tokens'  => $response->usage->input_tokens ?? 0,
                    'output_tokens' => $response->usage->output_tokens ?? 0,
                ],
            ];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'chat');
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
     * Ask Claude about a document (PDF or plain text).
     *
     * @param string $prompt What to ask about the document
     * @param string $documentData Raw bytes (PDF) or plain text
     * @param string $mediaType 'application/pdf' | 'text/plain'
     * @param string $title Optional document title
     * @param string|null $userId User ID for API key resolution
     * @param bool $cacheDoc Add cache_control to the document block
     * @return array ['response' => string] or ['error' => string]
     */
    public function askWithDocument(
        string  $prompt,
        string  $documentData,
        string  $mediaType,
        string  $title    = '',
        ?string $userId   = null,
        bool    $cacheDoc = false
    ): array {
        try {
            $client = $this->getClient($userId);

            if ($mediaType === 'application/pdf') {
                $source = [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => base64_encode($documentData),
                ];
            } else {
                // text/plain and other text types
                $source = [
                    'type'       => 'text',
                    'media_type' => 'text/plain',
                    'data'       => $documentData,
                ];
            }

            if ($cacheDoc) {
                $source['cache_control'] = ['type' => 'ephemeral'];
            }

            $docBlock = ['type' => 'document', 'source' => $source];
            if ($title !== '') {
                $docBlock['title'] = $title;
            }

            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        $docBlock,
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ],
            ];

            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId));

            $this->logger->info('AIquila SDK: Document analysis response', [
                'stop_reason' => $response->stop_reason ?? 'unknown',
                'media_type'  => $mediaType,
                'usage' => [
                    'input_tokens'  => $response->usage->input_tokens ?? 0,
                    'output_tokens' => $response->usage->output_tokens ?? 0,
                ],
            ]);

            return ['response' => $this->extractText($response)];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'askWithDocument');
        }
    }

    /**
     * Send a message to Claude, routing to the correct method based on file type.
     *
     * @param string $prompt The prompt
     * @param string|null $userId User ID
     * @param string|null $filePath Optional file path to include as context
     * @return string Response text
     * @throws \Exception If error occurs
     */
    public function sendMessage(string $prompt, ?string $userId = null, ?string $filePath = null): string {
        if ($filePath && file_exists($filePath)) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType === false) {
                $mimeType = 'application/octet-stream';
            }

            if (str_starts_with($mimeType, 'image/')) {
                $imageData = file_get_contents($filePath);
                if ($imageData === false) {
                    throw new \Exception('Could not read image file: ' . $filePath);
                }
                $result = $this->askWithImage($prompt, base64_encode($imageData), $mimeType, $userId);

            } elseif ($mimeType === 'application/pdf') {
                $pdfData = file_get_contents($filePath);
                if ($pdfData === false) {
                    throw new \Exception('Could not read PDF file: ' . $filePath);
                }
                $result = $this->askWithDocument($prompt, $pdfData, 'application/pdf', basename($filePath), $userId);

            } elseif (str_starts_with($mimeType, 'text/')) {
                $textData = file_get_contents($filePath);
                if ($textData === false) {
                    throw new \Exception('Could not read text file: ' . $filePath);
                }
                $result = $this->askWithDocument($prompt, $textData, 'text/plain', basename($filePath), $userId);

            } else {
                $this->logger->debug('AIquila SDK: Unsupported file type, falling back to plain ask', [
                    'file'      => $filePath,
                    'mime_type' => $mimeType,
                ]);
                $result = $this->ask($prompt, '', $userId);
            }
        } else {
            if ($filePath) {
                $this->logger->warning('AIquila SDK: File not found, falling back to plain ask', [
                    'file' => $filePath,
                ]);
            }
            $result = $this->ask($prompt, '', $userId);
        }

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
            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId));

            $this->logger->info('AIquila SDK: Image analysis response', [
                'stop_reason' => $response->stop_reason ?? 'unknown',
                'usage' => [
                    'input_tokens' => $response->usage->input_tokens ?? 0,
                    'output_tokens' => $response->usage->output_tokens ?? 0,
                ]
            ]);

            return ['response' => $this->extractText($response)];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Image analysis');
        }
    }

    /**
     * Stream response from Claude
     *
     * @param string $prompt The question/prompt
     * @param string $context Optional context
     * @param string|null $userId User ID for user-specific API key
     * @param array $options Optional: system, temperature, top_p, top_k, stop_sequences, cache_system
     * @return \Generator Yields text chunks as they arrive
     * @throws \Exception If error occurs
     */
    public function askStream(
        string  $prompt,
        string  $context = '',
        ?string $userId  = null,
        array   $options = []
    ): \Generator {
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
            'model' => $this->getModel($userId),
            'user' => $userId
        ]);

        try {
            $stream = $this->callCreateStream($client, $this->buildRequestParams($messages, $userId, $options));

            foreach ($stream as $event) {
                if ($event->type === 'content_block_delta' && isset($event->delta->text)) {
                    yield $event->delta->text;
                }
            }

            $this->logger->info('AIquila SDK: Stream completed successfully');

        } catch (AuthenticationException $e) {
            $this->logger->error('AIquila SDK: Stream authentication error', ['error' => $e->getMessage()]);
            throw new \Exception('Invalid API key. Please check your configuration.');
        } catch (PermissionDeniedException $e) {
            $this->logger->error('AIquila SDK: Stream permission denied', ['error' => $e->getMessage()]);
            throw new \Exception('Your API key does not have permission to use this resource.');
        } catch (RateLimitException $e) {
            $this->logger->error('AIquila SDK: Stream rate limit exceeded', ['error' => $e->getMessage()]);
            throw new \Exception('Rate limit exceeded. Please try again later.');
        } catch (InternalServerException $e) {
            $this->logger->error('AIquila SDK: Stream Anthropic server error', ['error' => $e->getMessage()]);
            throw new \Exception('Anthropic API is temporarily unavailable. Please try again later.');
        } catch (APIConnectionException | APITimeoutException $e) {
            $this->logger->error('AIquila SDK: Stream connection error', ['error' => $e->getMessage()]);
            throw new \Exception('Connection to Claude API failed: ' . $e->getMessage());
        } catch (APIStatusException | \Exception $e) {
            $this->logger->error('AIquila SDK: Stream error', ['error' => $e->getMessage(), 'class' => get_class($e)]);
            throw new \Exception('Stream error: ' . $e->getMessage());
        }
    }
}
