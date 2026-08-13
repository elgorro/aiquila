<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Local / self-hosted model provider.
 *
 * Ollama, LM Studio and llama.cpp's `llama-server` all expose the same
 * OpenAI-compatible surface (`/v1/chat/completions` with SSE streaming and
 * `tools`/`tool_calls`, `/v1/models`), so a single provider with a configurable
 * base URL covers all three — plus vLLM, LocalAI and anything else speaking that
 * dialect. No vendor SDK is involved: neither Ollama nor LM Studio ships a PHP
 * client, and their SDKs only add model management (load/pull), not inference.
 *
 * Differences from the hosted providers:
 *   - The base URL is admin configuration rather than a constant.
 *   - The API key is optional. Ollama has no auth at all; LM Studio and
 *     `llama-server --api-key` use a bearer token. When no key is stored the
 *     Authorization header is omitted entirely rather than sent empty.
 *   - Requests may target a local/private address (localhost, the Docker
 *     network, a LAN box), which Nextcloud's HTTP client blocks by default.
 *   - Vision is opt-in per deployment: we cannot know whether the loaded model
 *     is multimodal, so an admin toggle decides whether images are sent.
 *   - `tool_choice` is not sent — Ollama rejects the field.
 *   - Timeouts default much higher; CPU inference is slow.
 *
 * Base URL and the local-address allowance are admin-scope only and never
 * user-settable: the server makes outbound requests to whatever is configured,
 * so letting users set it would be an SSRF vector.
 */
class LocalProvider extends AbstractOpenAiCompatibleProvider {
    private const PROVIDER_ID = 'local';

    /** Fallback model id when the admin has not picked one. */
    public const DEFAULT_MODEL = 'llama3.2';

    public const DEFAULT_MAX_TOKENS = 4096;

    /** Local inference is slow; well above the 30s shared `api_timeout`. */
    public const DEFAULT_TIMEOUT = 300;

    public function getId(): string {
        return self::PROVIDER_ID;
    }

    public function getLabel(): string {
        return 'Local model';
    }

    /**
     * Configured base URL, normalized to include a `/v1` version segment and no
     * trailing slash. Returns '' when unset or not an http(s) URL.
     */
    protected function apiBase(): string {
        return self::normalizeBaseUrl($this->config->getAppValue(self::APP_NAME, 'local_base_url', ''));
    }

    /**
     * Normalize an admin-entered endpoint into a usable OpenAI base URL:
     * `http://localhost:11434` and `http://localhost:11434/v1/` both become
     * `http://localhost:11434/v1`. Non-http(s) input yields ''.
     */
    public static function normalizeBaseUrl(string $raw): string {
        $url = rtrim(trim($raw), '/');
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        if (parse_url($url, PHP_URL_HOST) === null) {
            return '';
        }
        if (!str_ends_with($url, '/v1')) {
            $url .= '/v1';
        }
        return $url;
    }

    /**
     * Configured, not "has an API key" — most local backends have no auth.
     */
    public function isConfigured(?string $userId = null): bool {
        return $this->apiBase() !== '';
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_local', '');
            if ($userModel !== '') {
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_local', self::DEFAULT_MODEL);
    }

    /**
     * No per-model ceiling table: local model ids are arbitrary tags, so the
     * configured value is used as-is.
     */
    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_local', (string)self::DEFAULT_MAX_TOKENS);
        return $stored > 0 ? $stored : self::DEFAULT_MAX_TOKENS;
    }

    protected function defaultModel(): string {
        return self::DEFAULT_MODEL;
    }

    protected function defaultMaxTokens(): int {
        return self::DEFAULT_MAX_TOKENS;
    }

