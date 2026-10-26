<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use Anthropic\Core\BaseClient;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Claude through a cloud platform: the same Messages request shape, thinking,
 * effort, tool loop, streaming and caching as the Claude API — all inherited
 * from ClaudeSDKService — but with the platform's SDK client, its auth, and
 * only the platform features it serves (see platformFeatures()).
 *
 * ## Credentials are instance-scope only
 *
 * A cloud account is an organisation's billing and IAM boundary, so unlike a
 * personal Anthropic key there is no per-user credential: every field here is
 * SCOPE_ADMIN, getApiKey() ignores the user, and a user's personal Anthropic
 * key never reaches a cloud endpoint. Endpoint-determining fields (region,
 * location, project, resource, workspace) are validated by CloudEndpoints on
 * write and again on use.
 *
 * ## Config keys
 *
 * Every setting is namespaced with the provider id (`model_bedrock`,
 * `effort_vertex`, …) through configKey(), so two platforms can be configured
 * side by side and neither touches the Claude API card's settings.
 */
abstract class AbstractCloudClaudeProvider extends ClaudeSDKService {
    /** Bearer key issued by the platform (Bedrock API key, Foundry key, Claude Platform on AWS key). */
    public const AUTH_API_KEY = 'api_key';
    /** AWS access key ID + secret (+ session token), signed with SigV4. */
    public const AUTH_ACCESS_KEYS = 'access_keys';
    /** AWS default credential chain: env, shared profile, ECS task role, EC2 instance role. */
    public const AUTH_DEFAULT_CHAIN = 'default_chain';
    /** A short-lived bearer token pasted in by the admin (Google access token, Entra ID token). */
    public const AUTH_ACCESS_TOKEN = 'access_token';
    /** Google service account key JSON. */
    public const AUTH_SERVICE_ACCOUNT = 'service_account';
    /** Google Application Default Credentials. */
    public const AUTH_ADC = 'adc';

    /** Settings inherited from the Claude API card, re-keyed per provider. */
    private const INHERITED_FIELDS = ['effort', 'thinking', 'thinking_budget', 'task_effort', 'task_thinking', 'max_tokens'];

    private ?BaseClient $client = null;

    public function __construct(
        IConfig $config,
        LoggerInterface $logger,
        CredentialService $credentials,
        ICacheFactory $cacheFactory,
        RequestMetadataService $requestMetadata,
        protected readonly CloudSdk $sdk,
    ) {
        parent::__construct($config, $logger, $credentials, $cacheFactory, $requestMetadata);
    }

    // ── What each platform supplies ─────────────────────────────────────────

    /**
     * Auth modes this platform accepts, the default first.
     *
     * @return list<string>
     */
    abstract protected function authModes(): array;

    /**
     * The endpoint fields (region, project, …), all SCOPE_ADMIN.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function endpointFields(): array;

    /** Whether every required endpoint field holds a valid value. */
    abstract protected function endpointConfigured(): bool;

    /**
     * Build the platform's SDK client for the given auth mode. Called once per
     * provider instance; the client caches its own tokens and signatures.
     *
     * @throws \RuntimeException|\InvalidArgumentException on a missing or invalid setting
     */
    abstract protected function buildClient(string $authMode): BaseClient;

    /** Which optional SDK an auth mode needs, or null. */
    protected function requiredSdk(string $authMode): ?string {
        return match ($authMode) {
            self::AUTH_ACCESS_KEYS, self::AUTH_DEFAULT_CHAIN => CloudSdk::AWS,
            self::AUTH_SERVICE_ACCOUNT, self::AUTH_ADC => CloudSdk::GOOGLE,
            default => null,
        };
    }

    /** Hint under the model field: what an ID looks like on this platform. */
    abstract protected function modelDescription(): string;

    /** Hint under the API key field. */
    protected function apiKeyDescription(): string {
        return 'Stored encrypted in Nextcloud\'s credential manager.';
    }

    /**
     * Messages, tools, vision, documents, thinking, effort, citations and
     * prompt caching are available everywhere. What is left are the extras,
     * which a platform opts into; the safe default is the Messages API alone.
     *
     * @return array<string, bool>
     */
    protected function platformFeatures(): array {
        return [
            self::FEATURE_FILES_API => false,
            self::FEATURE_BATCHES => false,
            self::FEATURE_MODELS_API => false,
            self::FEATURE_NATIVE_MCP => false,
            self::FEATURE_FAST_MODE => false,
            self::FEATURE_EXTENDED_BATCH_OUTPUT => false,
            self::FEATURE_SERVICE_TIER => false,
            self::FEATURE_METADATA => false,
            self::FEATURE_AUTO_CACHE => true,
        ];
    }

    // ── Shared plumbing ─────────────────────────────────────────────────────

    protected function configKey(string $key): string {
        return $key . '_' . $this->getId();
    }

    /** Name of a credential-manager secret owned by this provider. */
    protected function secretName(string $name): string {
        return $this->getId() . '_' . $name;
    }

