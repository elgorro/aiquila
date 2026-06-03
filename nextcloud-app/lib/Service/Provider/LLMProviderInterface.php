<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Provider-neutral surface for the chat experience.
 *
 * Both the Anthropic implementation (ClaudeSDKService) and MistralProvider
 * implement this interface so controllers can switch the active LLM at runtime
 * via LLMProviderFactory without depending on a concrete provider.
 *
 * Message and tool definitions flowing in/out are kept in the application's
 * canonical Anthropic-block shape (string content, or arrays of
 * text/image/document/tool_use/tool_result blocks; tools as
 * {name, description, input_schema}). Non-Anthropic providers translate to and
 * from their native wire format internally so the controllers and
 * McpClientService stay provider-agnostic.
 */
interface LLMProviderInterface {
    /** Stable provider id, e.g. 'anthropic' or 'mistral'. */
    public function getId(): string;

    /** Human-readable label for settings UIs. */
    public function getLabel(): string;

    /** True when an API key is available for this provider (user or admin scope). */
    public function isConfigured(?string $userId = null): bool;

    /** Whether this provider supports Anthropic's native MCP connector path. */
    public function supportsNativeMcp(): bool;

    public function getApiKey(?string $userId = null): string;

    public function getModel(?string $userId = null): string;

    public function getMaxTokens(?string $userId = null): int;

    /** @return array{api_key: string, model: string, max_tokens: int, timeout: int} */
    public function getConfiguration(): array;

    /**
     * List available model IDs for the configured key, or null on error
     * (caller falls back to the static registry).
     *
     * @return list<string>|null
     */
    public function listModels(?string $userId = null): ?array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function ask(string $prompt, string $context = '', ?string $userId = null, array $options = []): array;

    /** @return array{response: string, usage?: array}|array{error: string} */
    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array;

    /**
     * @param array<array{base64: string, mimeType: string}> $images
     * @param array<int, string|null>|null $fileIds
     * @return array{response: string, usage?: array}|array{error: string}
     */
    public function askWithImages(string $prompt, array $images, ?string $userId = null, ?array $fileIds = null): array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function askWithDocument(string $prompt, string $documentData, string $mediaType, string $title = '', ?string $userId = null, bool $cacheDoc = true, bool $citations = true, ?string $fileId = null): array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function chat(array $messages, ?string $system = null, ?string $userId = null, array $options = []): array;

    /**
     * @param array $tools Anthropic-format tool definitions ({name, description, input_schema})
     * @param callable $toolExecutor fn(string $name, array $input): array
     * @return array{response: string, usage?: array, citations?: array}|array{error: string}
     */
    public function chatWithTools(array $messages, array $tools, callable $toolExecutor, ?string $system = null, ?string $userId = null, array $options = [], int $maxIterations = 10): array;

    /**
     * Streaming agentic loop. Yields normalized event arrays:
     *   ['type' => 'text_delta',  'text' => string]
     *   ['type' => 'tool_use',    'id' => string, 'name' => string, 'input' => array]
     *   ['type' => 'tool_result', 'tool_use_id' => string, 'output' => string, 'is_error' => bool]
     *   ['type' => 'done',        'usage' => array, 'citations' => array]
     *   ['type' => 'error',       'error' => string, 'usage' => ?array]
     *
     * @param callable $toolExecutor fn(string $name, array $input): array
     */
    public function chatWithToolsStream(array $messages, array $tools, callable $toolExecutor, ?string $system = null, ?string $userId = null, array $options = [], int $maxIterations = 10): \Generator;

    /**
     * Native MCP connector path (Anthropic only). Providers that return false
     * from supportsNativeMcp() are never asked to run this; they yield an error.
     */
    public function chatWithNativeMcp(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): \Generator;

    /** Non-streaming convenience wrapper around chatWithNativeMcp(). */
    public function chatWithNativeMcpCollect(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): array;

    /** @return array{response: string, usage?: array}|array{error: string} */
    public function summarize(string $content, ?string $userId = null): array;
}