    /**
     * The local endpoint has no vendor defaults to fall back on, so the base
     * URL is the field that decides whether this provider works at all — hence
     * GROUP_BASIC and no API key requirement. Base URL and the local-address
     * allowance stay admin-scope: see the class docblock (SSRF).
     *
     * The timeout uses the provider's own `local_timeout` key rather than the
     * shared `api_timeout`, because CPU inference needs a much higher ceiling.
     */
    public function getSettingsSchema(): array {
        return [
            ProviderSettingsSchema::baseUrl(
                'local_base_url',
                'Endpoint URL',
                'Ollama: http://localhost:11434 · LM Studio: http://localhost:1234 · llama.cpp: http://localhost:8080. A /v1 segment is appended automatically.',
                'http://localhost:11434',
            ),
            ProviderSettingsSchema::apiKey(
                'API key (optional)',
                'Ollama needs none. LM Studio and `llama-server --api-key` use a bearer token. Left blank, no Authorization header is sent.',
                optional: true,
            ),
            ProviderSettingsSchema::model(
                'model_local',
                'user_model_local',
                self::DEFAULT_MODEL,
                'The model tag as your backend reports it, e.g. llama3.2. Refresh to pull the loaded list from the endpoint.',
            ),
            ProviderSettingsSchema::maxTokens(
                'max_tokens_local',
                self::DEFAULT_MAX_TOKENS,
                'No per-model ceiling is known for local tags, so this value is used as-is.',
            ),
            ProviderSettingsSchema::timeout(
                'local_timeout',
                self::DEFAULT_TIMEOUT,
                'Defaults far above the hosted providers — CPU inference is slow.',
            ),
            ProviderSettingsSchema::checkbox(
                'local_vision',
                'local_vision',
                'Send images to this model',
                'Only enable when the loaded model is multimodal; Nextcloud cannot detect this.',
            ),
            ProviderSettingsSchema::checkbox(
                'local_allow_local_address',
                'local_allow_local_address',
                'Allow local and private addresses',
                'Nextcloud blocks requests to localhost and private ranges by default. Required for virtually every local setup.',
                default: true,
            ),
        ];
    }

    protected function supportsVisionInput(?string $userId = null): bool {
        return $this->config->getAppValue(self::APP_NAME, 'local_vision', 'no') === 'yes';
    }

    /** Ollama does not accept `tool_choice`; the other backends tolerate its absence. */
    protected function sendsToolChoice(): bool {
        return false;
    }

    protected function requestTimeout(): int {
        return $this->configuredTimeout();
    }

    protected function streamTimeout(): int {
        return $this->configuredTimeout();
    }

    private function configuredTimeout(): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'local_timeout', (string)self::DEFAULT_TIMEOUT);
        return $stored > 0 ? $stored : self::DEFAULT_TIMEOUT;
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    /**
     * Add the local-address allowance when enabled. Nextcloud's HTTP client
     * refuses private/loopback targets by default (SSRF protection), which would
     * block every realistic local-model setup.
     */
    protected function requestOptions(?string $userId, array $extra): array {
        $options = parent::requestOptions($userId, $extra);
        if ($this->config->getAppValue(self::APP_NAME, 'local_allow_local_address', 'yes') === 'yes') {
            $options['nextcloud'] = ['allow_local_address' => true];
        }
        return $options;
    }

    /**
     * Auth is optional. An empty key means "no Authorization header" rather than
     * an error, so `requireApiKey()` must not throw here.
     */
    protected function requireApiKey(?string $userId): string {
        return $this->getApiKey($userId);
    }

    /** @return array<string, string> */
    protected function headers(string $apiKey): array {
        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        return $headers;
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    protected function notConfiguredMessage(): string {
        return 'No local model endpoint configured. Set one in the AIquila admin settings.';
    }

    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if ($e instanceof \OCP\Http\Client\LocalServerException) {
            return 'Nextcloud blocked the request to the local model endpoint. Enable "Allow local addresses" in the AIquila admin settings.';
        }
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'The local model endpoint rejected the API key. Check the token in the AIquila admin settings.';
        }
        if (stripos($msg, '404') !== false) {
            return 'The local model endpoint returned 404. Check the base URL and that the model is loaded.';
        }
        if (stripos($msg, 'connection') !== false || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
            return 'Could not reach the local model endpoint (' . $this->apiBase() . '). Is the server running and reachable from Nextcloud?';
        }
        return 'Local model error: ' . $msg;
    }
}
