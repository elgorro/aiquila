<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\CredentialService;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes provider configuration through the descriptors returned by
 * LLMProviderInterface::getSettingsSchema().
 *
 * Everything is driven by the descriptor — the config key, the scope, how a
 * boolean is stored — so the controllers stay generic and key naming stays with
 * the provider that owns it. Adding a provider means adding a schema, nothing
 * else.
 *
 * ## Scope enforcement
 *
 * writeUser() only accepts SCOPE_USER / SCOPE_BOTH fields and silently ignores
 * anything else; an unknown field id is rejected outright. This is the guard
 * that keeps `local_base_url` and `hetzner_base_url` — which decide where the
 * server sends outbound requests — out of reach of the personal settings
 * endpoint. The default scope is admin, so a provider that forgets to declare
 * one fails closed.
 */
class ProviderSettingsService {
    private const APP_NAME = 'aiquila';

    /**
     * Live model lists come from an outbound HTTP call per provider. Rendering a
     * settings page used to make one such call for every registered provider on
     * every load; cache them and let the UI ask for a refresh explicitly.
     */
    private const MODEL_CACHE_TTL = 3600;

    public function __construct(
        private readonly IConfig $config,
        private readonly CredentialService $credentials,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Describe a provider for the settings UI.
     *
     * @param bool $admin true for the admin page (all fields, admin values),
     *                    false for the personal page (user-writable fields only)
     * @return array<string, mixed>
     */
    public function describe(LLMProviderInterface $provider, ?string $userId, bool $admin, bool $refreshModels = false): array {
        $id = $provider->getId();
        $fields = [];
        foreach ($provider->getSettingsSchema() as $field) {
            if (!$admin && !ProviderSettingsSchema::isUserWritable($field)) {
                continue;
            }
            $fields[] = $this->describeField($field, $id, $userId, $admin, $provider, $refreshModels);
        }

        return [
            'id' => $id,
            'label' => $provider->getLabel(),
            'configured' => $provider->isConfigured($admin ? null : $userId),
            'capabilities' => $provider->getCapabilities(),
            // Distinguishes a personal key from one inherited from the instance.
            'hasKey' => $this->credentials->hasApiKey(null, $id),
            'hasUserKey' => $userId !== null && $this->credentials->hasApiKey($userId, $id),
            'currentModel' => $provider->getModel($admin ? null : $userId),
            'fields' => $fields,
        ];
    }

    /**
     * Apply a set of `{fieldId: value}` updates in user scope.
     *
     * An empty string clears the user override so the instance default applies
     * again — the same convention SettingsController::save() already uses.
     *
     * @param array<string, mixed> $values
     * @return list<string> ids of fields that were rejected (unknown or not user-writable)
     * @throws \InvalidArgumentException when a submitted value fails validation
     */
    public function writeUser(LLMProviderInterface $provider, string $userId, array $values): array {
        $schema = $this->indexSchema($provider);
        $rejected = [];
        $plan = [];

        // Validate and encode everything before writing anything, so a bad value
        // in one field cannot leave the rest half-applied.
        foreach ($values as $fieldId => $value) {
            $field = $schema[$fieldId] ?? null;
            if ($field === null || !ProviderSettingsSchema::isUserWritable($field)) {
                $rejected[] = (string)$fieldId;
                continue;
            }

            if (!empty($field['sensitive'])) {
                $plan[] = ['key' => null, 'value' => (string)$value];
                continue;
            }

            $key = $field['user_key'] ?? null;
            if ($key === null) {
                // Declared user-writable but with no user-scope key to write to.
                $rejected[] = (string)$fieldId;
                continue;
            }

            $plan[] = ['key' => $key, 'value' => $this->encode($field, $value)];
        }

        foreach ($plan as $write) {
            if ($write['key'] === null) {
                $this->writeApiKey($provider->getId(), $userId, $write['value']);
                continue;
            }
            // '' clears the override so the instance default applies again.
            if ($write['value'] === '') {
                $this->config->deleteUserValue($userId, self::APP_NAME, $write['key']);
            } else {
                $this->config->setUserValue($userId, self::APP_NAME, $write['key'], $write['value']);
            }
        }

        $this->logWrite($provider->getId(), 'user', $plan, $rejected, $userId);

        return $rejected;
    }

    /**
     * Apply a set of `{fieldId: value}` updates in admin scope.
     *
     * @param array<string, mixed> $values
     * @return list<string> ids of fields that were rejected
     * @throws \InvalidArgumentException when a submitted value fails validation
     */
    public function writeAdmin(LLMProviderInterface $provider, array $values): array {
        $schema = $this->indexSchema($provider);
        $rejected = [];
        $plan = [];

        foreach ($values as $fieldId => $value) {
            $field = $schema[$fieldId] ?? null;
            if ($field === null || !ProviderSettingsSchema::isAdminWritable($field)) {
                $rejected[] = (string)$fieldId;
                continue;
            }

            if (!empty($field['sensitive'])) {
                $plan[] = ['key' => null, 'value' => (string)$value];
                continue;
            }

            $key = $field['key'] ?? null;
            if ($key === null) {
                $rejected[] = (string)$fieldId;
                continue;
            }

            $plan[] = ['key' => $key, 'value' => $this->encode($field, $value)];
        }

        foreach ($plan as $write) {
            if ($write['key'] === null) {
                $this->writeApiKey($provider->getId(), null, $write['value']);
                continue;
            }
            $this->config->setAppValue(self::APP_NAME, $write['key'], $write['value']);
        }

        // An endpoint or key change invalidates whatever model list we cached.
        $this->forgetModels($provider->getId());

        $this->logWrite($provider->getId(), 'admin', $plan, $rejected, null);

        return $rejected;
    }

    /**
     * Model list for a provider: the live listing when available, otherwise the
     * static registry. Cached for MODEL_CACHE_TTL unless $refresh is set.
     *
     * @return list<string>
     */
    public function listModels(LLMProviderInterface $provider, ?string $userId, bool $refresh = false): array {
        $cache = $this->cacheFactory->createDistributed('aiquila-models');
        $cacheKey = $provider->getId();

        if (!$refresh) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)) {
                return array_values($cached);
            }
        }

        $models = $provider->listModels($userId);
        if ($models === null || $models === []) {
            $this->logger->warning('AIquila: no live model list from ' . $provider->getId() . ', using the static registry', [
                'provider' => $provider->getId(),
            ]);
            // Do not cache the fallback: it is static anyway, and caching it
            // would keep a transient outage pinned for an hour.
            return $this->staticModels($provider);
        }

        $cache->set($cacheKey, $models, self::MODEL_CACHE_TTL);
        return $models;
    }

    /** Drop the cached model list for a provider (or all of them). */
    public function forgetModels(?string $providerId = null): void {
        $cache = $this->cacheFactory->createDistributed('aiquila-models');
        if ($providerId === null) {
            $cache->clear();
            return;
        }
        $cache->remove($providerId);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Record which fields a settings save touched. Values are deliberately
     * absent: a plan entry with a null key is an API key, and even the
     * non-sensitive ones can carry endpoint URLs an admin would not expect in
     * the log. A rejection is logged at warning level because it means the UI
     * offered a field the scope rules refuse — either a schema bug or an
     * attempt to write an admin-only field from the personal page.
     *
     * @param list<array{key: ?string, value: string}> $plan
     * @param list<string> $rejected
     */
    private function logWrite(string $providerId, string $scope, array $plan, array $rejected, ?string $userId): void {
        $written = [];
        foreach ($plan as $write) {
            $written[] = $write['key'] ?? 'api_key';
        }

        $context = ['provider' => $providerId, 'scope' => $scope, 'fields' => $written];
        if ($userId !== null) {
            $context['user'] = $userId;
        }

        if ($written !== []) {
            $this->logger->info('AIquila: ' . $scope . ' settings saved for ' . $providerId, $context);
        }

        if ($rejected !== []) {
            $this->logger->warning('AIquila: rejected out-of-scope fields for ' . $providerId, $context + ['rejected' => $rejected]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function indexSchema(LLMProviderInterface $provider): array {
        $indexed = [];
        foreach ($provider->getSettingsSchema() as $field) {
            $indexed[$field['id']] = $field;
        }
        return $indexed;
    }

    /**
     * A descriptor plus its current value. Sensitive fields never carry a value
     * — only `hasValue`, so the UI can say "configured" without the key leaving
     * the credential manager.
     */
    private function describeField(
        array $field,
        string $providerId,
        ?string $userId,
        bool $admin,
        LLMProviderInterface $provider,
        bool $refreshModels,
    ): array {
        $out = $field;

        if (($field['options'] ?? null) === ProviderSettingsSchema::OPTIONS_MODELS) {
            $out['options'] = $this->listModels($provider, $admin ? null : $userId, $refreshModels);
        }

        if (!empty($field['sensitive'])) {
            $out['hasValue'] = $admin
                ? $this->credentials->hasApiKey(null, $providerId)
                : ($userId !== null && $this->credentials->hasApiKey($userId, $providerId));
            unset($out['value']);
            return $out;
        }

        $out['value'] = $admin
            ? $this->readAdmin($field)
            : $this->readUser($field, $userId);

        // The personal page shows what it would inherit when nothing is set.
        if (!$admin) {
            $out['inherited'] = $this->readAdmin($field);
        }

        return $out;
    }

    private function readAdmin(array $field): mixed {
        $key = $field['key'] ?? null;
        if ($key === null) {
            return $field['default'] ?? '';
        }
        $raw = (string)$this->config->getAppValue(self::APP_NAME, $key, $this->encodeDefault($field));
        return $this->decode($field, $raw);
    }

    private function readUser(array $field, ?string $userId): mixed {
        $key = $field['user_key'] ?? null;
        if ($key === null || $userId === null) {
            return $field['type'] === ProviderSettingsSchema::TYPE_CHECKBOX ? null : '';
        }
        $raw = (string)$this->config->getUserValue($userId, self::APP_NAME, $key, '');
        // '' means "not set — follow the instance default", which the UI renders
        // as an empty picker rather than as a false checkbox.
        return $raw === '' ? '' : $this->decode($field, $raw);
    }

    private function writeApiKey(string $providerId, ?string $userId, #[\SensitiveParameter] string $value): void {
        if ($value === '') {
            $this->credentials->deleteApiKey($userId, $providerId);
            return;
        }
        $this->credentials->setApiKey($userId, $value, $providerId);
        // A new key can unlock a different model list.
        $this->forgetModels($providerId);
    }

    /**
     * Turn a submitted value into its stored string form.
     *
     * @throws \InvalidArgumentException when the value is not valid for the field
     */
    private function encode(array $field, mixed $value): string {
        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_CHECKBOX) {
            if ($value === '' || $value === null) {
                return '';
            }
            $on = $value === true || $value === 1 || $value === '1' || $value === 'yes' || $value === 'true';
            return ($field['storage'] ?? ProviderSettingsSchema::STORAGE_YESNO) === ProviderSettingsSchema::STORAGE_BOOL
                ? ($on ? 'true' : 'false')
                : ($on ? 'yes' : 'no');
        }
        $string = trim((string)$value);

        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_URL && $string !== '') {
            $scheme = strtolower((string)parse_url($string, PHP_URL_SCHEME));
            if (($scheme !== 'http' && $scheme !== 'https') || parse_url($string, PHP_URL_HOST) === null) {
                throw new \InvalidArgumentException(
                    ($field['title'] ?? $field['id']) . ': enter a full http:// or https:// URL, for example http://localhost:11434'
                );
            }
        }

        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_NUMBER && $string !== '') {
            if (!ctype_digit($string) || (int)$string <= 0) {
                throw new \InvalidArgumentException(($field['title'] ?? $field['id']) . ': enter a positive whole number.');
            }
        }

        // A select may only receive one of the values it offered. `options` is
        // '@models' at this point for model fields, whose valid set is whatever
        // the provider currently serves — those are checked by the provider on
        // use, not here, since the live list can lag behind reality.
        $options = $field['options'] ?? null;
        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_SELECT
            && is_array($options)
            && !in_array($string, $options, true)
        ) {
            throw new \InvalidArgumentException(($field['title'] ?? $field['id']) . ': not one of the allowed values.');
        }

        return $string;
    }

    /** Turn a stored string back into the type the UI expects. */
    private function decode(array $field, string $raw): mixed {
        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_CHECKBOX) {
            return in_array($raw, ['yes', 'true', '1'], true);
        }
        return $raw;
    }

    private function encodeDefault(array $field): string {
        $default = $field['default'] ?? '';
        if (($field['type'] ?? '') === ProviderSettingsSchema::TYPE_CHECKBOX) {
            return $this->encode($field, $default);
        }
        return (string)$default;
    }

    /**
     * Static registry fallback, used when the live listing is unavailable
     * (no key yet, endpoint down, provider without a /models route).
     *
     * @return list<string>
     */
    private function staticModels(LLMProviderInterface $provider): array {
        $models = match ($provider->getId()) {
            'anthropic' => \OCA\AIquila\Service\ClaudeModels::getAllModels(),
            'mistral' => \OCA\AIquila\Service\MistralModels::getAllModels(),
            'deepseek' => \OCA\AIquila\Service\DeepSeekModels::getAllModels(),
            'hetzner' => \OCA\AIquila\Service\HetznerModels::getAllModels(),
            // Local model tags are arbitrary; the configured one is all we know.
            default => [$provider->getModel()],
        };
        return array_values(array_filter($models, static fn ($m) => is_string($m) && $m !== ''));
    }
}
