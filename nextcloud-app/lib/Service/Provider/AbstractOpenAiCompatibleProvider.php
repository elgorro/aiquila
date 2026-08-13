<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\CredentialService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Shared implementation for providers speaking the OpenAI chat-completions wire
 * format (`POST {base}/chat/completions`, `GET {base}/models`).
 *
 * Messages and tools arrive in the application's canonical Anthropic-block shape;
 * this class translates them to the OpenAI-style wire format on the way out and
 * normalizes responses/stream events on the way back, so controllers and
 * McpClientService remain provider-agnostic.
 *
 * Subclasses supply identity (getId/getLabel), the base URL, and the model
 * registry, and may override the capability hooks below. The defaults describe a
 * text-only, no-native-MCP provider — the common case.
 *
 * HTTP uses Nextcloud's IClientService (proxy/cert aware). Streaming relies on
 * the `stream => true` option, under which Response::getBody() yields a raw PHP
 * stream resource we read incrementally for Server-Sent Events.
 *
 * MistralProvider deliberately does not extend this class: its native MCP path
 * runs against Mistral's Conversations API, which diverges too far to share.
 */
abstract class AbstractOpenAiCompatibleProvider implements LLMProviderInterface {
    protected const APP_NAME = 'aiquila';
    protected const DEFAULT_STREAM_TIMEOUT = 300;

    public function __construct(
        protected readonly IClientService $clientService,
        protected readonly IConfig $config,
        protected readonly CredentialService $credentials,
        protected readonly LoggerInterface $logger,
    ) {
    }

    // ── Subclass contract ───────────────────────────────────────────────────

    /** Base URL including the version segment, without a trailing slash. */
    abstract protected function apiBase(): string;

    /** Whether the backend accepts image input (sent as `image_url` data URIs). */
    protected function supportsVisionInput(?string $userId = null): bool {
        return false;
    }

    /**
     * Whether to send `tool_choice: auto` alongside `tools`. Some
     * OpenAI-compatible servers (notably Ollama) reject the field.
     */
    protected function sendsToolChoice(): bool {
        return true;
    }

    /** Timeout in seconds for non-streaming requests. */
    protected function requestTimeout(): int {
        return (int)$this->config->getAppValue(self::APP_NAME, 'api_timeout', '30');
    }

    /** Timeout in seconds for streaming requests. */
    protected function streamTimeout(): int {
        return self::DEFAULT_STREAM_TIMEOUT;
    }

