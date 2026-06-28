<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\DeepSeekModels;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * DeepSeek provider (https://api.deepseek.com/v1, OpenAI-compatible).
 *
 * Implements the same provider-neutral surface as the Anthropic-backed
 * ClaudeSDKService. Messages and tools arrive in the application's canonical
 * Anthropic-block shape; this class translates them to DeepSeek's OpenAI-style
 * wire format on the way out and normalizes responses/stream events on the way
 * back, so controllers and McpClientService remain provider-agnostic.
 *
 * Structurally mirrors MistralProvider (both are OpenAI-compatible) but:
 *   - DeepSeek has no vision models, so image input is rejected.
 *   - DeepSeek has no native MCP connector path (supportsNativeMcp() === false).
 *   - The `deepseek-reasoner` model streams a separate `reasoning_content`
 *     field, surfaced here as text deltas, and rejects sampling params.
 *
 * HTTP uses Nextcloud's IClientService (proxy/cert aware). Streaming relies on
 * the `stream => true` option, under which Response::getBody() yields a raw PHP
 * stream resource we read incrementally for Server-Sent Events.
 */
class DeepSeekProvider implements LLMProviderInterface {
    private const PROVIDER_ID = 'deepseek';
    private const API_BASE = 'https://api.deepseek.com/v1';
    private const APP_NAME = 'aiquila';
    private const STREAM_TIMEOUT = 300;

    public function __construct(
        private readonly IClientService $clientService,
        private readonly IConfig $config,
        private readonly CredentialService $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return self::PROVIDER_ID;
    }

    public function getLabel(): string {
        return 'DeepSeek';
    }

    public function supportsNativeMcp(): bool {
        return false;
    }

    public function getApiKey(?string $userId = null): string {
        return $this->credentials->getApiKey($userId, self::PROVIDER_ID);
    }

    public function isConfigured(?string $userId = null): bool {
        return $this->getApiKey($userId) !== '';
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_deepseek', '');
            if ($userModel !== '') {
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_deepseek', DeepSeekModels::DEFAULT_MODEL);
    }

    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_deepseek', (string)DeepSeekModels::DEFAULT_MAX_TOKENS);
        return min($stored, DeepSeekModels::getMaxTokenCeiling($this->getModel($userId)));
    }

    public function getConfiguration(): array {
        return [
            'api_key' => '', // never expose the stored key
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'timeout' => (int)$this->config->getAppValue(self::APP_NAME, 'api_timeout', '30'),
        ];
    }

    public function listModels(?string $userId = null): ?array {
        $apiKey = $this->getApiKey($userId);
        if ($apiKey === '') {
            return null;
        }
        try {
            $client = $this->clientService->newClient();
            $response = $client->get(self::API_BASE . '/models', [
                'headers' => $this->headers($apiKey),
                'timeout' => 15,
            ]);
            $data = json_decode((string)$response->getBody(), true);
            $items = $data['data'] ?? [];
            $ids = [];
            foreach ($items as $m) {
                if (isset($m['id']) && is_string($m['id'])) {
                    $ids[] = $m['id'];
                }
            }
            sort($ids);
            return $ids !== [] ? $ids : null;
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila DeepSeek: Could not list models', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Non-streaming entry points ──────────────────────────────────────────

    public function ask(string $prompt, string $context = '', ?string $userId = null, array $options = []): array {
        $content = $context !== '' ? "Context:\n$context\n\nQuestion: $prompt" : $prompt;
        return $this->chat([['role' => 'user', 'content' => $content]], $options['system'] ?? null, $userId, $options);
    }

    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array {
        return ['error' => 'DeepSeek does not support image input. Please switch to a vision-capable provider.'];
    }

    public function askWithImages(string $prompt, array $images, ?string $userId = null, ?array $fileIds = null): array {
        return ['error' => 'DeepSeek does not support image input. Please switch to a vision-capable provider.'];
    }

    public function askWithDocument(string $prompt, string $documentData, string $mediaType, string $title = '', ?string $userId = null, bool $cacheDoc = true, bool $citations = true, ?string $fileId = null): array {
        if ($mediaType === 'application/pdf') {
            return ['error' => 'DeepSeek does not support PDF input. Please extract the text first or switch providers.'];
        }
        // Inline plain-text documents into the prompt.
        $content = $title !== ''
            ? "Document \"$title\":\n$documentData\n\n$prompt"
            : "Document:\n$documentData\n\n$prompt";
        return $this->chat([['role' => 'user', 'content' => $content]], null, $userId);
    }

    public function chat(array $messages, ?string $system = null, ?string $userId = null, array $options = []): array {
        try {
            $body = $this->buildBody($messages, $system, $userId, $options);
            $data = $this->requestJson($body, $userId);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'chat');
        }

        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            return ['error' => 'DeepSeek returned no choices'];
        }
        return [
            'response' => (string)($choice['message']['content'] ?? ''),
            'usage' => $this->extractUsage($data['usage'] ?? []),
            'citations' => [],
        ];
    }

    public function chatWithTools(array $messages, array $tools, callable $toolExecutor, ?string $system = null, ?string $userId = null, array $options = [], int $maxIterations = 10): array {
        $options['tools'] = $tools;
        $total = $this->newUsage();
        $finalText = '';

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                $body = $this->buildBody($messages, $system, $userId, $options);
                $data = $this->requestJson($body, $userId);
            } catch (\Throwable $e) {
                return $this->handleException($e, 'chatWithTools');
            }

            $this->accumulateUsage($total, $data['usage'] ?? []);
            $choice = $data['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $text = (string)($message['content'] ?? '');
            $toolCalls = $message['tool_calls'] ?? [];
            $finalText = $text;

            if ($toolCalls === [] || ($choice['finish_reason'] ?? '') === 'stop') {
                return ['response' => $text, 'usage' => $this->finalizeUsage($total), 'citations' => []];
            }

            $messages[] = $this->assistantMessageFromToolCalls($text, $toolCalls);
            $messages[] = ['role' => 'user', 'content' => $this->executeToolCalls($toolCalls, $toolExecutor)];
        }

        $this->logger->warning('AIquila DeepSeek: chatWithTools reached max iterations', ['maxIterations' => $maxIterations]);
        return ['response' => $finalText ?: 'I was unable to complete the request within the allowed number of tool-use iterations.', 'usage' => $this->finalizeUsage($total), 'citations' => []];
    }

    public function summarize(string $content, ?string $userId = null): array {
        return $this->ask("Summarize the following content concisely:\n\n$content", '', $userId);
    }

    // ── Streaming agentic loop ──────────────────────────────────────────────

    public function chatWithToolsStream(array $messages, array $tools, callable $toolExecutor, ?string $system = null, ?string $userId = null, array $options = [], int $maxIterations = 10): \Generator {
        if (!$this->isConfigured($userId)) {
            yield ['type' => 'error', 'error' => 'No API key configured', 'usage' => null];
            return;
        }
        $options['tools'] = $tools;
        $total = $this->newUsage();

        for ($i = 0; $i < $maxIterations; $i++) {
            $body = $this->buildBody($messages, $system, $userId, $options, true);

            $text = '';
            /** @var array<int, array{id: string, name: string, arguments: string}> $toolAcc */
            $toolAcc = [];
            $finishReason = null;

            try {
                $stream = $this->openStream($body, $userId);
                $buffer = '';
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    $buffer .= $chunk;
                    while (($nl = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $nl), "\r");
                        $buffer = substr($buffer, $nl + 1);
                        if ($line === '' || !str_starts_with($line, 'data:')) {
                            continue;
                        }
                        $payload = trim(substr($line, 5));
                        if ($payload === '[DONE]') {
                            break 2;
                        }
                        $event = json_decode($payload, true);
                        if (!is_array($event)) {
                            continue;
                        }
                        if (isset($event['usage'])) {
                            $this->accumulateUsage($total, $event['usage']);
                        }
                        $choice = $event['choices'][0] ?? null;
                        if ($choice === null) {
                            continue;
                        }
                        $delta = $choice['delta'] ?? [];
                        // Reasoning models (deepseek-reasoner) stream a separate
                        // `reasoning_content` field. The frontend has no chain-of-thought
                        // renderer, so it is intentionally not surfaced — only the final
                        // answer (`content`) is streamed and persisted.
                        $deltaText = $delta['content'] ?? null;
                        if (is_string($deltaText) && $deltaText !== '') {
                            $text .= $deltaText;
                            yield ['type' => 'text_delta', 'text' => $deltaText];
                        }
                        foreach ($delta['tool_calls'] ?? [] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (!isset($toolAcc[$idx])) {
                                $toolAcc[$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                            }
                            if (isset($tc['id'])) {
                                $toolAcc[$idx]['id'] = $tc['id'];
                            }
                            if (isset($tc['function']['name'])) {
                                $toolAcc[$idx]['name'] = $tc['function']['name'];
                            }
                            if (isset($tc['function']['arguments'])) {
                                $toolAcc[$idx]['arguments'] .= $tc['function']['arguments'];
                            }
                        }
                        if (!empty($choice['finish_reason'])) {
                            $finishReason = $choice['finish_reason'];
                        }
                    }
                }
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable $e) {
                $this->logger->error('AIquila DeepSeek: chatWithToolsStream failed', ['error' => $e->getMessage()]);
                yield ['type' => 'error', 'error' => $this->errorMessage($e), 'usage' => $this->finalizeUsage($total)];
                return;
            }

            $toolCalls = $this->normalizeAccumulatedToolCalls($toolAcc);

            if ($toolCalls === [] || $finishReason === 'stop') {
                yield ['type' => 'done', 'usage' => $this->finalizeUsage($total), 'citations' => []];
                return;
            }

            $messages[] = $this->assistantMessageFromToolCalls($text, $toolCalls);
            foreach ($toolCalls as $tc) {
                yield ['type' => 'tool_use', 'id' => $tc['id'], 'name' => $tc['function']['name'], 'input' => $this->decodeArguments($tc['function']['arguments'])];
            }
            $toolResults = $this->executeToolCalls($toolCalls, $toolExecutor);
            foreach ($toolResults as $tr) {
                yield ['type' => 'tool_result', 'tool_use_id' => $tr['tool_use_id'], 'output' => $tr['content'], 'is_error' => !empty($tr['is_error'])];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        $this->logger->warning('AIquila DeepSeek: chatWithToolsStream reached max iterations', ['maxIterations' => $maxIterations]);
        yield ['type' => 'error', 'error' => 'Max tool-use iterations reached', 'usage' => $this->finalizeUsage($total)];
    }

    // ── Native MCP (unsupported) ────────────────────────────────────────────

    public function chatWithNativeMcp(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): \Generator {
        yield ['type' => 'error', 'error' => 'DeepSeek does not support native MCP connectors.', 'usage' => null];
    }

    public function chatWithNativeMcpCollect(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): array {
        return ['error' => 'DeepSeek does not support native MCP connectors.', 'model' => $this->getModel($userId), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]];
    }

    // ── Request building / HTTP ─────────────────────────────────────────────

    /**
     * Build a DeepSeek chat-completions request body from app-format messages.
     */
    private function buildBody(array $messages, ?string $system, ?string $userId, array $options, bool $stream = false): array {
        $model = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? $options['model']
            : $this->getModel($userId);
        $body = [
            'model' => $model,
            'max_tokens' => $this->getMaxTokens($userId),
            'messages' => $this->toOpenAiMessages($messages, $system),
        ];
        if ($stream) {
            $body['stream'] = true;
            $body['stream_options'] = ['include_usage' => true];
        }
        // Reasoning models reject sampling params; only forward them otherwise.
        if (!DeepSeekModels::isReasoner($model)) {
            foreach (['temperature', 'top_p'] as $key) {
                if (array_key_exists($key, $options)) {
                    $body[$key] = $options[$key];
                }
            }
        }
        if (array_key_exists('stop_sequences', $options)) {
            $body['stop'] = $options['stop_sequences'];
        }
        $tools = $this->toOpenAiTools($options['tools'] ?? []);
        if ($tools !== null) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }
        return $body;
    }

    private function requestJson(array $body, ?string $userId): array {
        $client = $this->clientService->newClient();
        $response = $client->post(self::API_BASE . '/chat/completions', [
            'headers' => $this->headers($this->requireApiKey($userId)),
            'body' => json_encode($body),
            'timeout' => (int)$this->config->getAppValue(self::APP_NAME, 'api_timeout', '30'),
        ]);
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('DeepSeek returned a non-JSON response');
        }
        return $decoded;
    }

    /**
     * Open a streaming chat-completions request. Returns a readable PHP stream
     * resource (Nextcloud detaches the body when `stream => true`).
     *
     * @return resource
     */
    private function openStream(array $body, ?string $userId) {
        $client = $this->clientService->newClient();
        $response = $client->post(self::API_BASE . '/chat/completions', [
            'headers' => $this->headers($this->requireApiKey($userId)) + ['Accept' => 'text/event-stream'],
            'body' => json_encode($body),
            'stream' => true,
            'timeout' => self::STREAM_TIMEOUT,
        ]);
        $stream = $response->getBody();
        if (is_string($stream)) {
            // Some client backends return the full body instead of a resource;
            // wrap it so the SSE reader can consume it uniformly.
            $tmp = fopen('php://temp', 'r+');
            fwrite($tmp, $stream);
            rewind($tmp);
            return $tmp;
        }
        return $stream;
    }

    private function requireApiKey(?string $userId): string {
        $key = $this->getApiKey($userId);
        if ($key === '') {
            throw new \RuntimeException('No API key configured');
        }
        return $key;
    }

    /** @return array<string, string> */
    private function headers(string $apiKey): array {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    // ── Message / tool translation (Anthropic blocks → OpenAI) ──────────────

    /**
     * Convert app-format (Anthropic-block) messages to DeepSeek's OpenAI-style
     * message list. A single app message may expand to several messages (e.g. a
     * user turn carrying multiple tool_result blocks). Image blocks are dropped
     * since DeepSeek has no vision support.
     */
    private function toOpenAiMessages(array $messages, ?string $system): array {
        $out = [];
        if ($system !== null && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                $out[] = ['role' => $role, 'content' => $content];
                continue;
            }
            if (!is_array($content)) {
                continue;
            }

            // tool_result blocks (always carried on a user turn) → role:tool messages.
            $toolResults = array_filter($content, fn($b) => is_array($b) && ($b['type'] ?? '') === 'tool_result');
            if ($toolResults !== []) {
                foreach ($toolResults as $b) {
                    $out[] = [
                        'role' => 'tool',
                        'tool_call_id' => $b['tool_use_id'] ?? '',
                        'content' => is_string($b['content'] ?? null) ? $b['content'] : json_encode($b['content'] ?? ''),
                    ];
                }
                continue;
            }

            // assistant turn with tool_use blocks → tool_calls.
            $toolUses = array_values(array_filter($content, fn($b) => is_array($b) && ($b['type'] ?? '') === 'tool_use'));
            if ($role === 'assistant' && $toolUses !== []) {
                $text = $this->extractText($content);
                $toolCalls = [];
                foreach ($toolUses as $b) {
                    $toolCalls[] = [
                        'id' => $b['id'] ?? '',
                        'type' => 'function',
                        'function' => ['name' => $b['name'] ?? '', 'arguments' => json_encode($b['input'] ?? new \stdClass())],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $text, 'tool_calls' => $toolCalls];
                continue;
            }

            // user/assistant turn with text/document parts (images dropped).
            $out[] = ['role' => $role, 'content' => $this->flattenToText($content)];
        }
        return $out;
    }

    /**
     * Collapse text/document content blocks into a single string. Image blocks
     * are ignored (DeepSeek is text-only); document blocks are inlined as text.
     */
    private function flattenToText(array $blocks): string {
        $text = '';
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $type = $b['type'] ?? '';
            if ($type === 'text') {
                $text .= $b['text'] ?? '';
            } elseif ($type === 'document') {
                $source = $b['source'] ?? [];
                if (($source['type'] ?? '') === 'text') {
                    $text .= ($text !== '' ? "\n\n" : '') . ($source['data'] ?? '');
                }
            }
        }
        return $text;
    }

    /** Concatenate the text blocks of a content array. */
    private function extractText(array $content): string {
        $text = '';
        foreach ($content as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text') {
                $text .= $b['text'] ?? '';
            }
        }
        return $text;
    }

    /**
     * Convert Anthropic-format tool definitions to OpenAI function tools.
     *
     * @return list<array<string, mixed>>|null
     */
    private function toOpenAiTools(array $tools): ?array {
        if ($tools === []) {
            return null;
        }
        $out = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'])) {
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object'],
                ],
            ];
        }
        return $out !== [] ? $out : null;
    }

    // ── Tool-call helpers (shared by streaming + non-streaming) ──────────────

    /**
     * Build an app-format assistant message from streamed/returned tool calls.
     */
    private function assistantMessageFromToolCalls(string $text, array $toolCalls): array {
        $assistantContent = [];
        if ($text !== '') {
            $assistantContent[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($toolCalls as $tc) {
            $assistantContent[] = [
                'type' => 'tool_use',
                'id' => $tc['id'] ?? '',
                'name' => $tc['function']['name'] ?? '',
                'input' => $this->decodeArguments($tc['function']['arguments'] ?? ''),
            ];
        }
        return ['role' => 'assistant', 'content' => $assistantContent];
    }

    /**
     * Execute each tool call and return app-format tool_result blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function executeToolCalls(array $toolCalls, callable $toolExecutor): array {
        $results = [];
        foreach ($toolCalls as $tc) {
            $name = $tc['function']['name'] ?? '';
            $input = $this->decodeArguments($tc['function']['arguments'] ?? '');
            $result = $toolExecutor($name, $input);

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

            $block = ['type' => 'tool_result', 'tool_use_id' => $tc['id'] ?? '', 'content' => $resultContent];
            if (!empty($result['isError'])) {
                $block['is_error'] = true;
            }
            $results[] = $block;
        }
        return $results;
    }

    /**
     * Convert index-keyed streamed tool-call accumulators into the response
     * tool_calls shape used by the non-streaming path.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeAccumulatedToolCalls(array $toolAcc): array {
        $out = [];
        ksort($toolAcc);
        foreach ($toolAcc as $tc) {
            if (($tc['name'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'id' => $tc['id'] ?? '',
                'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments'] ?? ''],
            ];
        }
        return $out;
    }

    private function decodeArguments(string $arguments): array {
        if ($arguments === '') {
            return [];
        }
        $decoded = json_decode($arguments, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ── Usage accounting ────────────────────────────────────────────────────

    /** @return array{input: int, output: int} */
    private function newUsage(): array {
        return ['input' => 0, 'output' => 0];
    }

    private function accumulateUsage(array &$total, array $usage): void {
        $total['input'] += (int)($usage['prompt_tokens'] ?? 0);
        $total['output'] += (int)($usage['completion_tokens'] ?? 0);
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_creation_tokens: null, cache_read_tokens: null} */
    private function finalizeUsage(array $total): array {
        return [
            'input_tokens' => $total['input'],
            'output_tokens' => $total['output'],
            'cache_creation_tokens' => null,
            'cache_read_tokens' => null,
        ];
    }

    /** @return array{input_tokens: int, output_tokens: int} */
    private function extractUsage(array $usage): array {
        return [
            'input_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
        ];
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    private function handleException(\Throwable $e, string $context): array {
        $this->logger->error("AIquila DeepSeek: $context error", ['error' => $e->getMessage()]);
        return ['error' => $this->errorMessage($e)];
    }

    private function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'Invalid DeepSeek API key. Please check your configuration.';
        }
        if (stripos($msg, '429') !== false) {
            return 'Rate limit exceeded. Please try again later.';
        }
        return 'DeepSeek API error: ' . $msg;
    }
}
