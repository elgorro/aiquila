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
 *   - Vision is opt-in per deployment: an admin toggle decides whether images
 *     are sent. Where the backend reports capabilities (Ollama, LM Studio) the
 *     settings card says when the toggle disagrees with the model.
 *   - The model list comes from the backend's own API when one is detected —
 *     see fetchModelCatalog() — because `/v1/models` carries ids only.
 *   - `tool_choice` is not sent — Ollama rejects the field.
 *   - Timeouts default much higher; CPU inference is slow.
 *
 * Every field here is admin-scope only and never user-settable: the server makes
 * outbound requests to whatever is configured, with the SSRF guard disabled, so
 * a user-settable endpoint would be an SSRF vector — and user-settable headers
 * on top of it would be a way to post an instance credential wherever the user
 * likes. Secrets go through CredentialService, never app config.
 */
class LocalProvider extends AbstractOpenAiCompatibleProvider implements ModelCatalogInterface {
    private const PROVIDER_ID = 'local';

    /** Fallback model id when the admin has not picked one. */
    public const DEFAULT_MODEL = 'llama3.2';

    public const DEFAULT_MAX_TOKENS = 4096;

    /** Offered as a placeholder only; the admin names whatever their backend loaded. */
    public const DEFAULT_STT_MODEL = 'whisper-1';
    public const DEFAULT_TTS_MODEL = 'tts-1';

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

    /** Which listing answered fetchModelCatalog(); recorded in the catalog. */
    public const SOURCE_LMSTUDIO = 'lmstudio';
    public const SOURCE_LMSTUDIO_V0 = 'lmstudio-v0';
    public const SOURCE_OLLAMA = 'ollama';
    public const SOURCE_OPENAI = 'openai';

    /**
     * Ollama reports capabilities per model only, one `/api/show` each. Past
     * this many pulled models the rest are listed unverified rather than
     * spending a request apiece on a settings page load.
     */
    public const OLLAMA_SHOW_LIMIT = 50;

    /** `/api/tags` lists pulled models, so a missing one has not been pulled. */
    private const OLLAMA_MISSING_HINT = 'Pull it on the Ollama host with `ollama pull {model}`, or pick one from the list.';

