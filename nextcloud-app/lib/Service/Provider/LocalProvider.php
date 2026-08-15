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
 *   - The API key is optional, and how it is sent is configurable. Ollama has no
 *     auth at all; LM Studio and `llama-server --api-key` use a bearer token;
 *     a reverse proxy in front of any of them may want HTTP Basic or a header
 *     of its own, plus static extras such as Cloudflare Access. When no key is
 *     stored, no credential header is sent at all rather than an empty one.
 *   - TLS is configurable too: a private CA bundle, a client certificate for
 *     mTLS, and an explicit opt-out of verification, since an internal endpoint
 *     is often published with a certificate no public bundle trusts.
 *   - Requests may target a local/private address (localhost, the Docker
 *     network, a LAN box), which Nextcloud's HTTP client blocks by default.
 *   - Vision is opt-in per deployment: we cannot know whether the loaded model
 *     is multimodal, so an admin toggle decides whether images are sent.
 *   - `tool_choice` is not sent — Ollama rejects the field.
 *   - Timeouts default much higher; CPU inference is slow.
 *
 * Every field here is admin-scope only and never user-settable: the server makes
 * outbound requests to whatever is configured, with the SSRF guard disabled, so
 * a user-settable endpoint would be an SSRF vector — and user-settable headers
 * on top of it would be a way to post an instance credential wherever the user
 * likes. Secrets go through CredentialService, never IConfig.
 */
class LocalProvider extends AbstractOpenAiCompatibleProvider {
    private const PROVIDER_ID = 'local';

    /** Fallback model id when the admin has not picked one. */
    public const DEFAULT_MODEL = 'llama3.2';

    public const DEFAULT_MAX_TOKENS = 4096;

    /** Local inference is slow; well above the 30s shared `api_timeout`. */
    public const DEFAULT_TIMEOUT = 300;

    /** No auth at all — a bare Ollama on the same host. */
    public const AUTH_NONE = 'none';
    /** `Authorization: Bearer <key>`; what every install had before #446. */
    public const AUTH_BEARER = 'bearer';
    /** `Authorization: Basic base64(user:key)` — nginx auth_basic and friends. */
    public const AUTH_BASIC = 'basic';
    /** A custom header carrying the key, e.g. `X-API-Key`. */
    public const AUTH_HEADER = 'header';

    /** @var list<string> */
    private const AUTH_MODES = [self::AUTH_NONE, self::AUTH_BEARER, self::AUTH_BASIC, self::AUTH_HEADER];

    /** Credential-store names for the two secrets that are not the API key. */
    private const SECRET_EXTRA_HEADERS = 'local_extra_headers';
    private const SECRET_CLIENT_KEY_PASSWORD = 'local_client_key_password';

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

            // ── Auth scheme ────────────────────────────────────────────────
            ProviderSettingsSchema::select(
                'local_auth_mode',
                'local_auth_mode',
                'Authentication',
                'How the key above is sent. Bearer suits LM Studio and llama-server; pick Basic or a custom header when the endpoint sits behind a reverse proxy.',
                self::AUTH_MODES,
                default: self::AUTH_BEARER,
                group: ProviderSettingsSchema::GROUP_BASIC,
            ),
            ProviderSettingsSchema::text(
                'local_auth_user',
                'local_auth_user',
                'Basic auth username',
                'The key above is used as the password.',
                group: ProviderSettingsSchema::GROUP_BASIC,
                placeholder: 'nextcloud',
                visibleIf: ['field' => 'local_auth_mode', 'in' => [self::AUTH_BASIC]],
            ),
            ProviderSettingsSchema::text(
                'local_auth_header',
                'local_auth_header',
                'Header name',
                'The header the key is sent in, e.g. X-API-Key or Api-Key. No Authorization header is sent in this mode.',
                group: ProviderSettingsSchema::GROUP_BASIC,
                placeholder: 'X-API-Key',
                format: ProviderSettingsSchema::FORMAT_HEADER_NAME,
                visibleIf: ['field' => 'local_auth_mode', 'in' => [self::AUTH_HEADER]],
            ),
            ProviderSettingsSchema::secretTextarea(
                'local_extra_headers',
                self::SECRET_EXTRA_HEADERS,
                'Additional request headers',
                'One "Name: value" per line, for Cloudflare Access or gateway routing headers. Stored encrypted. Content-Type, Authorization and hop-by-hop headers cannot be set here.',
                placeholder: "CF-Access-Client-Id: …\nCF-Access-Client-Secret: …",
                format: ProviderSettingsSchema::FORMAT_HEADERS,
            ),