    /**
     * Copy sampling parameters from the caller's options onto the request body.
     * Overridden by providers whose models reject them.
     */
    protected function applySamplingParams(array &$body, string $model, array $options): void {
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }
    }

    // ── Identity / configuration ────────────────────────────────────────────

    public function supportsNativeMcp(): bool {
        return false;
    }

    public function getApiKey(?string $userId = null): string {
        return $this->credentials->getApiKey($userId, $this->getId());
    }

    public function isConfigured(?string $userId = null): bool {
        return $this->getApiKey($userId) !== '';
    }

    public function getConfiguration(): array {
        return [
            'api_key' => '', // never expose the stored key
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'timeout' => $this->requestTimeout(),
        ];
    }

    // ── Settings schema ─────────────────────────────────────────────────────

    /**
     * Config key naming is uniform across the OpenAI-compatible providers:
     * `model_<id>` / `user_model_<id>` / `max_tokens_<id>`, with the shared
     * `api_timeout`. Subclasses that deviate (LocalProvider's own timeout key)
     * or add fields (endpoint URLs, vision toggles) merge into this list.
     */
    public function getSettingsSchema(): array {
        $id = $this->getId();
        return [
            ProviderSettingsSchema::apiKey(
                'API key',
                'Stored encrypted in Nextcloud\'s credential manager. A personal key overrides the instance key.',
            ),
            ProviderSettingsSchema::model(
                'model_' . $id,
                'user_model_' . $id,
                $this->defaultModel(),
                'Used for every request unless a conversation pins a different one.',
            ),
            ProviderSettingsSchema::maxTokens('max_tokens_' . $id, $this->defaultMaxTokens()),
            ProviderSettingsSchema::timeout('api_timeout', 30, 'Shared across all hosted providers.'),
        ];
    }

    public function getCapabilities(): array {
        return ProviderSettingsSchema::capabilities([
            'vision' => $this->supportsVisionInput(),
            'tools' => true,
            'streaming' => true,
            'native_mcp' => $this->supportsNativeMcp(),
        ]);
    }

    /** Model id offered when nothing is configured; used by the schema default. */
    abstract protected function defaultModel(): string;

    /** Max-tokens value offered when nothing is configured. */
    abstract protected function defaultMaxTokens(): int;

    public function listModels(?string $userId = null): ?array {
        if (!$this->isConfigured($userId)) {
            return null;
        }
        try {
            $client = $this->clientService->newClient();
            $response = $client->get($this->apiBase() . '/models', $this->requestOptions($userId, [
                'timeout' => 15,
            ]));
            $data = json_decode((string)$response->getBody(), true);
            $items = is_array($data) ? ($data['data'] ?? []) : [];
            $ids = [];
            foreach ($items as $m) {
                if (isset($m['id']) && is_string($m['id'])) {
                    $ids[] = $m['id'];
                }
            }
            sort($ids);
            return $ids !== [] ? $ids : null;
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila ' . $this->getLabel() . ': Could not list models', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Non-streaming entry points ──────────────────────────────────────────

    public function ask(string $prompt, string $context = '', ?string $userId = null, array $options = []): array {
        $content = $context !== '' ? "Context:\n$context\n\nQuestion: $prompt" : $prompt;
        return $this->chat([['role' => 'user', 'content' => $content]], $options['system'] ?? null, $userId, $options);
    }

    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array {
        return $this->askWithImages($prompt, [['base64' => $base64Image, 'mimeType' => $mimeType]], $userId, $fileId !== null ? [$fileId] : null);
    }

    public function askWithImages(string $prompt, array $images, ?string $userId = null, ?array $fileIds = null): array {
        if (!$this->supportsVisionInput($userId)) {
            return ['error' => $this->getLabel() . ' is not configured with a vision-capable model. Please switch models or providers.'];
        }
        if ($images === []) {
            return ['error' => 'No images provided'];
        }
        $content = [];
        foreach (array_values($images) as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mimeType'], 'data' => $img['base64']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        return $this->chat([['role' => 'user', 'content' => $content]], null, $userId);
    }

    public function askWithDocument(string $prompt, string $documentData, string $mediaType, string $title = '', ?string $userId = null, bool $cacheDoc = true, bool $citations = true, ?string $fileId = null): array {
        if ($mediaType === 'application/pdf') {
            return ['error' => $this->getLabel() . ' does not support PDF input. Please extract the text first or switch providers.'];
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
            return ['error' => $this->getLabel() . ' returned no choices'];
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

        $this->logger->warning('AIquila ' . $this->getLabel() . ': chatWithTools reached max iterations', ['maxIterations' => $maxIterations]);
        return ['response' => $finalText ?: 'I was unable to complete the request within the allowed number of tool-use iterations.', 'usage' => $this->finalizeUsage($total), 'citations' => []];
    }

    public function summarize(string $content, ?string $userId = null): array {
        return $this->ask("Summarize the following content concisely:\n\n$content", '', $userId);
    }

    // ── Streaming agentic loop ──────────────────────────────────────────────

    public function chatWithToolsStream(array $messages, array $tools, callable $toolExecutor, ?string $system = null, ?string $userId = null, array $options = [], int $maxIterations = 10): \Generator {
        if (!$this->isConfigured($userId)) {
            yield ['type' => 'error', 'error' => $this->notConfiguredMessage(), 'usage' => null];
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
                        // Reasoning models stream a separate `reasoning_content`
                        // field. The frontend has no chain-of-thought renderer, so
                        // it is intentionally not surfaced — only the final answer
                        // (`content`) is streamed and persisted.
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
                $this->logger->error('AIquila ' . $this->getLabel() . ': chatWithToolsStream failed', ['error' => $e->getMessage()]);
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

        $this->logger->warning('AIquila ' . $this->getLabel() . ': chatWithToolsStream reached max iterations', ['maxIterations' => $maxIterations]);
        yield ['type' => 'error', 'error' => 'Max tool-use iterations reached', 'usage' => $this->finalizeUsage($total)];
    }

    // ── Native MCP (unsupported by default) ─────────────────────────────────

    public function chatWithNativeMcp(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): \Generator {
        yield ['type' => 'error', 'error' => $this->getLabel() . ' does not support native MCP connectors.', 'usage' => null];
    }

    public function chatWithNativeMcpCollect(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): array {
        return ['error' => $this->getLabel() . ' does not support native MCP connectors.', 'model' => $this->getModel($userId), 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]];
    }

    // ── Request building / HTTP ─────────────────────────────────────────────

    /**
     * Build a chat-completions request body from app-format messages.
     */
    protected function buildBody(array $messages, ?string $system, ?string $userId, array $options, bool $stream = false): array {
        $model = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? $options['model']
            : $this->getModel($userId);
        $body = [
            'model' => $model,
            'max_tokens' => $this->getMaxTokens($userId),
            'messages' => $this->toOpenAiMessages($messages, $system, $userId),
        ];
        if ($stream) {
            $body['stream'] = true;
            $body['stream_options'] = ['include_usage' => true];
        }
        $this->applySamplingParams($body, $model, $options);
        if (array_key_exists('stop_sequences', $options)) {
            $body['stop'] = $options['stop_sequences'];
        }
        $tools = $this->toOpenAiTools($options['tools'] ?? []);
        if ($tools !== null) {
            $body['tools'] = $tools;
            if ($this->sendsToolChoice()) {
                $body['tool_choice'] = 'auto';
            }
        }
        return $body;
    }

    protected function requestJson(array $body, ?string $userId): array {
        if (!$this->isConfigured($userId)) {
            throw new \RuntimeException($this->notConfiguredMessage());
        }
        $client = $this->clientService->newClient();
        $response = $client->post($this->apiBase() . '/chat/completions', $this->requestOptions($userId, [
            'body' => json_encode($body),
            'timeout' => $this->requestTimeout(),
        ]));
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException($this->getLabel() . ' returned a non-JSON response');
        }
        return $decoded;
    }

    /**
     * Open a streaming chat-completions request. Returns a readable PHP stream
     * resource (Nextcloud detaches the body when `stream => true`).
     *
     * @return resource
     */
    protected function openStream(array $body, ?string $userId) {
        $client = $this->clientService->newClient();
        $options = $this->requestOptions($userId, [
            'body' => json_encode($body),
            'stream' => true,
            'timeout' => $this->streamTimeout(),
        ]);
        $options['headers'] += ['Accept' => 'text/event-stream'];
        $response = $client->post($this->apiBase() . '/chat/completions', $options);
        $stream = $response->getBody();
        if (!is_string($stream) && is_resource($stream)) {
            return $stream;
        }

        // Some client backends return the full body instead of a resource;
        // wrap it so the SSE reader can consume it uniformly.
        $tmp = fopen('php://temp', 'r+');
        if ($tmp === false) {
            throw new \RuntimeException('Could not open a temporary stream');
        }
        fwrite($tmp, is_string($stream) ? $stream : '');
        rewind($tmp);

        return $tmp;
    }

    /**
     * Assemble IClientService options, merging in the auth headers. Subclasses
     * override this to add transport-level options (e.g. local address access).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function requestOptions(?string $userId, array $extra): array {
        return ['headers' => $this->headers($this->requireApiKey($userId))] + $extra;
    }

    protected function requireApiKey(?string $userId): string {
        $key = $this->getApiKey($userId);
        if ($key === '') {
            throw new \RuntimeException('No API key configured');
        }
        return $key;
    }

    /** @return array<string, string> */
    protected function headers(string $apiKey): array {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    // ── Message / tool translation (Anthropic blocks → OpenAI) ──────────────

    /**
     * Convert app-format (Anthropic-block) messages to an OpenAI-style message
     * list. A single app message may expand to several messages (e.g. a user turn
     * carrying multiple tool_result blocks).
     */
    protected function toOpenAiMessages(array $messages, ?string $system, ?string $userId = null): array {
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

            // user/assistant turn with text/image/document parts.
            $out[] = ['role' => $role, 'content' => $this->renderUserContent($content, $userId)];
        }
        return $out;
    }

    /**
     * Render text/image/document blocks either as a plain string (text-only
     * backends) or as OpenAI content parts (vision-capable backends).
     *
     * @return string|list<array<string, mixed>>
     */
    protected function renderUserContent(array $blocks, ?string $userId = null) {
        return $this->supportsVisionInput($userId)
            ? $this->toContentParts($blocks)
            : $this->flattenToText($blocks);
    }

    /**
     * Translate text/image/document blocks into OpenAI content parts. Images
     * become `image_url` data URIs; documents are inlined as text (only PDFs are
     * rejected upstream in askWithDocument()).
     *
     * @return list<array<string, mixed>>
     */
    protected function toContentParts(array $blocks): array {
        $parts = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $type = $b['type'] ?? '';
            if ($type === 'text') {
                $parts[] = ['type' => 'text', 'text' => $b['text'] ?? ''];
            } elseif ($type === 'image') {
                $source = $b['source'] ?? [];
                if (($source['type'] ?? '') === 'base64') {
                    $dataUri = 'data:' . ($source['media_type'] ?? 'image/jpeg') . ';base64,' . ($source['data'] ?? '');
                    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUri]];
                }
            } elseif ($type === 'document') {
                $source = $b['source'] ?? [];
                if (($source['type'] ?? '') === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $source['data'] ?? ''];
                }
            }
        }
        return $parts;
    }

    /**
     * Collapse text/document content blocks into a single string. Image blocks
     * are ignored; document blocks are inlined as text.
     */
    protected function flattenToText(array $blocks): string {
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
    protected function extractText(array $content): string {
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
    protected function toOpenAiTools(array $tools): ?array {
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
    protected function assistantMessageFromToolCalls(string $text, array $toolCalls): array {
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
    protected function executeToolCalls(array $toolCalls, callable $toolExecutor): array {
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
    protected function normalizeAccumulatedToolCalls(array $toolAcc): array {
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

    protected function decodeArguments(string $arguments): array {
        if ($arguments === '') {
            return [];
        }
        $decoded = json_decode($arguments, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ── Usage accounting ────────────────────────────────────────────────────

    /** @return array{input: int, output: int} */
    protected function newUsage(): array {
        return ['input' => 0, 'output' => 0];
    }

    protected function accumulateUsage(array &$total, array $usage): void {
        $total['input'] += (int)($usage['prompt_tokens'] ?? 0);
        $total['output'] += (int)($usage['completion_tokens'] ?? 0);
    }

    /** @return array{input_tokens: int, output_tokens: int, cache_creation_tokens: null, cache_read_tokens: null} */
    protected function finalizeUsage(array $total): array {
        return [
            'input_tokens' => $total['input'],
            'output_tokens' => $total['output'],
            'cache_creation_tokens' => null,
            'cache_read_tokens' => null,
        ];
    }

    /** @return array{input_tokens: int, output_tokens: int} */
    protected function extractUsage(array $usage): array {
        return [
            'input_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
        ];
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    /** Message shown when chatWithToolsStream() runs unconfigured. */
    protected function notConfiguredMessage(): string {
        return 'No API key configured';
    }

    protected function handleException(\Throwable $e, string $context): array {
        $this->logger->error('AIquila ' . $this->getLabel() . ": $context error", ['error' => $e->getMessage()]);
        return ['error' => $this->errorMessage($e)];
    }

    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'Invalid ' . $this->getLabel() . ' API key. Please check your configuration.';
        }
        if (stripos($msg, '429') !== false) {
            return 'Rate limit exceeded. Please try again later.';
        }
        return $this->getLabel() . ' API error: ' . $msg;
    }
}