    protected function secret(string $name): string {
        return $this->credentials->getSecret($this->secretName($name));
    }

    /**
     * A secret the current auth mode cannot do without.
     *
     * @return non-empty-string
     */
    protected function requiredSecret(string $name): string {
        $value = $this->secret($name);
        if ($value === '') {
            throw new \RuntimeException($this->unconfiguredMessage());
        }
        return $value;
    }

    /** @return non-empty-string */
    protected function requiredApiKey(): string {
        $key = $this->getApiKey();
        if ($key === '') {
            throw new \RuntimeException($this->unconfiguredMessage());
        }
        return $key;
    }

    public function authMode(): string {
        $modes = $this->authModes();
        $mode = $this->appValue('auth_mode', $modes[0]);
        return in_array($mode, $modes, true) ? $mode : $modes[0];
    }

    /** Instance key only — a personal Anthropic key must never reach a cloud endpoint. */
    public function getApiKey(?string $userId = null): string {
        return $this->credentials->getApiKey(null, $this->getId());
    }

    public function isConfigured(?string $userId = null): bool {
        if (!$this->endpointConfigured()) {
            return false;
        }
        return match ($this->authMode()) {
            self::AUTH_API_KEY => $this->getApiKey() !== '',
            self::AUTH_ACCESS_KEYS => $this->appValue('aws_access_key_id') !== '' && $this->secret('aws_secret_access_key') !== '',
            self::AUTH_ACCESS_TOKEN => $this->secret('access_token') !== '',
            self::AUTH_SERVICE_ACCOUNT => $this->secret('service_account') !== '',
            // Ambient credentials: only a request can tell whether they resolve.
            self::AUTH_DEFAULT_CHAIN, self::AUTH_ADC => true,
            default => false,
        };
    }

    protected function unconfiguredMessage(): string {
        return $this->endpointConfigured()
            ? sprintf('No credentials configured for %s', $this->getLabel())
            : sprintf('%s needs its endpoint settings first', $this->getLabel());
    }

    protected function validateModel(string $model): string {
        return CloudEndpoints::requireModelId($model);
    }

