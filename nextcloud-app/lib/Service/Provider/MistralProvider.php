<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\MistralModels;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Mistral AI provider (https://api.mistral.ai/v1, OpenAI-compatible).
 *
 * Implements the same provider-neutral surface as the Anthropic-backed
 * ClaudeSDKService. Messages and tools arrive in the application's canonical
 * Anthropic-block shape; this class translates them to Mistral's OpenAI-style
 * wire format on the way out and normalizes responses/stream events on the way
 * back, so controllers and McpClientService remain provider-agnostic.
 *
 * HTTP uses Nextcloud's IClientService (proxy/cert aware). Streaming relies on
 * the `stream => true` option, under which Response::getBody() yields a raw PHP
 * stream resource we read incrementally for Server-Sent Events.
 */
class MistralProvider implements LLMProviderInterface {
    private const PROVIDER_ID = 'mistral';
    private const API_BASE = 'https://api.mistral.ai/v1';
    private const CONVERSATIONS_URL = self::API_BASE . '/conversations';
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
        return 'Mistral';
    }

    public function supportsNativeMcp(): bool {
        return true;
    }

    public function getApiKey(?string $userId = null): string {
        return $this->credentials->getApiKey($userId, self::PROVIDER_ID);
    }

    public function isConfigured(?string $userId = null): bool {
        return $this->getApiKey($userId) !== '';
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_mistral', '');
            if ($userModel !== '') {
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_mistral', MistralModels::DEFAULT_MODEL);
    }

    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_mistral', (string)MistralModels::DEFAULT_MAX_TOKENS);
        return min($stored, MistralModels::getMaxTokenCeiling($this->getModel($userId)));
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
            $this->logger->warning('AIquila Mistral: Could not list models', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Non-streaming entry points ──────────────────────────────────────────

    public function ask(string $prompt, string $context = '', ?string $userId = null, array $options = []): array {
        $content = $context !== '' ? "Context:\n$context\n\nQuestion: $prompt" : $prompt;
        return $this->chat([['role' => 'user', 'content' => $content]], $options['system'] ?? null, $userId, $options);
    }

    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array {
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $base64Image]],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]];
        return $this->chat($messages, null, $userId, $this->visionOptions($userId));
    }

    public function askWithImages(string $prompt, array $images, ?string $userId = null, ?array $fileIds = null): array {
        if ($images === []) {
            return ['error' => 'No images provided'];
        }
        $content = [];
        foreach (array_values($images) as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mimeType'], 'data' => $img['base64']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];
        return $this->chat([['role' => 'user', 'content' => $content]], null, $userId, $this->visionOptions($userId));
    }

    /**
     * Force a vision-capable model when the user's configured model cannot
     * accept image input (e.g. the default mistral-small).
     *
     * @return array{model?: string}
     */
    private function visionOptions(?string $userId): array {
        return MistralModels::supportsVision($this->getModel($userId))
            ? []
            : ['model' => MistralModels::PIXTRAL];
    }

    public function askWithDocument(string $prompt, string $documentData, string $mediaType, string $title = '', ?string $userId = null, bool $cacheDoc = true, bool $citations = true, ?string $fileId = null): array {
        $source = $mediaType === 'application/pdf'
            ? ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($documentData)]
            : ['type' => 'text', 'media_type' => 'text/plain', 'data' => $documentData];
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'document', 'source' => $source],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]];
        return $this->chat($messages, null, $userId);
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
            return ['error' => 'Mistral returned no choices'];
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

        $this->logger->warning('AIquila Mistral: chatWithTools reached max iterations', ['maxIterations' => $maxIterations]);
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
                $this->logger->error('AIquila Mistral: chatWithToolsStream failed', ['error' => $e->getMessage()]);
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

        $this->logger->warning('AIquila Mistral: chatWithToolsStream reached max iterations', ['maxIterations' => $maxIterations]);
        yield ['type' => 'error', 'error' => 'Max tool-use iterations reached', 'usage' => $this->finalizeUsage($total)];
    }

    // ── Native MCP (Mistral Connectors + Conversations API) ──────────────────

    /**
     * Native MCP path. Hands the conversation to Mistral's Conversations API
     * (`POST /v1/conversations`) with pre-registered MCP connectors attached as
     * tools; Mistral calls each connector directly and streams the result back,
     * so no PHP-side agentic loop is required.
     *
     * Connectors are scoped to the Mistral workspace key that registered them,
     * so this path always authenticates with the app-level (admin) Mistral key,
     * regardless of any per-user key. `$mcpServers` arrives as connector tool
     * entries (`[{type:'connector', connector_id}, …]`) built by NativeMcpService.
     *
     * Yields the same event shape as chatWithToolsStream() / the Anthropic native
     * path so controllers can persist/render either path interchangeably.
     */
    public function chatWithNativeMcp(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): \Generator {
        $apiKey = $this->getApiKey(null);
        if ($apiKey === '') {
            yield ['type' => 'error', 'error' => 'No admin Mistral API key configured for native MCP.', 'usage' => null];
            return;
        }
        $tools = $this->normalizeConnectorTools($mcpServers);
        if ($tools === []) {
            yield ['type' => 'error', 'error' => 'Native MCP connector enabled but no Mistral connector IDs are configured.', 'usage' => null];
            return;
        }

        $total = $this->newUsage();
        $body = $this->buildConversationBody($messages, $system, $tools, $userId, $options, true);

        // Per-output-index accumulators for streamed connector tool calls.
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolAcc */
        $toolAcc = [];

        try {
            $stream = $this->openConversationStream($body, $apiKey);
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

                    switch ($event['type'] ?? '') {
                        case 'message.output.delta':
                            $text = $this->extractDeltaText($event['content'] ?? '');
                            if ($text !== '') {
                                yield ['type' => 'text_delta', 'text' => $text];
                            }
                            break;
                        case 'tool.execution.started':
                            $idx = (int)($event['output_index'] ?? 0);
                            $toolAcc[$idx] = [
                                'id' => (string)($event['id'] ?? ''),
                                'name' => (string)($event['name'] ?? ''),
                                'arguments' => (string)($event['arguments'] ?? ''),
                            ];
                            break;
                        case 'tool.execution.delta':
                            $idx = (int)($event['output_index'] ?? 0);
                            if (isset($toolAcc[$idx])) {
                                $toolAcc[$idx]['arguments'] .= (string)($event['arguments'] ?? '');
                            }
                            break;
                        case 'tool.execution.done':
                            $idx = (int)($event['output_index'] ?? 0);
                            $tc = $toolAcc[$idx] ?? [
                                'id' => (string)($event['id'] ?? ''),
                                'name' => (string)($event['name'] ?? ''),
                                'arguments' => '',
                            ];
                            yield [
                                'type' => 'tool_use',
                                'id' => $tc['id'],
                                'name' => $tc['name'],
                                'input' => $this->decodeArguments($tc['arguments']),
                                'server' => 'mistral',
                            ];
                            yield [
                                'type' => 'tool_result',
                                'tool_use_id' => $tc['id'],
                                'output' => $this->extractToolDoneOutput($event),
                                'is_error' => false,
                            ];
                            unset($toolAcc[$idx]);
                            break;
                        case 'conversation.response.done':
                            $this->accumulateUsage($total, $event['usage'] ?? []);
                            break;
                        case 'conversation.response.error':
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            yield ['type' => 'error', 'error' => (string)($event['message'] ?? 'Mistral conversation error'), 'usage' => $this->finalizeUsage($total)];
                            return;
                        default:
                            break;
                    }
                }
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            $this->logger->error('AIquila Mistral: chatWithNativeMcp stream failed', ['error' => $e->getMessage()]);
            yield ['type' => 'error', 'error' => $this->errorMessage($e), 'usage' => $this->finalizeUsage($total)];
            return;
        }

        yield ['type' => 'done', 'usage' => $this->finalizeUsage($total), 'citations' => []];
    }

    /**
     * Non-streaming convenience: drive chatWithNativeMcp() to completion and
     * return a flat result array compatible with the chat()/chatWithTools()
     * shape (response/model/usage/citations).
     */
    public function chatWithNativeMcpCollect(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): array {
        $text = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $error = null;

        foreach ($this->chatWithNativeMcp($messages, $mcpServers, $system, $userId, $options) as $event) {
            switch ($event['type'] ?? null) {
                case 'text_delta':
                    $text .= $event['text'] ?? '';
                    break;
                case 'done':
                    $usage = $event['usage'] ?? $usage;
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
            'citations' => [],
        ];
    }

    /**
     * Build a Conversations API request body from app-format messages.
     */
    private function buildConversationBody(array $messages, ?string $system, array $tools, ?string $userId, array $options, bool $stream): array {
        $body = [
            'model' => $this->getModel($userId),
            'inputs' => $this->toConversationInputs($messages),
            'tools' => $tools,
            'stream' => $stream,
            'store' => false,
        ];
        if ($system !== null && $system !== '') {
            $body['instructions'] = $system;
        }
        $completionArgs = ['max_tokens' => $this->getMaxTokens($userId)];
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $options)) {
                $completionArgs[$key] = $options[$key];
            }
        }
        if (array_key_exists('stop_sequences', $options)) {
            $completionArgs['stop'] = $options['stop_sequences'];
        }
        $body['completion_args'] = $completionArgs;
        return $body;
    }

    /**
     * Translate app-format (Anthropic-block) history into Conversations API
     * `inputs` entries. Prior tool_use/tool_result blocks are dropped — under
     * native connectors Mistral re-runs tools server-side, so only the
     * user/assistant text + image/document context is replayed.
     *
     * @return list<array{role: string, content: string|list<array<string, mixed>>}>
     */
    private function toConversationInputs(array $messages): array {
        $inputs = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            if ($role !== 'user' && $role !== 'assistant') {
                $role = 'user';
            }
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                if ($content !== '') {
                    $inputs[] = ['role' => $role, 'content' => $content];
                }
                continue;
            }
            if (!is_array($content)) {
                continue;
            }

            $blocks = array_values(array_filter(
                $content,
                fn($b) => is_array($b) && in_array($b['type'] ?? '', ['text', 'image', 'document'], true)
            ));
            $parts = $this->toMistralContentParts($blocks);
            if ($parts === []) {
                continue;
            }
            if (count($parts) === 1 && ($parts[0]['type'] ?? '') === 'text') {
                $inputs[] = ['role' => $role, 'content' => $parts[0]['text']];
            } else {
                $inputs[] = ['role' => $role, 'content' => $parts];
            }
        }
        if ($inputs === []) {
            $inputs[] = ['role' => 'user', 'content' => ''];
        }
        return $inputs;
    }

    /**
     * Normalize NativeMcpService connector entries into Conversations API
     * connector tool entries, keeping only those with a non-empty connector_id.
     *
     * @return list<array{type: string, connector_id: string}>
     */
    private function normalizeConnectorTools(array $mcpServers): array {
        $out = [];
        foreach ($mcpServers as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = $entry['connector_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $out[] = ['type' => 'connector', 'connector_id' => $id];
            }
        }
        return $out;
    }

    /**
     * Open a streaming Conversations request. Returns a readable PHP stream
     * resource (Nextcloud detaches the body when `stream => true`).
     *
     * @return resource
     */
    private function openConversationStream(array $body, string $apiKey) {
        $client = $this->clientService->newClient();
        $response = $client->post(self::CONVERSATIONS_URL, [
            'headers' => $this->headers($apiKey) + ['Accept' => 'text/event-stream'],
            'body' => json_encode($body),
            'stream' => true,
            'timeout' => self::STREAM_TIMEOUT,
        ]);
        $stream = $response->getBody();
        if (is_string($stream)) {
            $tmp = fopen('php://temp', 'r+');
            fwrite($tmp, $stream);
            rewind($tmp);
            return $tmp;
        }
        return $stream;
    }

    /**
     * Extract plain text from a message.output.delta `content` field, which may
     * be a string or an array of content chunks.
     *
     * @param mixed $content
     */
    private function extractDeltaText($content): string {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text') {
                    $text .= $part['text'] ?? '';
                }
            }
            return $text;
        }
        return '';
    }

    /**
     * Flatten the `info` payload of a tool.execution.done event into a string
     * suitable for yielding as a tool_result output.
     */
    private function extractToolDoneOutput(array $event): string {
        $info = $event['info'] ?? null;
        if (is_string($info)) {
            return $info;
        }
        if (is_array($info)) {
            return json_encode($info) ?: '';
        }
        return '';
    }

    // ── Request building / HTTP ─────────────────────────────────────────────

    /**
     * Build a Mistral chat-completions request body from app-format messages.
     */
    private function buildBody(array $messages, ?string $system, ?string $userId, array $options, bool $stream = false): array {
        $model = isset($options['model']) && is_string($options['model']) && $options['model'] !== ''
            ? $options['model']
            : $this->getModel($userId);
        $body = [
            'model' => $model,
            'max_tokens' => $this->getMaxTokens($userId),
            'messages' => $this->toMistralMessages($messages, $system),
        ];
        if ($stream) {
            $body['stream'] = true;
        }
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }
        if (array_key_exists('stop_sequences', $options)) {
            $body['stop'] = $options['stop_sequences'];
        }
        $tools = $this->toMistralTools($options['tools'] ?? []);
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
            throw new \RuntimeException('Mistral returned a non-JSON response');
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

    // ── Message / tool translation (Anthropic blocks → Mistral) ─────────────

    /**
     * Convert app-format (Anthropic-block) messages to Mistral's OpenAI-style
     * message list. A single app message may expand to several Mistral messages
     * (e.g. a user turn carrying multiple tool_result blocks).
     */
    private function toMistralMessages(array $messages, ?string $system): array {
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
                $text = '';
                foreach ($content as $b) {
                    if (is_array($b) && ($b['type'] ?? '') === 'text') {
                        $text .= $b['text'] ?? '';
                    }
                }
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
            $parts = $this->toMistralContentParts($content);
            $out[] = ['role' => $role, 'content' => $parts];
        }
        return $out;
    }

    /**
     * Translate text/image/document content blocks into Mistral content parts.
     *
     * @return list<array<string, mixed>>
     */
    private function toMistralContentParts(array $blocks): array {
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
                    $parts[] = ['type' => 'image_url', 'image_url' => $dataUri];
                }
            } elseif ($type === 'document') {
                $source = $b['source'] ?? [];
                if (($source['type'] ?? '') === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $source['data'] ?? ''];
                } elseif (($source['type'] ?? '') === 'base64') {
                    $dataUri = 'data:' . ($source['media_type'] ?? 'application/pdf') . ';base64,' . ($source['data'] ?? '');
                    $parts[] = ['type' => 'document_url', 'document_url' => $dataUri];
                }
            }
        }
        return $parts;
    }

    /**
     * Convert Anthropic-format tool definitions to Mistral function tools.
     *
     * @return list<array<string, mixed>>|null
     */
    private function toMistralTools(array $tools): ?array {
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
        $this->logger->error("AIquila Mistral: $context error", ['error' => $e->getMessage()]);
        return ['error' => $this->errorMessage($e)];
    }

    private function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'Invalid Mistral API key. Please check your configuration.';
        }
        if (stripos($msg, '429') !== false) {
            return 'Rate limit exceeded. Please try again later.';
        }
        return 'Mistral API error: ' . $msg;
    }
}