            // ── TLS ────────────────────────────────────────────────────────
            ProviderSettingsSchema::checkbox(
                'local_tls_verify',
                'local_tls_verify',
                'Verify the endpoint\'s TLS certificate',
                'Leave on. Turning it off accepts any certificate for this endpoint, including one presented by an attacker on the path.',
                default: true,
            ),
            ProviderSettingsSchema::text(
                'local_ca_bundle',
                'local_ca_bundle',
                'CA bundle path',
                'Absolute path to a PEM bundle for a private CA, readable by the web server. Alternatively import the CA instance-wide with occ security:certificates:import.',
                placeholder: '/etc/ssl/certs/internal-ca.pem',
                format: ProviderSettingsSchema::FORMAT_FILE_PATH,
                visibleIf: ['field' => 'local_tls_verify', 'in' => [true]],
            ),
            ProviderSettingsSchema::text(
                'local_client_cert',
                'local_client_cert',
                'Client certificate path',
                'Absolute path to a PEM client certificate, for an endpoint requiring mTLS.',
                placeholder: '/etc/ssl/certs/aiquila-client.pem',
                format: ProviderSettingsSchema::FORMAT_FILE_PATH,
            ),
            ProviderSettingsSchema::text(
                'local_client_key',
                'local_client_key',
                'Client key path',
                'Absolute path to the matching private key. Omit when the key is inside the certificate file.',
                placeholder: '/etc/ssl/private/aiquila-client.key',
                format: ProviderSettingsSchema::FORMAT_FILE_PATH,
            ),
            ProviderSettingsSchema::secret(
                'local_client_key_password',
                self::SECRET_CLIENT_KEY_PASSWORD,
                'Client key passphrase',
                'Only needed when the private key is encrypted. Stored encrypted.',
            ),
        ];
    }

    /** One of the AUTH_* constants; anything unrecognised falls back to bearer. */
    private function authMode(): string {
        $mode = $this->config->getAppValue(self::APP_NAME, 'local_auth_mode', self::AUTH_BEARER);
        return in_array($mode, self::AUTH_MODES, true) ? $mode : self::AUTH_BEARER;
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
        return $options + $this->tlsOptions();
    }

    /**
     * TLS options for a self-hosted endpoint: a private CA, a client
     * certificate, or an explicit decision to accept whatever certificate is
     * presented.
     *
     * Nextcloud's HTTP client merges caller options over its own defaults
     * (`array_merge($defaults, $options)` in OC\Http\Client\Client), so
     * `verify` set here really does replace the instance CA bundle — and, when
     * it is not set here, that bundle keeps applying.
     *
     * @return array<string, mixed>
     */
    private function tlsOptions(): array {
        $options = [];

        if ($this->config->getAppValue(self::APP_NAME, 'local_tls_verify', 'yes') !== 'yes') {
            $options['verify'] = false;
        } else {
            $ca = $this->readablePath('local_ca_bundle', 'CA bundle');
            if ($ca !== '') {
                $options['verify'] = $ca;
            }
        }

        $passphrase = $this->credentials->getSecret(self::SECRET_CLIENT_KEY_PASSWORD);

        $cert = $this->readablePath('local_client_cert', 'client certificate');
        if ($cert !== '') {
            $options['cert'] = $passphrase !== '' ? [$cert, $passphrase] : $cert;
        }

        $key = $this->readablePath('local_client_key', 'client key');
        if ($key !== '') {
            $options['ssl_key'] = $passphrase !== '' ? [$key, $passphrase] : $key;
        }

        return $options;
    }

    /**
     * A configured certificate path, checked again at request time.
     *
     * The path was verified when it was saved, but a container rebuild or a
     * rotated mount can take the file away afterwards. Failing here names the
     * file and the setting; letting it through would surface as an opaque
     * "cURL error 58" instead.
     */
    private function readablePath(string $configKey, string $label): string {
        $path = trim($this->config->getAppValue(self::APP_NAME, $configKey, ''));
        if ($path === '') {
            return '';
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                'The configured ' . $label . ' "' . $path . '" is missing or unreadable. '
                . 'Check the AIquila admin settings for the local model provider.'
            );
        }
        return $path;
    }

    /**
     * Auth is optional. An empty key means "no Authorization header" rather than
     * an error, so `requireApiKey()` must not throw here.
     */
    protected function requireApiKey(?string $userId): string {
        return $this->getApiKey($userId);
    }

    /**
     * The stored API key is the secret for every mode — the bearer token, the
     * Basic *password*, or the custom header's value — so switching schemes
     * does not mean re-entering it. An empty key still means "send no auth"
     * rather than an error: that is the whole point of the keyless Ollama case.
     *
     * Extra headers are merged last but cannot override anything above:
     * HeaderSpec forbids Content-Type and Authorization outright, and the
     * custom auth header is re-applied afterwards so a stale stored block
     * cannot quietly replace the configured credential.
     *
     * @return array<string, string>
     */
    protected function headers(string $apiKey): array {
        $headers = ['Content-Type' => 'application/json'] + $this->extraHeaders();

        if ($apiKey === '') {
            return $headers;
        }

        return match ($this->authMode()) {
            self::AUTH_NONE => $headers,
            self::AUTH_BASIC => ['Authorization' => 'Basic ' . base64_encode(
                $this->config->getAppValue(self::APP_NAME, 'local_auth_user', '') . ':' . $apiKey
            )] + $headers,
            self::AUTH_HEADER => $this->customAuthHeader($apiKey) + $headers,
            default => ['Authorization' => 'Bearer ' . $apiKey] + $headers,
        };
    }

    /**
     * The custom-header mode with no header name configured sends nothing
     * rather than guessing: a request that silently arrives unauthenticated is
     * easier to diagnose as a 401 than as a key leaked into a header the admin
     * never named.
     *
     * @return array<string, string>
     */
    private function customAuthHeader(string $apiKey): array {
        $name = trim($this->config->getAppValue(self::APP_NAME, 'local_auth_header', ''));
        if ($name === '' || !HeaderSpec::isValidName($name)) {
            $this->logger->warning('AIquila: local provider is set to custom-header auth but has no valid header name; sending no credential', [
                'provider' => self::PROVIDER_ID,
            ]);
            return [];
        }
        return [$name => $apiKey];
    }

    /**
     * The admin's static extra headers. Re-validated on read — a stored value
     * is not trusted input, and a block that no longer parses must not be
     * spliced into an outbound request.
     *
     * @return array<string, string>
     */
    private function extraHeaders(): array {
        $raw = $this->credentials->getSecret(self::SECRET_EXTRA_HEADERS);
        if ($raw === '') {
            return [];
        }
        try {
            return HeaderSpec::parse($raw);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('AIquila: stored extra headers for the local provider are invalid and were dropped: ' . $e->getMessage(), [
                'provider' => self::PROVIDER_ID,
            ]);
            return [];
        }
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    protected function notConfiguredMessage(): string {
        return 'No local model endpoint configured. Set one in the AIquila admin settings.';
    }

    /**
     * A local endpoint fails in more ways than a hosted one — it may be behind a
     * proxy, on a private CA, or simply not running — and "could not reach it"
     * sends an admin to look at the wrong setting in most of them. Each branch
     * below names the setting that fixes it.
     */
    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if ($e instanceof \OCP\Http\Client\LocalServerException) {
            return 'Nextcloud blocked the request to the local model endpoint. Enable "Allow local addresses" in the AIquila admin settings.';
        }
        if ($this->mentions($msg, ProviderProbe::TLS_MARKERS)) {
            return $this->config->getAppValue(self::APP_NAME, 'local_tls_verify', 'yes') === 'yes'
                ? 'TLS failed against the local model endpoint (' . $msg . '). For a private CA, set a CA bundle path in the AIquila admin settings or import the CA with occ security:certificates:import.'
                : 'TLS failed against the local model endpoint even with verification disabled (' . $msg . '). Check the client certificate settings.';
        }
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return $this->unauthorizedMessage();
        }
        if (stripos($msg, '403') !== false || stripos($msg, 'forbidden') !== false) {
            return 'The local model endpoint refused the request (403). If it sits behind a proxy such as Cloudflare Access, check the additional request headers in the AIquila admin settings.';
        }
        if (stripos($msg, '407') !== false || stripos($msg, 'proxy authentication') !== false) {
            return 'An HTTP proxy demanded authentication (407). This is Nextcloud\'s own `proxy` / `proxyuserpwd` system configuration, not an AIquila setting.';
        }
        if (stripos($msg, '404') !== false) {
            return 'The local model endpoint returned 404. Check the base URL and that the model is loaded.';
        }
        if ($this->mentions($msg, ['could not resolve host', 'name or service not known', 'curl error 6'])) {
            return 'The host in the local model endpoint (' . $this->apiBase() . ') could not be resolved. From a container, "localhost" is the container itself — use host.docker.internal or the service name.';
        }
        if ($this->mentions($msg, ['connection refused', 'curl error 7'])) {
            return 'Nothing is listening at the local model endpoint (' . $this->apiBase() . '). Is the backend running, and is the port right?';
        }
        if (stripos($msg, 'connection') !== false || stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
            return 'Could not reach the local model endpoint (' . $this->apiBase() . '). Is the server running and reachable from Nextcloud?';
        }
        return 'Local model error: ' . $msg;
    }

    /**
     * The 401 wording follows the configured scheme: "check the API key" is
     * simply wrong advice when the endpoint wants a username and password, or a
     * header the admin has not named yet.
     */
    private function unauthorizedMessage(): string {
        return match ($this->authMode()) {
            self::AUTH_NONE => 'The local model endpoint requires authentication, but Authentication is set to "none" in the AIquila admin settings.',
            self::AUTH_BASIC => 'The local model endpoint rejected the Basic credentials. Check the username and the key in the AIquila admin settings.',
            self::AUTH_HEADER => 'The local model endpoint rejected the credential sent in "'
                . ($this->config->getAppValue(self::APP_NAME, 'local_auth_header', '') ?: 'the configured header')
                . '". Check the header name and the key in the AIquila admin settings.',
            default => 'The local model endpoint rejected the API key. Check the token in the AIquila admin settings.',
        };
    }

    /** @param list<string> $needles */
    private function mentions(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
