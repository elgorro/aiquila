<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Service;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Files\FileMetadata;
use Anthropic\Client;
use Anthropic\Core\Contracts\BaseStream;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\FileParam;
use Anthropic\Messages\Batches\MessageBatch;
use Anthropic\Messages\Batches\MessageBatchCanceledResult;
use Anthropic\Messages\Batches\MessageBatchErroredResult;
use Anthropic\Messages\Batches\MessageBatchExpiredResult;
use Anthropic\Messages\Batches\MessageBatchIndividualResponse;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Messages\Message;
use Anthropic\Models\ModelInfo;
use OCP\ICache;
use OCP\ICacheFactory;
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
    private CredentialService $credentials;
    private ICache $cache;
    private string $appName = 'aiquila';

    private const CAPABILITY_CACHE_TTL = 3600; // 1 hour

    public function __construct(IConfig $config, LoggerInterface $logger, CredentialService $credentials, ICacheFactory $cacheFactory) {
        $this->config = $config;
        $this->logger = $logger;
        $this->credentials = $credentials;
        $this->cache = $cacheFactory->createDistributed('aiquila_model_caps');
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
        return $this->credentials->getApiKey($userId);
    }

    /**
     * Get configured model, checking user preference first
     */
    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, $this->appName, 'user_model', '');
            if ($userModel) return ClaudeModels::resolveModel($userModel);
        }
        return ClaudeModels::resolveModel(
            $this->config->getAppValue($this->appName, 'model', ClaudeModels::DEFAULT_MODEL)
        );
    }

    /**
     * Get configured max tokens, clamped to the model's output ceiling
     */
    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue($this->appName, 'max_tokens', (string)ClaudeModels::DEFAULT_MAX_TOKENS);
        $caps = $this->resolveModelCapabilities($this->getModel($userId), $userId);
        return min($stored, $caps['max_tokens']);
    }

    /**
     * Resolve model capabilities dynamically via the SDK, with caching and static fallback.
     *
     * @return array{max_tokens: int, supports_thinking: bool, supports_effort: bool}
     */
    private function resolveModelCapabilities(string $model, ?string $userId = null): array {
        $cacheKey = $model;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $client = $this->getClient($userId);
            $info = $this->callRetrieveModel($client, $model);

            $caps = [
                'max_tokens' => $info->maxTokens ?? ClaudeModels::getMaxTokenCeiling($model),
                'supports_thinking' => $info->capabilities->thinking->supported ?? false,
                'supports_effort' => $info->capabilities->effort->supported ?? false,
            ];

            $this->cache->set($cacheKey, $caps, self::CAPABILITY_CACHE_TTL);

            $this->logger->debug('AIquila SDK: Resolved model capabilities dynamically', [
                'model' => $model,
                'caps' => $caps,
            ]);

            return $caps;
        } catch (\Throwable $e) {
            $this->logger->debug('AIquila SDK: Falling back to static capabilities', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return [
                'max_tokens' => ClaudeModels::getMaxTokenCeiling($model),
                'supports_thinking' => ClaudeModels::supportsThinking($model),
                'supports_effort' => ClaudeModels::supportsEffort($model),
            ];
        }
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
     *   tools          (array)  – Anthropic-format tool definitions
     */
    private function buildRequestParams(
        array   $messages,
        ?string $userId  = null,
        array   $options = []
    ): array {
        $model = $this->getModel($userId);
        $caps = $this->resolveModelCapabilities($model, $userId);

        $params = [
            'model'      => $model,
            'max_tokens' => $this->getMaxTokens($userId),
            'messages'   => $messages,
        ];

        if ($caps['supports_thinking']) {
            $params['thinking'] = ['type' => 'adaptive'];
        }

        if ($caps['supports_effort']) {
            $params['outputConfig'] = ['effort' => ClaudeModels::getEffortLevel($model)];
        }

        // System prompt — cache by default; caller can opt out with cache_system: false.
        // The API silently skips caching for prompts under the model minimum, so it is safe
        // to always mark; large reusable system prompts are the common case.
        if (!empty($options['system'])) {
            $systemBlock = ['type' => 'text', 'text' => $options['system']];
            $cacheSystem = $options['cache_system'] ?? true;
            if ($cacheSystem) {
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

        // Tools — mark the last tool with cache_control so the entire tool block
        // (typically large and reused across turns) becomes a cache breakpoint.
        // Caller can opt out with cache_tools: false.
        if (!empty($options['tools'])) {
            $tools = $options['tools'];
            $cacheTools = $options['cache_tools'] ?? true;
            if ($cacheTools && is_array($tools) && $tools !== []) {
                $lastIdx = array_key_last($tools);
                $tools[$lastIdx]['cache_control'] = ['type' => 'ephemeral'];
            }
            $params['tools'] = $tools;
        }

        return $params;
    }

    /**
     * Upload bytes to Anthropic's beta Files API and return the resulting
     * FileMetadata. Overridable for testing.
     */
    protected function callFilesUpload(Client $client, FileParam $file): FileMetadata {
        return $client->beta->files->upload(
            file: $file,
            betas: [AnthropicBeta::FILES_API_2025_04_14],
        );
    }

    /**
     * Upload a file to Anthropic's Files API. The returned id can be passed
     * as `source: { type: 'file', file_id: ... }` on document/image content
     * blocks instead of inlining base64.
     *
     * @return string Anthropic file_id
     */
    public function uploadFile(string $bytes, string $filename, string $mimeType, ?string $userId = null): string {
        $client = $this->getClient($userId);
        $param = FileParam::fromString($bytes, $filename, $mimeType);
        $meta = $this->callFilesUpload($client, $param);
        return $meta->id;
    }

    /**
     * Build per-request options (extra headers etc.). Returns null when none
     * are needed. Today this only enables the Files API beta header when the
     * messages reference an uploaded file_id.
     */
    protected function requestOptionsForMessages(array $params): ?array {
        if ($this->messagesReferenceFileId($params['messages'] ?? [])) {
            return [
                'extraHeaders' => [
                    'anthropic-beta' => AnthropicBeta::FILES_API_2025_04_14->value,
                ],
            ];
        }
        return null;
    }

    /**
     * Walk message content blocks to detect any `source.type === 'file'`
     * reference, which signals the Files API beta is in use.
     */
    private function messagesReferenceFileId(array $messages): bool {
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $source = $block['source'] ?? null;
                if (is_array($source) && ($source['type'] ?? null) === 'file') {
                    return true;
                }
            }
        }
        return false;
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
            outputConfig: $params['outputConfig'] ?? null,
            temperature: $params['temperature'] ?? null,
            topP: $params['top_p'] ?? null,
            topK: $params['top_k'] ?? null,
            stopSequences: $params['stop_sequences'] ?? null,
            tools: $params['tools'] ?? null,
            requestOptions: $this->requestOptionsForMessages($params),
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
            outputConfig: $params['outputConfig'] ?? null,
            temperature: $params['temperature'] ?? null,
            topP: $params['top_p'] ?? null,
            topK: $params['top_k'] ?? null,
            stopSequences: $params['stop_sequences'] ?? null,
            tools: $params['tools'] ?? null,
            requestOptions: $this->requestOptionsForMessages($params),
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

            // Warm capability cache for each model
            foreach ($items as $m) {
                $this->cacheModelInfoCapabilities($m);
            }

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

            $this->cacheModelInfoCapabilities($info);

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
     * Extract and cache capabilities from a ModelInfo object.
     */
    private function cacheModelInfoCapabilities(ModelInfo $info): void {
        $caps = [
            'max_tokens' => $info->maxTokens ?? ClaudeModels::getMaxTokenCeiling($info->id),
            'supports_thinking' => $info->capabilities->thinking->supported ?? false,
            'supports_effort' => $info->capabilities->effort->supported ?? false,
        ];
        $this->cache->set($info->id, $caps, self::CAPABILITY_CACHE_TTL);
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
     * Extract citations from text blocks in a Message response.
     * Returns a flat list of citation entries in API-native shape; an empty
     * list when no document had citations enabled or no citations were emitted.
     *
     * @return list<array<string, mixed>>
     */
    private function extractCitations(Message $response): array {
        $out = [];
        foreach ($response->content as $block) {
            if ($block->type !== 'text') {
                continue;
            }
            $citations = $block->citations ?? null;
            if (!is_array($citations) || $citations === []) {
                continue;
            }
            foreach ($citations as $c) {
                // SDK exposes citation entries as objects; normalise to array.
                $out[] = is_object($c) ? json_decode(json_encode($c), true) : $c;
            }
        }
        return $out;
    }

    /**
     * Extract usage data from a Message response, including cache tokens.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_creation_tokens: int|null, cache_read_tokens: int|null}
     */
    private function extractUsage(Message $response): array {
        return [
            'input_tokens' => $response->usage->inputTokens ?? 0,
            'output_tokens' => $response->usage->outputTokens ?? 0,
            'cache_creation_tokens' => $response->usage->cacheCreationInputTokens,
            'cache_read_tokens' => $response->usage->cacheReadInputTokens,
        ];
    }

    /**
     * Log informational response metadata (inference geo, service tier, refusal details)
     * at debug level.
     */
    private function logResponseMetadata(Message $response): void {
        $meta = [];
        if (isset($response->usage->inferenceGeo) && $response->usage->inferenceGeo !== null) {
            $meta['inference_geo'] = $response->usage->inferenceGeo;
        }
        if (isset($response->usage->serviceTier) && $response->usage->serviceTier !== null) {
            $meta['service_tier'] = $response->usage->serviceTier;
        }
        if (isset($response->stopDetails) && $response->stopDetails !== null) {
            $meta['stop_details'] = [
                'type' => $response->stopDetails->type,
                'category' => $response->stopDetails->category ?? null,
                'explanation' => $response->stopDetails->explanation ?? null,
            ];
        }
        if (!empty($meta)) {
            $this->logger->debug('AIquila SDK: Response metadata', $meta);
        }
    }

    /**
     * Shared exception handler for non-streaming calls.
     * Returns ['error' => string].
     */
    private function handleException(\Throwable $e, string $context = ''): array {
        $prefix = $context ? "AIquila SDK: $context" : 'AIquila SDK';
        $logCtx = $this->buildErrorLogContext($e);
        if ($e instanceof AuthenticationException) {
            $this->logger->error("$prefix: Authentication error", $logCtx);
            return ['error' => 'Invalid API key. Please check your configuration.'];
        }
        if ($e instanceof PermissionDeniedException) {
            $this->logger->error("$prefix: Permission denied", $logCtx);
            return ['error' => 'Your API key does not have permission to use this resource.'];
        }
        if ($e instanceof RateLimitException) {
            $this->logger->error("$prefix: Rate limit exceeded", $logCtx);
            return ['error' => 'Rate limit exceeded. Please try again later.'];
        }
        if ($e instanceof InternalServerException) {
            $this->logger->error("$prefix: Anthropic server error", $logCtx);
            return ['error' => 'Anthropic API is temporarily unavailable. Please try again later.'];
        }
        if ($e instanceof APIConnectionException || $e instanceof APITimeoutException) {
            $this->logger->error("$prefix: Connection error", $logCtx);
            return ['error' => 'Connection to Claude API failed: ' . $e->getMessage()];
        }
        $this->logger->error("$prefix: Error", $logCtx + ['class' => get_class($e)]);
        return ['error' => 'Error: ' . $e->getMessage()];
    }

    /**
     * Build a log context for an SDK exception, including the typed
     * ErrorType enum value when the exception carries one (SDK v0.9+).
     *
     * @return array{error: string, error_type?: string}
     */
    private function buildErrorLogContext(\Throwable $e): array {
        $ctx = ['error' => $e->getMessage()];
        if ($e instanceof APIStatusException && $e->type !== null) {
            $ctx['error_type'] = $e->type->value;
        }
        return $ctx;
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

            $usage = $this->extractUsage($response);

            $this->logger->info('AIquila SDK: Successful response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => $usage,
            ]);

            $this->logResponseMetadata($response);

            return [
                'response' => $this->extractText($response),
                'usage' => $usage,
                'citations' => $this->extractCitations($response),
            ];

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

            $usage = $this->extractUsage($response);

            $this->logger->info('AIquila SDK: chat() response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => $usage,
            ]);

            $this->logResponseMetadata($response);

            return [
                'response' => $this->extractText($response),
                'usage' => $usage,
                'citations' => $this->extractCitations($response),
            ];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'chat');
        }
    }

    /**
     * Agentic tool-use loop: sends messages + tools to Claude, executes tool
     * calls via the provided callback, and loops until Claude produces a final
     * text response or the iteration cap is reached.
     *
     * @param array $messages Conversation messages
     * @param array $tools Anthropic-format tool definitions
     * @param callable $toolExecutor fn(string $name, array $input): array — returns MCP tool result
     * @param string|null $system Optional system prompt
     * @param string|null $userId User ID for API key resolution
     * @param array $options Optional: temperature, top_p, etc.
     * @param int $maxIterations Safety cap on tool-use loop iterations
     * @return array ['response' => string, 'usage' => [...]] or ['error' => string]
     */
    public function chatWithTools(
        array    $messages,
        array    $tools,
        callable $toolExecutor,
        ?string  $system = null,
        ?string  $userId = null,
        array    $options = [],
        int      $maxIterations = 10
    ): array {
        try {
            $client = $this->getClient($userId);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'chatWithTools');
        }

        if ($system !== null) {
            $options['system'] = $system;
        }
        $options['tools'] = $tools;

        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCacheCreationTokens = 0;
        $totalCacheReadTokens = 0;
        $allCitations = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                $params = $this->buildRequestParams($messages, $userId, $options);
                $response = $this->callCreate($client, $params);
            } catch (\Throwable $e) {
                return $this->handleException($e, 'chatWithTools');
            }

            $totalInputTokens += $response->usage->inputTokens ?? 0;
            $totalOutputTokens += $response->usage->outputTokens ?? 0;
            $totalCacheCreationTokens += $response->usage->cacheCreationInputTokens ?? 0;
            $totalCacheReadTokens += $response->usage->cacheReadInputTokens ?? 0;
            $allCitations = array_merge($allCitations, $this->extractCitations($response));

            $this->logResponseMetadata($response);

            // Check if response contains tool use blocks
            $toolUseBlocks = [];
            $textParts = [];
            foreach ($response->content as $block) {
                if ($block->type === 'tool_use') {
                    $toolUseBlocks[] = $block;
                } elseif ($block->type === 'text') {
                    $textParts[] = $block->text;
                }
            }

            // If no tool use, return the text response
            if (empty($toolUseBlocks) || ($response->stopReason ?? '') === 'end_turn') {
                if (empty($toolUseBlocks)) {
                    return [
                        'response' => implode('', $textParts),
                        'usage' => [
                            'input_tokens' => $totalInputTokens,
                            'output_tokens' => $totalOutputTokens,
                            'cache_creation_tokens' => $totalCacheCreationTokens ?: null,
                            'cache_read_tokens' => $totalCacheReadTokens ?: null,
                        ],
                        'citations' => $allCitations,
                    ];
                }
            }

            // Build assistant message with the full content (text + tool_use blocks)
            $assistantContent = [];
            foreach ($response->content as $block) {
                if ($block->type === 'text') {
                    $assistantContent[] = ['type' => 'text', 'text' => $block->text];
                } elseif ($block->type === 'tool_use') {
                    $assistantContent[] = [
                        'type' => 'tool_use',
                        'id' => $block->id,
                        'name' => $block->name,
                        'input' => $block->input,
                    ];
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

            // Execute each tool and build tool_result blocks
            $toolResults = [];
            foreach ($toolUseBlocks as $block) {
                $input = is_array($block->input) ? $block->input : (array)$block->input;
                $result = $toolExecutor($block->name, $input);

                // Convert MCP result to Anthropic tool_result format
                $resultContent = '';
                if (isset($result['content']) && is_array($result['content'])) {
                    foreach ($result['content'] as $part) {
                        if (($part['type'] ?? '') === 'text') {
                            $resultContent .= $part['text'];
                        }
                    }
                } else {
                    $resultContent = json_encode($result);
                }

                $toolResult = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block->id,
                    'content' => $resultContent,
                ];
                if (!empty($result['isError'])) {
                    $toolResult['is_error'] = true;
                }
                $toolResults[] = $toolResult;
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];

            $this->logger->debug('AIquila SDK: chatWithTools iteration', [
                'iteration' => $i + 1,
                'tool_calls' => count($toolUseBlocks),
            ]);
        }

        // Max iterations reached — return whatever text we have
        $this->logger->warning('AIquila SDK: chatWithTools reached max iterations', [
            'maxIterations' => $maxIterations,
        ]);

        return [
            'response' => implode('', $textParts ?? []) ?: 'I was unable to complete the request within the allowed number of tool-use iterations.',
            'usage' => [
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
                'cache_creation_tokens' => $totalCacheCreationTokens ?: null,
                'cache_read_tokens' => $totalCacheReadTokens ?: null,
            ],
            'citations' => $allCitations,
        ];
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
     * Summarize via the Anthropic Batches API (50% input/output token discount).
     *
     * Submits a single-request batch, polls until ended, then fetches and
     * unpacks the result. Same return shape as ask()/summarize() so it is a
     * drop-in for non-interactive callers (TaskProcessing summary provider).
     *
     * Holds the calling worker open for the lifetime of the batch — only suitable
     * for non-interactive paths (`TaskProcessing` runs `process()` from a
     * background job already). Do not use from HTTP request handlers.
     *
     * @return array{response: string, usage: array, citations: array}|array{error: string}
     */
    public function summarizeViaBatch(string $content, ?string $userId = null, ?callable $reportProgress = null): array {
        try {
            $client = $this->getClient($userId);

            $messages = [['role' => 'user', 'content' => "Summarize the following content concisely:\n\n$content"]];
            $batchParams = $this->toBatchParams($this->buildRequestParams($messages, $userId));

            $customId = 'aiquila-summary-' . bin2hex(random_bytes(8));
            $batch = $this->callBatchCreate($client, [['custom_id' => $customId, 'params' => $batchParams]]);

            $this->logger->info('AIquila SDK: batch submitted', [
                'batch_id' => $batch->id,
                'custom_id' => $customId,
            ]);
            if ($reportProgress !== null) {
                $reportProgress(0.1);
            }

            $batch = $this->waitForBatch($client, $batch->id, $reportProgress);

            if ($batch->processingStatus !== 'ended') {
                throw new \RuntimeException("Batch did not complete: status={$batch->processingStatus}");
            }

            foreach ($this->callBatchResults($client, $batch->id) as $entry) {
                if ($entry->customID !== $customId) {
                    continue;
                }
                $result = $this->convertBatchResult($entry);
                if (isset($result['response'])) {
                    $this->logger->info('AIquila SDK: batch summary completed', [
                        'batch_id' => $batch->id,
                        'usage' => $result['usage'] ?? null,
                    ]);
                }
                return $result;
            }
            throw new \RuntimeException("Batch result for custom_id $customId not found");
        } catch (\Throwable $e) {
            return $this->handleException($e, 'summarizeViaBatch');
        }
    }

    /**
     * Convert the snake_case Messages API params shape produced by
     * buildRequestParams() into the camelCase shape required by
     * BatchCreateParams\Request\Params.
     */
    private function toBatchParams(array $params): array {
        $out = [
            'maxTokens' => $params['max_tokens'],
            'messages'  => $params['messages'],
            'model'     => $params['model'],
        ];
        foreach (['system', 'thinking', 'outputConfig', 'temperature', 'tools'] as $k) {
            if (isset($params[$k])) {
                $out[$k] = $params[$k];
            }
        }
        if (isset($params['top_p'])) {
            $out['topP'] = $params['top_p'];
        }
        if (isset($params['top_k'])) {
            $out['topK'] = $params['top_k'];
        }
        if (isset($params['stop_sequences'])) {
            $out['stopSequences'] = $params['stop_sequences'];
        }
        return $out;
    }

    /**
     * Poll a batch until processingStatus === 'ended', reporting progress.
     *
     * Polls every 5 s up to ~20 min. The Anthropic API guarantees batches
     * complete within 24 h; for one-request summaries the typical end-to-end
     * latency is single-digit seconds.
     */
    private function waitForBatch(Client $client, string $batchId, ?callable $reportProgress): MessageBatch {
        $maxAttempts = 240;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $batch = $this->callBatchRetrieve($client, $batchId);
            if ($batch->processingStatus === 'ended') {
                if ($reportProgress !== null) {
                    $reportProgress(0.95);
                }
                return $batch;
            }
            if ($reportProgress !== null) {
                $progress = min(0.9, 0.1 + ($i / $maxAttempts) * 0.8);
                $reportProgress($progress);
            }
            $this->sleepBetweenBatchPolls();
        }
        throw new \RuntimeException("Batch $batchId did not complete within poll window");
    }

    /**
     * Convert one MessageBatchIndividualResponse into the array shape used by
     * other ClaudeSDKService entry points.
     */
    private function convertBatchResult(MessageBatchIndividualResponse $entry): array {
        $r = $entry->result;
        if ($r instanceof MessageBatchSucceededResult) {
            return [
                'response'  => $this->extractText($r->message),
                'usage'     => $this->extractUsage($r->message),
                'citations' => $this->extractCitations($r->message),
            ];
        }
        if ($r instanceof MessageBatchErroredResult) {
            $message = $r->error->error->message ?? 'Batch request errored';
            return ['error' => $message];
        }
        if ($r instanceof MessageBatchCanceledResult) {
            return ['error' => 'Batch request canceled'];
        }
        if ($r instanceof MessageBatchExpiredResult) {
            return ['error' => 'Batch request expired before processing'];
        }
        return ['error' => 'Unknown batch result type'];
    }

    /**
     * Sleep between batch poll attempts. Overridable for tests.
     */
    protected function sleepBetweenBatchPolls(): void {
        sleep(5);
    }

    /**
     * Dispatch a batches->create. Overridable for testing.
     *
     * @param list<array{custom_id: string, params: array}> $requests
     */
    protected function callBatchCreate(Client $client, array $requests): MessageBatch {
        return $client->messages->batches->create($requests);
    }

    /**
     * Dispatch a batches->retrieve. Overridable for testing.
     */
    protected function callBatchRetrieve(Client $client, string $batchId): MessageBatch {
        return $client->messages->batches->retrieve($batchId);
    }

    /**
     * Dispatch a batches->resultsStream. Overridable for testing.
     *
     * @return iterable<MessageBatchIndividualResponse>
     */
    protected function callBatchResults(Client $client, string $batchId): iterable {
        return $client->messages->batches->resultsStream($batchId);
    }

    /**
     * Ask Claude about a document (PDF or plain text).
     *
     * @param string $prompt What to ask about the document
     * @param string $documentData Raw bytes (PDF) or plain text
     * @param string $mediaType 'application/pdf' | 'text/plain'
     * @param string $title Optional document title
     * @param string|null $userId User ID for API key resolution
     * @param bool $cacheDoc Add cache_control to the document block (default true — documents are large and benefit from cache reuse)
     * @param bool $citations Enable citations on the document block (default true). Cited text spans are returned alongside the response.
     * @param string|null $fileId Optional Anthropic Files API file_id. When provided, the document source is `{type:'file', file_id}` instead of inline base64/text.
     * @return array ['response' => string, 'usage' => [...], 'citations' => [...]] or ['error' => string]
     */
    public function askWithDocument(
        string  $prompt,
        string  $documentData,
        string  $mediaType,
        string  $title     = '',
        ?string $userId    = null,
        bool    $cacheDoc  = true,
        bool    $citations = true,
        ?string $fileId    = null
    ): array {
        try {
            $client = $this->getClient($userId);

            if ($fileId !== null) {
                $source = [
                    'type'    => 'file',
                    'file_id' => $fileId,
                ];
            } elseif ($mediaType === 'application/pdf') {
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
            if ($citations) {
                $docBlock['citations'] = ['enabled' => true];
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

            $usage = $this->extractUsage($response);

            $this->logger->info('AIquila SDK: Document analysis response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'media_type'  => $mediaType,
                'usage' => $usage,
            ]);

            $this->logResponseMetadata($response);

            return [
                'response' => $this->extractText($response),
                'usage' => $usage,
                'citations' => $this->extractCitations($response),
            ];

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
     * @param string|null $fileId Optional Anthropic Files API file_id. When provided, the image source is `{type:'file', file_id}` instead of inline base64.
     * @return array ['response' => string] or ['error' => string]
     */
    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array {
        try {
            $client = $this->getClient($userId);

            $imageSource = $fileId !== null
                ? ['type' => 'file', 'file_id' => $fileId]
                : ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64Image];

            $messages = [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => $imageSource,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ];
            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId));

            $usage = $this->extractUsage($response);

            $this->logger->info('AIquila SDK: Image analysis response', [
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => $usage,
            ]);

            $this->logResponseMetadata($response);

            return ['response' => $this->extractText($response), 'usage' => $usage];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Image analysis');
        }
    }

    /**
     * Ask Claude about multiple images using vision capabilities.
     *
     * Builds a single user message with N image content blocks followed by
     * the text prompt, per Claude best practice (images before text).
     *
     * @param string $prompt What to ask about the images
     * @param array<array{base64: string, mimeType: string}> $images Image data array
     * @param string|null $userId User ID for API key
     * @param array<int, string|null>|null $fileIds Optional per-image Anthropic Files API file_ids, indexed parallel to $images. A non-null entry triggers a `{type:'file', file_id}` source for that image; null entries fall back to inline base64.
     * @return array ['response' => string, 'usage' => array] or ['error' => string]
     */
    public function askWithImages(string $prompt, array $images, ?string $userId = null, ?array $fileIds = null): array {
        if (empty($images)) {
            return ['error' => 'No images provided'];
        }

        if (count($images) > 20) {
            return ['error' => 'Too many images. Maximum 20 images per request.'];
        }

        try {
            $client = $this->getClient($userId);

            $content = [];
            foreach (array_values($images) as $i => $img) {
                $fid = $fileIds[$i] ?? null;
                $source = $fid !== null
                    ? ['type' => 'file', 'file_id' => $fid]
                    : ['type' => 'base64', 'media_type' => $img['mimeType'], 'data' => $img['base64']];
                $content[] = [
                    'type' => 'image',
                    'source' => $source,
                ];
            }
            $lastImageIdx = count($content) - 1;
            $content[$lastImageIdx]['cache_control'] = ['type' => 'ephemeral'];
            $content[] = [
                'type' => 'text',
                'text' => $prompt,
            ];

            $messages = [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ];
            $response = $this->callCreate($client, $this->buildRequestParams($messages, $userId));

            $usage = $this->extractUsage($response);

            $this->logger->info('AIquila SDK: Multi-image analysis response', [
                'image_count' => count($images),
                'stop_reason' => $response->stopReason ?? 'unknown',
                'usage' => $usage,
            ]);

            $this->logResponseMetadata($response);

            return ['response' => $this->extractText($response), 'usage' => $usage];

        } catch (\Throwable $e) {
            return $this->handleException($e, 'Multi-image analysis');
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
            $this->logger->error('AIquila SDK: Stream authentication error', $this->buildErrorLogContext($e));
            throw new \Exception('Invalid API key. Please check your configuration.');
        } catch (PermissionDeniedException $e) {
            $this->logger->error('AIquila SDK: Stream permission denied', $this->buildErrorLogContext($e));
            throw new \Exception('Your API key does not have permission to use this resource.');
        } catch (RateLimitException $e) {
            $this->logger->error('AIquila SDK: Stream rate limit exceeded', $this->buildErrorLogContext($e));
            throw new \Exception('Rate limit exceeded. Please try again later.');
        } catch (InternalServerException $e) {
            $this->logger->error('AIquila SDK: Stream Anthropic server error', $this->buildErrorLogContext($e));
            throw new \Exception('Anthropic API is temporarily unavailable. Please try again later.');
        } catch (APIConnectionException | APITimeoutException $e) {
            $this->logger->error('AIquila SDK: Stream connection error', $this->buildErrorLogContext($e));
            throw new \Exception('Connection to Claude API failed: ' . $e->getMessage());
        } catch (APIStatusException | \Exception $e) {
            $this->logger->error('AIquila SDK: Stream error', $this->buildErrorLogContext($e) + ['class' => get_class($e)]);
            throw new \Exception('Stream error: ' . $e->getMessage());
        }
    }

    /**
     * Streaming variant of chatWithTools(). Yields a sequence of event arrays
     * the caller can re-encode (e.g. as Server-Sent Events) for incremental
     * delivery to the UI. Event shapes:
     *
     *   ['type' => 'text_delta',  'text' => string]
     *   ['type' => 'tool_use',    'id' => string, 'name' => string, 'input' => array]
     *   ['type' => 'tool_result', 'tool_use_id' => string, 'output' => string, 'is_error' => bool]
     *   ['type' => 'done',        'usage' => [...], 'citations' => [...]]
     *   ['type' => 'error',       'error' => string, 'usage' => [...]]
     *
     * The internal loop accumulates streamed content blocks into text and
     * tool_use blocks; tool execution still happens locally, and the loop
     * re-enters with tool_result messages until the model emits end_turn or
     * $maxIterations is reached.
     *
     * @param array $messages
     * @param array $tools
     * @param callable $toolExecutor fn(string $name, array $input): array
     * @param string|null $system
     * @param string|null $userId
     * @param array $options
     * @param int $maxIterations
     */
    public function chatWithToolsStream(
        array    $messages,
        array    $tools,
        callable $toolExecutor,
        ?string  $system = null,
        ?string  $userId = null,
        array    $options = [],
        int      $maxIterations = 10
    ): \Generator {
        try {
            $client = $this->getClient($userId);
        } catch (\Throwable $e) {
            yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => null];
            return;
        }

        if ($system !== null) {
            $options['system'] = $system;
        }
        $options['tools'] = $tools;

        $totalInput = 0;
        $totalOutput = 0;
        $totalCacheCreate = 0;
        $totalCacheRead = 0;
        $allCitations = [];

        for ($i = 0; $i < $maxIterations; $i++) {
            $params = $this->buildRequestParams($messages, $userId, $options);
            try {
                $stream = $this->callCreateStream($client, $params);
            } catch (\Throwable $e) {
                $this->logger->error('AIquila SDK: chatWithToolsStream open failed', $this->buildErrorLogContext($e));
                yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => null];
                return;
            }

            // Per-block accumulators, keyed by content-block index.
            /** @var array<int, array<string, mixed>> $blocks */
            $blocks = [];
            /** @var array<int, string> $jsonBuffers */
            $jsonBuffers = [];
            $stopReason = null;

            try {
                foreach ($stream as $event) {
                    $type = $event->type ?? null;
                    switch ($type) {
                        case 'message_start':
                            if (isset($event->message->usage)) {
                                $totalInput += $event->message->usage->inputTokens ?? 0;
                                $totalCacheCreate += $event->message->usage->cacheCreationInputTokens ?? 0;
                                $totalCacheRead += $event->message->usage->cacheReadInputTokens ?? 0;
                            }
                            break;

                        case 'content_block_start':
                            $idx = $event->index;
                            $cb = $event->contentBlock;
                            $cbType = $cb->type ?? null;
                            if ($cbType === 'text') {
                                $blocks[$idx] = ['type' => 'text', 'text' => '', 'citations' => []];
                            } elseif ($cbType === 'tool_use') {
                                $blocks[$idx] = [
                                    'type' => 'tool_use',
                                    'id' => $cb->id ?? '',
                                    'name' => $cb->name ?? '',
                                    'input' => null,
                                ];
                                $jsonBuffers[$idx] = '';
                            } else {
                                // thinking, server tools etc. — preserve type so downstream may handle.
                                $blocks[$idx] = ['type' => $cbType ?? 'unknown'];
                            }
                            break;

                        case 'content_block_delta':
                            $idx = $event->index;
                            $delta = $event->delta;
                            $deltaType = $delta->type ?? null;
                            if ($deltaType === 'text_delta') {
                                $text = $delta->text ?? '';
                                if (isset($blocks[$idx])) {
                                    $blocks[$idx]['text'] = ($blocks[$idx]['text'] ?? '') . $text;
                                }
                                if ($text !== '') {
                                    yield ['type' => 'text_delta', 'text' => $text];
                                }
                            } elseif ($deltaType === 'input_json_delta') {
                                $jsonBuffers[$idx] = ($jsonBuffers[$idx] ?? '') . ($delta->partialJSON ?? '');
                            } elseif ($deltaType === 'citations_delta') {
                                $citation = $delta->citation ?? null;
                                if ($citation !== null) {
                                    $normalized = is_object($citation) ? json_decode(json_encode($citation), true) : $citation;
                                    if (isset($blocks[$idx])) {
                                        $blocks[$idx]['citations'][] = $normalized;
                                    }
                                }
                            }
                            // thinking/signature deltas: ignore for now.
                            break;

                        case 'content_block_stop':
                            $idx = $event->index;
                            if (isset($blocks[$idx]) && $blocks[$idx]['type'] === 'tool_use') {
                                $json = $jsonBuffers[$idx] ?? '';
                                $blocks[$idx]['input'] = $json !== '' ? (json_decode($json, true) ?? []) : [];
                            }
                            break;

                        case 'message_delta':
                            $stopReason = $event->delta->stopReason ?? null;
                            if (isset($event->usage)) {
                                $totalOutput += $event->usage->outputTokens ?? 0;
                            }
                            break;

                        case 'message_stop':
                        default:
                            // No-op.
                            break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('AIquila SDK: chatWithToolsStream stream failed', $this->buildErrorLogContext($e));
                yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => [
                    'input_tokens' => $totalInput,
                    'output_tokens' => $totalOutput,
                    'cache_creation_tokens' => $totalCacheCreate ?: null,
                    'cache_read_tokens' => $totalCacheRead ?: null,
                ]];
                return;
            }

            // Collect any per-text-block citations seen during this iteration.
            foreach ($blocks as $b) {
                if (($b['type'] ?? null) === 'text' && !empty($b['citations'])) {
                    $allCitations = array_merge($allCitations, $b['citations']);
                }
            }

            $toolUseBlocks = array_values(array_filter($blocks, fn($b) => ($b['type'] ?? null) === 'tool_use'));

            if ($toolUseBlocks === [] || $stopReason === 'end_turn') {
                yield [
                    'type' => 'done',
                    'usage' => [
                        'input_tokens' => $totalInput,
                        'output_tokens' => $totalOutput,
                        'cache_creation_tokens' => $totalCacheCreate ?: null,
                        'cache_read_tokens' => $totalCacheRead ?: null,
                    ],
                    'citations' => $allCitations,
                ];
                return;
            }

            // Build assistant message reflecting the full streamed turn.
            $assistantContent = [];
            foreach ($blocks as $b) {
                if (($b['type'] ?? null) === 'text') {
                    $assistantContent[] = ['type' => 'text', 'text' => $b['text'] ?? ''];
                } elseif (($b['type'] ?? null) === 'tool_use') {
                    $assistantContent[] = [
                        'type' => 'tool_use',
                        'id' => $b['id'],
                        'name' => $b['name'],
                        'input' => $b['input'] ?? [],
                    ];
                    yield [
                        'type' => 'tool_use',
                        'id' => $b['id'],
                        'name' => $b['name'],
                        'input' => $b['input'] ?? [],
                    ];
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $assistantContent];

            // Execute tools and feed results back into the next iteration.
            $toolResults = [];
            foreach ($toolUseBlocks as $b) {
                $input = is_array($b['input'] ?? null) ? $b['input'] : [];
                $result = $toolExecutor($b['name'], $input);

                $resultContent = '';
                if (isset($result['content']) && is_array($result['content'])) {
                    foreach ($result['content'] as $part) {
                        if (($part['type'] ?? '') === 'text') {
                            $resultContent .= $part['text'] ?? '';
                        }
                    }
                } else {
                    $resultContent = json_encode($result);
                }

                $tr = [
                    'type' => 'tool_result',
                    'tool_use_id' => $b['id'],
                    'content' => $resultContent,
                ];
                if (!empty($result['isError'])) {
                    $tr['is_error'] = true;
                }
                $toolResults[] = $tr;

                yield [
                    'type' => 'tool_result',
                    'tool_use_id' => $b['id'],
                    'output' => $resultContent,
                    'is_error' => !empty($result['isError']),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        $this->logger->warning('AIquila SDK: chatWithToolsStream reached max iterations', [
            'maxIterations' => $maxIterations,
        ]);
        yield [
            'type' => 'error',
            'error' => 'Max tool-use iterations reached',
            'usage' => [
                'input_tokens' => $totalInput,
                'output_tokens' => $totalOutput,
                'cache_creation_tokens' => $totalCacheCreate ?: null,
                'cache_read_tokens' => $totalCacheRead ?: null,
            ],
        ];
    }

    /**
     * Dispatch a beta streaming create call with mcp_servers attached. Overridable for testing.
     *
     * @param list<array<string, mixed>> $mcpServers
     */
    protected function callBetaCreateStreamWithMcp(Client $client, array $params, array $mcpServers): BaseStream {
        return $client->beta->messages->createStream(
            maxTokens: $params['max_tokens'],
            messages: $params['messages'],
            model: $params['model'],
            mcpServers: $mcpServers,
            outputConfig: $params['outputConfig'] ?? null,
            stopSequences: $params['stop_sequences'] ?? null,
            system: $params['system'] ?? null,
            temperature: $params['temperature'] ?? null,
            thinking: $params['thinking'] ?? null,
            tools: $params['tools'] ?? null,
            topK: $params['top_k'] ?? null,
            topP: $params['top_p'] ?? null,
            betas: [AnthropicBeta::MCP_CLIENT_2025_11_20],
        );
    }

    /**
     * Native MCP connector path. Hands the conversation to Anthropic's beta
     * messages endpoint with `mcp_servers` set; Anthropic calls each server
     * directly over HTTPS and returns mcp_tool_use / mcp_tool_result blocks
     * inline, so no PHP-side agentic loop is required.
     *
     * Yields the same event shape as chatWithToolsStream() so controllers can
     * persist/render either path interchangeably:
     *
     *   ['type' => 'text_delta',  'text' => string]
     *   ['type' => 'tool_use',    'id' => string, 'name' => string, 'input' => array, 'server' => string]
     *   ['type' => 'tool_result', 'tool_use_id' => string, 'output' => string, 'is_error' => bool]
     *   ['type' => 'done',        'usage' => [...], 'citations' => [...]]
     *   ['type' => 'error',       'error' => string, 'usage' => [...]]
     *
     * @param list<array<string, mixed>> $mcpServers Server descriptors per BetaRequestMCPServerURLDefinition
     */
    public function chatWithNativeMcp(
        array    $messages,
        array    $mcpServers,
        ?string  $system = null,
        ?string  $userId = null,
        array    $options = []
    ): \Generator {
        try {
            $client = $this->getClient($userId);
        } catch (\Throwable $e) {
            yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => null];
            return;
        }

        if ($mcpServers === []) {
            yield ['type' => 'error', 'error' => 'Native MCP connector enabled but no reachable HTTPS MCP servers are configured.', 'usage' => null];
            return;
        }

        if ($system !== null) {
            $options['system'] = $system;
        }
        // Tools are advertised by the MCP servers themselves under the
        // beta connector; do not pre-merge user-defined tool schemas.
        unset($options['tools']);

        $params = $this->buildRequestParams($messages, $userId, $options);

        try {
            $stream = $this->callBetaCreateStreamWithMcp($client, $params, $mcpServers);
        } catch (\Throwable $e) {
            $this->logger->error('AIquila SDK: chatWithNativeMcp open failed', $this->buildErrorLogContext($e));
            yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => null];
            return;
        }

        $totalInput = 0;
        $totalOutput = 0;
        $totalCacheCreate = 0;
        $totalCacheRead = 0;
        $allCitations = [];

        // Per-block accumulators, keyed by content-block index.
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];
        /** @var array<int, string> $jsonBuffers */
        $jsonBuffers = [];

        try {
            foreach ($stream as $event) {
                $type = $event->type ?? null;
                switch ($type) {
                    case 'message_start':
                        if (isset($event->message->usage)) {
                            $totalInput += $event->message->usage->inputTokens ?? 0;
                            $totalCacheCreate += $event->message->usage->cacheCreationInputTokens ?? 0;
                            $totalCacheRead += $event->message->usage->cacheReadInputTokens ?? 0;
                        }
                        break;

                    case 'content_block_start':
                        $idx = $event->index;
                        $cb = $event->contentBlock;
                        $cbType = $cb->type ?? null;
                        if ($cbType === 'text') {
                            $blocks[$idx] = ['type' => 'text', 'text' => '', 'citations' => []];
                        } elseif ($cbType === 'mcp_tool_use') {
                            $blocks[$idx] = [
                                'type' => 'mcp_tool_use',
                                'id' => $cb->id ?? '',
                                'name' => $cb->name ?? '',
                                'server' => $cb->serverName ?? '',
                                'input' => null,
                            ];
                            $jsonBuffers[$idx] = '';
                        } elseif ($cbType === 'mcp_tool_result') {
                            // Anthropic returns the full result inline in content_block_start
                            // (no streaming deltas), so we can yield immediately.
                            $output = $this->stringifyMcpResultContent($cb->content ?? null);
                            yield [
                                'type' => 'tool_result',
                                'tool_use_id' => $cb->toolUseID ?? '',
                                'output' => $output,
                                'is_error' => (bool)($cb->isError ?? false),
                            ];
                            $blocks[$idx] = ['type' => 'mcp_tool_result'];
                        } else {
                            $blocks[$idx] = ['type' => $cbType ?? 'unknown'];
                        }
                        break;

                    case 'content_block_delta':
                        $idx = $event->index;
                        $delta = $event->delta;
                        $deltaType = $delta->type ?? null;
                        if ($deltaType === 'text_delta') {
                            $text = $delta->text ?? '';
                            if (isset($blocks[$idx])) {
                                $blocks[$idx]['text'] = ($blocks[$idx]['text'] ?? '') . $text;
                            }
                            if ($text !== '') {
                                yield ['type' => 'text_delta', 'text' => $text];
                            }
                        } elseif ($deltaType === 'input_json_delta') {
                            $jsonBuffers[$idx] = ($jsonBuffers[$idx] ?? '') . ($delta->partialJSON ?? '');
                        } elseif ($deltaType === 'citations_delta') {
                            $citation = $delta->citation ?? null;
                            if ($citation !== null) {
                                $normalized = is_object($citation) ? json_decode(json_encode($citation), true) : $citation;
                                if (isset($blocks[$idx])) {
                                    $blocks[$idx]['citations'][] = $normalized;
                                }
                            }
                        }
                        break;

                    case 'content_block_stop':
                        $idx = $event->index;
                        if (isset($blocks[$idx]) && ($blocks[$idx]['type'] ?? null) === 'mcp_tool_use') {
                            $json = $jsonBuffers[$idx] ?? '';
                            $blocks[$idx]['input'] = $json !== '' ? (json_decode($json, true) ?? []) : [];
                            yield [
                                'type' => 'tool_use',
                                'id' => $blocks[$idx]['id'],
                                'name' => $blocks[$idx]['name'],
                                'input' => $blocks[$idx]['input'],
                                'server' => $blocks[$idx]['server'] ?? '',
                            ];
                        }
                        break;

                    case 'message_delta':
                        if (isset($event->usage)) {
                            $totalOutput += $event->usage->outputTokens ?? 0;
                        }
                        break;

                    case 'message_stop':
                    default:
                        break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('AIquila SDK: chatWithNativeMcp stream failed', $this->buildErrorLogContext($e));
            yield ['type' => 'error', 'error' => $e->getMessage(), 'usage' => [
                'input_tokens' => $totalInput,
                'output_tokens' => $totalOutput,
                'cache_creation_tokens' => $totalCacheCreate ?: null,
                'cache_read_tokens' => $totalCacheRead ?: null,
            ]];
            return;
        }

        foreach ($blocks as $b) {
            if (($b['type'] ?? null) === 'text' && !empty($b['citations'])) {
                $allCitations = array_merge($allCitations, $b['citations']);
            }
        }

        yield [
            'type' => 'done',
            'usage' => [
                'input_tokens' => $totalInput,
                'output_tokens' => $totalOutput,
                'cache_creation_tokens' => $totalCacheCreate ?: null,
                'cache_read_tokens' => $totalCacheRead ?: null,
            ],
            'citations' => $allCitations,
        ];
    }

    /**
     * Flatten an mcp_tool_result content payload (string or array of text blocks)
     * into a single string suitable for yielding to clients and persisting in
     * the conversation log.
     *
     * @param mixed $content
     */
    private function stringifyMcpResultContent($content): string {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $out = '';
            foreach ($content as $part) {
                if (is_object($part)) {
                    if (($part->type ?? null) === 'text') {
                        $out .= $part->text ?? '';
                    }
                } elseif (is_array($part)) {
                    if (($part['type'] ?? null) === 'text') {
                        $out .= $part['text'] ?? '';
                    }
                }
            }
            return $out !== '' ? $out : json_encode($content);
        }
        if (is_object($content)) {
            return json_encode($content) ?: '';
        }
        return '';
    }

    /**
     * Non-streaming convenience: drive chatWithNativeMcp() to completion and
     * return a flat result array compatible with the legacy chat()/chatWithTools()
     * shape (response/model/usage/citations).
     */
    public function chatWithNativeMcpCollect(
        array $messages,
        array $mcpServers,
        ?string $system = null,
        ?string $userId = null,
        array $options = []
    ): array {
        $text = '';
        $citations = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $error = null;

        foreach ($this->chatWithNativeMcp($messages, $mcpServers, $system, $userId, $options) as $event) {
            switch ($event['type'] ?? null) {
                case 'text_delta':
                    $text .= $event['text'] ?? '';
                    break;
                case 'done':
                    $usage = $event['usage'] ?? $usage;
                    $citations = $event['citations'] ?? [];
                    break;
                case 'error':
                    $error = $event['error'] ?? 'Unknown error';
                    if (isset($event['usage']) && is_array($event['usage'])) {
                        $usage = $event['usage'];
                    }
                    break;
            }
        }

        if ($error !== null && $text === '') {
            return ['error' => $error, 'model' => $this->getModel($userId), 'usage' => $usage];
        }

        return [
            'response' => $text,
            'model' => $this->getModel($userId),
            'usage' => $usage,
            'citations' => $citations,
        ];
    }
}
