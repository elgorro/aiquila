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
     * Describe this provider's configuration for the settings UI.
     *
     * Returns a list of field descriptors built with ProviderSettingsSchema.
     * The admin and personal pages render them generically, and
     * ProviderSettingsService reads/writes them from the descriptor's config
     * keys — so a new provider needs no frontend or controller changes.
     *
     * The `scope` of each field is a security boundary: only SCOPE_USER and
     * SCOPE_BOTH fields may be written from the personal page. Endpoint URLs
     * must stay SCOPE_ADMIN (SSRF).
     *
     * @return list<array<string, mixed>>
     */
    public function getSettingsSchema(): array;

    /**
     * What this provider can actually do, for capability chips in the settings
     * UI and for provider-aware validation (e.g. whether `effort` is a
     * meaningful option at all).
     *
     * @return array{vision: bool, tools: bool, streaming: bool, thinking: bool, effort: bool, native_mcp: bool, documents: bool, audio_in: bool, audio_out: bool, image_out: bool}
     */
    public function getCapabilities(): array;

    /**
     * Effort values this provider accepts for $model, in the provider's own
     * vocabulary (Anthropic's low…max, Mistral's none/high, …); empty when the
     * model — or the provider — has no effort knob.
     *
     * Callers must not validate one provider's effort against another's table;
     * that is what this exists for.
     *
     * @return list<string>
     */
    public function getAllowedEfforts(string $model): array;

    /**
     * List available model IDs for the configured key, or null on error
     * (caller falls back to the static registry).
     *
     * @return list<string>|null
     */
    public function listModels(?string $userId = null): ?array;

    /**
     * Cheap liveness check for the settings UI: can this key reach this
     * endpoint, and is the configured model actually on offer?
     *
     * Unlike listModels(), which flattens every failure to null, a probe
     * distinguishes a rejected key from an unreachable host from a provider
     * that is merely rate-limiting — that is what the four-state light on a
     * provider card needs. Build the return value with ProviderProbe.
     *
     * @return array{state: string, reason: string, message: string, model: string}
     */
    public function probe(?string $userId = null): array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function ask(string $prompt, string $context = '', ?string $userId = null, array $options = []): array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function askWithImage(string $prompt, string $base64Image, string $mimeType, ?string $userId = null, ?string $fileId = null): array;

    /**
     * @param array<array{base64: string, mimeType: string, ...}> $images
     * @param array<int, string|null>|null $fileIds
     * @return array{response: string, usage?: array, citations?: array}|array{error: string}
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
     *
     * @param list<array<string, mixed>> $mcpServers Server descriptors per BetaRequestMCPServerURLDefinition
     */
    public function chatWithNativeMcp(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): \Generator;

    /**
     * Non-streaming convenience wrapper around chatWithNativeMcp().
     *
     * @param list<array<string, mixed>> $mcpServers
     */
    public function chatWithNativeMcpCollect(array $messages, array $mcpServers, ?string $system = null, ?string $userId = null, array $options = []): array;

    /** @return array{response: string, usage?: array, citations?: array}|array{error: string} */
    public function summarize(string $content, ?string $userId = null): array;

    // ── Non-text modalities ─────────────────────────────────────────────────
    //
    // Most providers have no endpoint for these. Rather than throwing, they
    // return the same {error: string} shape as every other call, carrying a
    // message that names the provider — see the UnsupportedModalities trait.
    // Whether a provider can serve one is asked through getCapabilities():
    // 'audio_in', 'audio_out' and 'image_out' respectively.

    /**
     * Transcribe an audio recording to text.
     *
     * $filename is passed to the provider because transcription endpoints
     * routinely sniff the container format from the extension rather than from
     * the declared MIME type.
     *
     * @param array{language?: string} $options
     * @return array{response: string, usage?: array}|array{error: string}
     */
    public function transcribeAudio(string $audioData, string $mimeType, string $filename = 'audio', ?string $userId = null, array $options = []): array;

    /**
     * Synthesize speech from text, returning the raw audio bytes and the MIME
     * type they are in — TaskProcessing stores file outputs as bytes, so no
     * provider ever deals in file handles here.
     *
     * @param array{voice?: string} $options
     * @return array{audio: string, mimeType: string, usage?: array}|array{error: string}
     */
    public function synthesizeSpeech(string $text, ?string $userId = null, array $options = []): array;

    /**
     * Generate up to $count images from a prompt, as raw bytes.
     *
     * $count is a request, not a guarantee: providers that generate through a
     * model-driven tool cannot bind the number exactly, so callers must handle
     * a shorter list.
     *
     * @return array{images: list<string>, mimeType: string, usage?: array}|array{error: string}
     */
    public function generateImages(string $prompt, int $count = 1, ?string $userId = null, array $options = []): array;
}