    /** Model id of the request in flight, for the runtime error sentences. */
    private string $lastModel = '';

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
                'The model tag as your backend reports it, e.g. llama3.2. Refresh to pull the list from the endpoint. Ollama and LM Studio report what each model can do; models that cannot chat are greyed out.',
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
                'Only enable when the model is multimodal. Ollama and LM Studio report this, and the model field warns when the two disagree; llama.cpp and other backends cannot tell.',
            ),
            ProviderSettingsSchema::checkbox(
                'local_audio_in',
                'local_audio_in',
                'Transcribe audio with this endpoint',
                'Only enable when the backend serves /v1/audio/transcriptions — Speaches, LocalAI and whisper.cpp\'s server do; plain Ollama and LM Studio do not.',
                group: ProviderSettingsSchema::GROUP_ADVANCED,
            ),
            ProviderSettingsSchema::text(
                'local_stt_model',
                'local_stt_model',
                'Transcription model',
                'The speech-to-text model tag as the backend reports it.',
                group: ProviderSettingsSchema::GROUP_ADVANCED,
                placeholder: self::DEFAULT_STT_MODEL,
                visibleIf: ['field' => 'local_audio_in', 'in' => [true]],
            ),
            ProviderSettingsSchema::checkbox(
                'local_audio_out',
                'local_audio_out',
                'Generate speech with this endpoint',
                'Only enable when the backend serves /v1/audio/speech.',
                group: ProviderSettingsSchema::GROUP_ADVANCED,
            ),
            ProviderSettingsSchema::text(
                'local_tts_model',
                'local_tts_model',
                'Speech model',
                'The text-to-speech model tag as the backend reports it.',
                group: ProviderSettingsSchema::GROUP_ADVANCED,
                placeholder: self::DEFAULT_TTS_MODEL,
                visibleIf: ['field' => 'local_audio_out', 'in' => [true]],
            ),
            ProviderSettingsSchema::text(
                'local_tts_voice',
                'local_tts_voice',
                'Speech voice',
                'The voice id the backend offers. Left blank, none is sent and the backend picks its default.',
                group: ProviderSettingsSchema::GROUP_ADVANCED,
                visibleIf: ['field' => 'local_audio_out', 'in' => [true]],
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

    /**
     * Audio is opt-in for the same reason vision is: the OpenAI-compatible
     * dialect these backends share stops at chat completions. Speaches, LocalAI
     * and whisper.cpp's server add the audio routes; Ollama and LM Studio do
     * not, and Nextcloud cannot tell which is listening.
     */
    protected function supportsAudioInput(?string $userId = null): bool {
        return $this->config->getAppValue(self::APP_NAME, 'local_audio_in', 'no') === 'yes';
    }

    protected function supportsAudioOutput(?string $userId = null): bool {
        return $this->config->getAppValue(self::APP_NAME, 'local_audio_out', 'no') === 'yes';
    }

    public function transcribeAudio(mixed $audio, string $mimeType, string $filename = 'audio', ?string $userId = null, array $options = []): array {
        if (!$this->supportsAudioInput($userId)) {
            return ['error' => $this->getLabel() . ' is not configured to transcribe audio. Enable it in the AIquila admin settings.'];
        }
        $parts = [
            ['name' => 'model', 'contents' => $this->config->getAppValue(self::APP_NAME, 'local_stt_model', self::DEFAULT_STT_MODEL)],
            ['name' => 'file', 'contents' => $audio, 'filename' => $filename],
        ];
        $language = $options['language'] ?? '';
        if (is_string($language) && $language !== '') {
            $parts[] = ['name' => 'language', 'contents' => $language];
        }
        // The OpenAI shape: segment timings only come with verbose_json.
        $timestamps = ($options['timestamps'] ?? false) === true;
        if ($timestamps) {
            $parts[] = ['name' => 'response_format', 'contents' => 'verbose_json'];
            $parts[] = ['name' => 'timestamp_granularities[]', 'contents' => 'segment'];
        }

        try {
            $response = $this->postMultipart('/audio/transcriptions', $parts, $userId, TranscriptionProgress::from($options)->requestOptions());
            $decoded = json_decode((string)$response->getBody(), true);
            if (!is_array($decoded) || !isset($decoded['text']) || !is_string($decoded['text'])) {
                return ['error' => $this->getLabel() . ' returned no transcript.'];
            }
            if ($timestamps) {
                return ['response' => $decoded['text'], 'segments' => TranscriptSegments::parse($decoded['segments'] ?? null)];
            }
            return ['response' => $decoded['text']];
        } catch (\Throwable $e) {
            $this->logger->error('AIquila Local: transcription failed', ['error' => $e->getMessage()]);
            return ['error' => $this->errorMessage($e)];
        }
    }

    public function synthesizeSpeech(string $text, ?string $userId = null, array $options = []): array {
        if (!$this->supportsAudioOutput($userId)) {
            return ['error' => $this->getLabel() . ' is not configured to generate speech. Enable it in the AIquila admin settings.'];
        }
        $body = [
            'model' => $this->config->getAppValue(self::APP_NAME, 'local_tts_model', self::DEFAULT_TTS_MODEL),
            'input' => $text,
            'response_format' => 'mp3',
        ];
        $voice = $options['voice'] ?? $this->config->getAppValue(self::APP_NAME, 'local_tts_voice', '');
        if (is_string($voice) && $voice !== '') {
            $body['voice'] = $voice;
        }

        try {
            $response = $this->postJsonTo('/audio/speech', $body, $userId);
            $audio = (string)$response->getBody();
            if ($audio === '') {
                return ['error' => $this->getLabel() . ' returned no audio.'];
            }
            return ['audio' => $audio, 'mimeType' => 'audio/mpeg'];
        } catch (\Throwable $e) {
            $this->logger->error('AIquila Local: speech synthesis failed', ['error' => $e->getMessage()]);
            return ['error' => $this->errorMessage($e)];
        }
    }

    /** Remember the model, so a failure can name it in errorMessage(). */
    protected function buildBody(array $messages, ?string $system, ?string $userId, array $options, bool $stream = false): array {
        $body = parent::buildBody($messages, $system, $userId, $options, $stream);
        $this->lastModel = is_string($body['model'] ?? null) ? $body['model'] : '';
        return $body;
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

    // ── Model catalog ───────────────────────────────────────────────────────

    /**
     * The ids from the catalog, so the picker and the plain list agree.
     * Incompatible models are included: the picker shows them disabled with
     * the reason, which beats a model that silently isn't there.
     */
    public function listModels(?string $userId = null): ?array {
        if (!$this->isConfigured($userId)) {
            return null;
        }
        $catalog = $this->fetchModelCatalog($userId);
        if ($catalog->isFailure()) {
            $this->logger->warning('AIquila ' . $this->getLabel() . ': Could not list models', ['error' => $catalog->error]);
            return null;
        }
        $ids = $catalog->ids();
        return $ids !== [] ? $ids : null;
    }

    /**
     * Live model list with capabilities, from the first listing that answers
     * in its documented shape:
     *
     *   1. LM Studio's native REST API, `GET /api/v1/models` (LM Studio 0.4+):
     *      `type` llm / embedding, `capabilities.vision`,
     *      `capabilities.trained_for_tool_use`, `loaded_instances`.
     *   2. Its predecessor `GET /api/v0/models` (LM Studio 0.3.6+): `type`
     *      llm / vlm / embeddings, `state`, and on recent builds a
     *      `capabilities` list with `tool_use`.
     *   3. Ollama, `GET /api/tags` then `POST /api/show` per model, whose
     *      `capabilities` list holds completion, tools, vision, embedding, …
     *   4. Anything else (llama.cpp, vLLM, LocalAI): the OpenAI-compatible
     *      `GET /v1/models`, ids only, every model "not verified".
     *
     * Detection is by response shape, not status: LM Studio answers an
     * endpoint it does not know with 200 and `{"error": "Unexpected
     * endpoint…"}`. A transport failure (refused, unresolvable, TLS) stops
     * the walk at once — every later step would fail the same way, three
     * timeouts later.
     */
    public function fetchModelCatalog(?string $userId = null): ModelCatalog {
        if (!$this->isConfigured($userId)) {
            return ModelCatalog::failed($this->getLabel(), $this->notConfiguredMessage());
        }
        $root = substr($this->apiBase(), 0, -strlen('/v1'));

        try {
            $data = $this->nativeGet($root . '/api/v1/models', $userId);
            if (is_array($data) && self::isListOf($data['models'] ?? null, 'key')) {
                return $this->lmStudioCatalog($data['models']);
            }
            $data = $this->nativeGet($root . '/api/v0/models', $userId);
            if (is_array($data) && self::isListOf($data['data'] ?? null, 'type')) {
                return $this->lmStudioV0Catalog($data['data']);
            }
            $data = $this->nativeGet($root . '/api/tags', $userId);
            if (is_array($data) && self::isListOf($data['models'] ?? null, 'name')) {
                return $this->ollamaCatalog($root, $data['models'], $userId);
            }
            return $this->openAiCatalog($this->fetchModelIds($userId));
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila ' . $this->getLabel() . ': Could not list models', ['error' => $e->getMessage()]);
            return ModelCatalog::failed($this->getLabel(), $this->errorMessage($e));
        }
    }

    /**
     * GET a backend-native listing. An HTTP error status means "not this
     * backend" and yields null; a failure without a response is rethrown.
     *
     * @return array<array-key, mixed>|null
     */
    private function nativeGet(string $url, ?string $userId): ?array {
        try {
            $response = $this->clientService->newClient()->get($url, $this->requestOptions($userId, ['timeout' => 15]));
        } catch (\Throwable $e) {
            if (self::isHttpStatusError($e)) {
                return null;
            }
            throw $e;
        }
        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Guzzle's BadResponseException carries the HTTP status as its code; a
     * connect or TLS failure carries 0 (or a cURL number below 100).
     */
    private static function isHttpStatusError(\Throwable $e): bool {
        $code = $e->getCode();
        return $code >= 400 && $code < 600;
    }

    /** A non-empty list whose first entry is an object carrying $key. */
    private static function isListOf(mixed $value, string $key): bool {
        return is_array($value) && array_is_list($value) && $value !== []
            && is_array($value[0]) && isset($value[0][$key]);
    }

    /** @param array<array-key, mixed> $entries */
    private function lmStudioCatalog(array $entries): ModelCatalog {
        $models = [];
        foreach ($entries as $m) {
            if (!is_array($m) || !is_string($m['key'] ?? null) || $m['key'] === '') {
                continue;
            }
            $id = $m['key'];
            $label = is_string($m['display_name'] ?? null) ? $m['display_name'] : '';
            $type = is_string($m['type'] ?? null) ? $m['type'] : '';
            $loaded = is_array($m['loaded_instances'] ?? null) ? $m['loaded_instances'] !== [] : null;
            $caps = is_array($m['capabilities'] ?? null) ? $m['capabilities'] : [];

            $models[] = match ($type) {
                'llm' => $this->lmStudioChat(
                    $id,
                    $label,
                    is_bool($caps['trained_for_tool_use'] ?? null) ? $caps['trained_for_tool_use'] : null,
                    is_bool($caps['vision'] ?? null) ? $caps['vision'] : null,
                    $loaded,
                ),
                'embedding' => ModelCompatibility::embedding($id, $label, '', $loaded),
                default => ModelCompatibility::cannotChat($id, $label, $type !== '' ? 'a "' . $type . '" model' : ''),
            };
        }
        return ModelCatalog::listed($models, self::SOURCE_LMSTUDIO, $this->getLabel(), self::lmStudioMissingHint());
    }

    /**
     * The v0 listing. `vlm` is how it says "vision"; tool use is only on
     * builds that add a `capabilities` list, and unknown otherwise.
     *
     * @param array<array-key, mixed> $entries
     */
    private function lmStudioV0Catalog(array $entries): ModelCatalog {
        $models = [];
        foreach ($entries as $m) {
            if (!is_array($m) || !is_string($m['id'] ?? null) || $m['id'] === '') {
                continue;
            }
            $id = $m['id'];
            $type = is_string($m['type'] ?? null) ? $m['type'] : '';
            $loaded = is_string($m['state'] ?? null) ? $m['state'] === 'loaded' : null;
            $tools = is_array($m['capabilities'] ?? null) ? in_array('tool_use', $m['capabilities'], true) : null;
            $vision = $type === 'vlm';

            $models[] = match ($type) {
                'llm', 'vlm' => $this->lmStudioChat($id, '', $tools, $vision, $loaded),
                'embeddings' => ModelCompatibility::embedding($id, '', '', $loaded),
                default => ModelCompatibility::cannotChat($id, '', $type !== '' ? 'a "' . $type . '" model' : ''),
            };
        }
        return ModelCatalog::listed($models, self::SOURCE_LMSTUDIO_V0, $this->getLabel(), self::lmStudioMissingHint());
    }

    /**
     * LM Studio still offers tools to a model not trained for them, in a
     * generic prompt format, so "no tools" there means "unreliable" rather
     * than "refused".
     *
     * A model that is not loaded gets a note, because what happens next
     * depends on a server setting AIquila cannot read: with just-in-time
     * loading (the default) LM Studio loads it on first use; with it off,
     * LM Studio answers with whichever model *is* loaded, under that model's
     * name, without an error.
     */
    private function lmStudioChat(string $id, string $label, ?bool $tools, ?bool $vision, ?bool $loaded): ModelCompatibility {
        $name = $label !== '' && $label !== $id ? $label . ' (' . $id . ')' : $id;
        return ModelCompatibility::chat(
            $id,
            $label,
            $tools,
            $vision,
            $loaded,
            $loaded === false
                ? 'It isn\'t loaded in LM Studio yet. With just-in-time loading on (the default) LM Studio loads it on the first request; with it off, LM Studio answers with whichever model is loaded instead, so load this one first.'
                : '',
            sprintf('%s isn\'t trained for tool use. LM Studio still offers it tools in a generic format, so Nextcloud tools and Cowork may work poorly with it.', $name),
        );
    }

    private static function lmStudioMissingHint(): string {
        return 'Download it in LM Studio (or with `lms get`), or pick one from the list. LM Studio does not reject an unknown model: it answers with whichever model is loaded.';
    }

    /**
     * Ollama lists pulled models in `/api/tags` without capabilities; those
     * come from `/api/show`, one request per model. A model whose show call
     * fails, or that predates the `capabilities` field, is listed unverified
     * rather than dropped.
     *
     * Embedding-only models have `embedding` and no `completion`; a model with
     * neither cannot chat for some other reason (an image generator, say).
     *
     * @param array<array-key, mixed> $entries
     */
    private function ollamaCatalog(string $root, array $entries, ?string $userId): ModelCatalog {
        $models = [];
        $shown = 0;
        foreach ($entries as $m) {
            if (!is_array($m) || !is_string($m['name'] ?? null) || $m['name'] === '') {
                continue;
            }
            $id = $m['name'];
            $caps = null;
            if ($shown < self::OLLAMA_SHOW_LIMIT) {
                $shown++;
                $caps = $this->ollamaCapabilities($root, $id, $userId);
            }

            if ($caps === null) {
                $models[] = ModelCompatibility::unverified($id, '', 'Ollama did not report its capabilities. Updating Ollama adds them.');
                continue;
            }
            if (!in_array('completion', $caps, true)) {
                $models[] = in_array('embedding', $caps, true)
                    ? ModelCompatibility::embedding($id)
                    : ModelCompatibility::cannotChat($id, '', $caps !== [] ? 'a model for ' . implode(', ', $caps) : '');
                continue;
            }
            $models[] = ModelCompatibility::chat($id, '', in_array('tools', $caps, true), in_array('vision', $caps, true));
        }
        return ModelCatalog::listed($models, self::SOURCE_OLLAMA, $this->getLabel(), self::OLLAMA_MISSING_HINT);
    }

    /**
     * One model's `capabilities` from `POST /api/show`, or null when the call
     * fails or the field is absent (Ollama releases before it existed).
     *
     * @return list<string>|null
     */
    private function ollamaCapabilities(string $root, string $model, ?string $userId): ?array {
        try {
            $response = $this->clientService->newClient()->post($root . '/api/show', $this->requestOptions($userId, [
                'body' => json_encode(['model' => $model]),
                'timeout' => 15,
            ]));
        } catch (\Throwable $e) {
            $this->logger->info('AIquila ' . $this->getLabel() . ': Ollama /api/show failed for ' . $model, ['error' => $e->getMessage()]);
            return null;
        }
        $decoded = json_decode((string)$response->getBody(), true);
        $caps = is_array($decoded) ? ($decoded['capabilities'] ?? null) : null;
        if (!is_array($caps)) {
            return null;
        }
        return array_values(array_filter($caps, 'is_string'));
    }

    /** @param list<string> $ids */
    private function openAiCatalog(array $ids): ModelCatalog {
        $why = 'this endpoint only offers the OpenAI-compatible /v1/models list (llama.cpp, vLLM and similar), which does not say what a model can do. Vision follows the "Send images" setting, and tool calling is assumed.';
        return ModelCatalog::listed(
            array_map(static fn(string $id) => ModelCompatibility::unverified($id, '', $why), $ids),
            self::SOURCE_OPENAI,
            $this->getLabel(),
        );
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
        $model = $this->modelSentence($msg);
        if ($model !== '') {
            return $model;
        }
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

    /**
     * Requests refused because of the model, in the same sentences the picker
     * uses. The wordings are Ollama's (server/routes.go, server/images.go):
     *   - `"x" does not support tools` (400) — tools sent to a model without them;
     *   - `"x" does not support chat` / `generate` (400) — no completion capability,
     *     typically an embedding model;
     *   - `model "x" not found, try pulling it first` (404).
     * LM Studio has no equivalent: it answers an unknown or embedding-only model
     * with whichever model is loaded, so only the picker can catch that.
     *
     * The model named is the one this request asked for; the id in Ollama's
     * text carries a registry prefix and is used only when that is unknown.
     */
    private function modelSentence(string $msg): string {
        $model = $this->lastModel;
        if ($model === '' && preg_match('/\\\\?"([^"\\\\]+)\\\\?"\s+does not support|model\s+\\\\?"([^"\\\\]+)\\\\?"\s+not found/i', $msg, $m) === 1) {
            $model = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        }
        if ($model === '') {
            $model = $this->getModel();
        }
        if (stripos($msg, 'does not support tools') !== false) {
            return ModelCompatibility::toolsRefusedMessage($model);
        }
        if (stripos($msg, 'does not support chat') !== false || stripos($msg, 'does not support generate') !== false) {
            return ModelCompatibility::cannotChatMessage($model);
        }
        if (stripos($msg, 'try pulling it first') !== false) {
            return ModelCompatibility::missingMessage($model, $this->getLabel(), str_replace('{model}', $model, self::OLLAMA_MISSING_HINT));
        }
        return '';
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