    protected function getClient(?string $userId = null): BaseClient {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->isConfigured()) {
            throw new \RuntimeException($this->unconfiguredMessage());
        }
        $mode = $this->authMode();
        $sdk = $this->requiredSdk($mode);
        if ($sdk !== null) {
            $this->sdk->require($sdk, $this->getLabel() . ' with "' . $mode . '" authentication');
        }
        return $this->client = $this->buildClient($mode);
    }

    /**
     * A stored endpoint setting, validated. Throws on a value the settings API
     * would have refused, so a bad occ write cannot redirect a request.
     *
     * @param callable(string): non-empty-string $validator one of the CloudEndpoints::require*() checks
     * @return non-empty-string
     * @throws \InvalidArgumentException
     */
    protected function endpointValue(string $key, callable $validator, string $default = ''): string {
        return $validator($this->appValue($key, $default));
    }

    /** @param callable(string): non-empty-string $validator */
    protected function hasValidEndpointValue(string $key, callable $validator, string $default = ''): bool {
        try {
            $this->endpointValue($key, $validator, $default);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /** Admin-only text field for an endpoint part, validated with $format. */
    protected function endpointField(string $id, string $title, string $description, string $format, string $placeholder, string $default = ''): array {
        return ProviderSettingsSchema::text(
            $id,
            $this->configKey($id),
            $title,
            $description,
            default: $default,
            group: ProviderSettingsSchema::GROUP_BASIC,
            placeholder: $placeholder,
            format: $format,
        );
    }

    /**
     * A blank optional secret becomes null, which the SDK reads as "not given".
     *
     * @return non-empty-string|null
     */
    protected function optionalSecret(string $name): ?string {
        $value = $this->secret($name);
        return $value === '' ? null : $value;
    }

    // ── Settings card ───────────────────────────────────────────────────────

    public function getSettingsSchema(): array {
        $modes = $this->authModes();
        $fields = $this->endpointFields();

        $fields[] = ProviderSettingsSchema::select(
            'auth_mode',
            $this->configKey('auth_mode'),
            'Authentication',
            $this->authModeDescription(),
            $modes,
            default: $modes[0],
            group: ProviderSettingsSchema::GROUP_BASIC,
        );
        foreach ($this->credentialFields($modes) as $field) {
            $fields[] = $field;
        }

        $fields[] = ProviderSettingsSchema::text(
            'model',
            $this->configKey('model'),
            'Model',
            $this->modelDescription(),
            default: $this->defaultModel(),
            group: ProviderSettingsSchema::GROUP_BASIC,
            placeholder: $this->defaultModel(),
            format: ProviderSettingsSchema::FORMAT_MODEL_ID,
        );

        // Thinking, effort and the token cap mean exactly what they mean on the
        // Claude API card, so they are taken from there and only re-keyed.
        foreach (parent::getSettingsSchema() as $field) {
            if (!in_array($field['id'] ?? null, self::INHERITED_FIELDS, true)) {
                continue;
            }
            if (isset($field['key'])) {
                $field['key'] = $this->configKey((string)$field['key']);
            }
            if (isset($field['user_key'])) {
                $field['user_key'] = $this->configKey((string)$field['user_key']);
            }
            $fields[] = $field;
        }

        return $fields;
    }

    private function authModeDescription(): string {
        $needs = [];
        foreach ($this->authModes() as $mode) {
            $sdk = $this->requiredSdk($mode);
            if ($sdk !== null) {
                $needs[$sdk][] = $mode;
            }
        }
        $parts = [];
        foreach ($needs as $sdk => $modes) {
            $parts[] = sprintf(
                '"%s" %s the %s package (%s).',
                implode('", "', $modes),
                count($modes) === 1 ? 'needs' : 'need',
                $sdk,
                $this->sdk->isAvailable($sdk) ? 'installed' : 'not installed — see the AIquila docs',
            );
        }
        return trim('How Nextcloud authenticates to ' . $this->getLabel() . '. ' . implode(' ', $parts));
    }

    /**
     * @param list<string> $modes
     * @return list<array<string, mixed>>
     */
    private function credentialFields(array $modes): array {
        $fields = [];
        $when = static fn (string ...$m): array => ['field' => 'auth_mode', 'in' => $m];

        if (in_array(self::AUTH_API_KEY, $modes, true)) {
            // The per-provider key slot, but admin-only: see the class docblock.
            $field = ProviderSettingsSchema::apiKey('API key', $this->apiKeyDescription());
            $field['scope'] = ProviderSettingsSchema::SCOPE_ADMIN;
            $field['visible_if'] = $when(self::AUTH_API_KEY);
            $fields[] = $field;
        }
        if (in_array(self::AUTH_ACCESS_KEYS, $modes, true)) {
            $fields[] = ProviderSettingsSchema::text(
                'aws_access_key_id',
                $this->configKey('aws_access_key_id'),
                'AWS access key ID',
                'Of an IAM user or role scoped to the Claude models you use.',
                group: ProviderSettingsSchema::GROUP_BASIC,
                visibleIf: $when(self::AUTH_ACCESS_KEYS),
            );
            $fields[] = ['group' => ProviderSettingsSchema::GROUP_BASIC] + ProviderSettingsSchema::secret(
                'aws_secret_access_key',
                $this->secretName('aws_secret_access_key'),
                'AWS secret access key',
                'Stored encrypted in Nextcloud\'s credential manager.',
                $when(self::AUTH_ACCESS_KEYS),
            );
            $fields[] = ProviderSettingsSchema::secret(
                'aws_session_token',
                $this->secretName('aws_session_token'),
                'AWS session token (optional)',
                'Only for temporary credentials. They expire, so prefer the default credential chain with a role for anything long-lived.',
                $when(self::AUTH_ACCESS_KEYS),
            );
        }
        if (in_array(self::AUTH_ACCESS_TOKEN, $modes, true)) {
            $fields[] = ['group' => ProviderSettingsSchema::GROUP_BASIC] + ProviderSettingsSchema::secret(
                'access_token',
                $this->secretName('access_token'),
                'Access token',
                'A bearer token as issued by the platform. These typically expire after an hour, so this mode suits testing; use a service account or ambient credentials in production.',
                $when(self::AUTH_ACCESS_TOKEN),
            );
        }
        if (in_array(self::AUTH_SERVICE_ACCOUNT, $modes, true)) {
            $field = ProviderSettingsSchema::secretTextarea(
                'service_account',
                $this->secretName('service_account'),
                'Service account key (JSON)',
                'The key file of a service account with the role this platform requires. Stored encrypted in Nextcloud\'s credential manager.',
                format: ProviderSettingsSchema::FORMAT_JSON_OBJECT,
                visibleIf: $when(self::AUTH_SERVICE_ACCOUNT),
            );
            $field['group'] = ProviderSettingsSchema::GROUP_BASIC;
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * Decoded service account key, as google/auth expects it.
     *
     * @return array<string, mixed>
     */
    protected function serviceAccountKey(): array {
        $json = json_decode($this->secret('service_account'), true);
        if (!is_array($json) || ($json['type'] ?? null) !== 'service_account') {
            throw new \RuntimeException('The stored service account key is not a service_account JSON key.');
        }
        return $json;
    }

    /** Google credentials for the service account key, with the cloud-platform scope. */
    protected function googleServiceAccountCredentials(): \Google\Auth\FetchAuthTokenInterface {
        return new \Google\Auth\Credentials\ServiceAccountCredentials(
            'https://www.googleapis.com/auth/cloud-platform',
            $this->serviceAccountKey(),
        );
    }

    public function getConfiguration(): array {
        return [
            'api_key' => '',
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'timeout' => (int)$this->config->getAppValue($this->appName, 'api_timeout', '30'),
        ];
    }
}
