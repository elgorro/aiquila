<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Field descriptors for the settings UI, plus factories for the fields nearly
 * every provider has.
 *
 * Each provider describes its own configuration through
 * LLMProviderInterface::getSettingsSchema(). The admin and personal settings
 * pages render those descriptors generically, so adding a provider no longer
 * means hand-editing a PHP template and a pile of vanilla JS.
 *
 * A descriptor carries the config keys itself (`key` for app scope, `user_key`
 * for user scope) so that key naming stays with the provider that owns it —
 * ProviderSettingsService reads and writes purely from the descriptor.
 *
 * ## Scope is a security boundary, not a display hint
 *
 * `scope` decides which endpoint may write the field. Base-URL fields are
 * SCOPE_ADMIN because the server makes outbound requests to whatever is stored:
 * a user-settable endpoint would be an SSRF vector (see the class docblocks on
 * LocalProvider and HetznerProvider). ProviderSettingsService::writeUser()
 * rejects anything that is not SCOPE_USER or SCOPE_BOTH, so a new provider
 * cannot accidentally expose one by leaving the scope off — the default is
 * admin-only.
 *
 * `sensitive` fields (API keys) live in CredentialService, never in IConfig, and
 * their values are never returned to the client; the API reports only whether a
 * key exists in the requested scope.
 */
final class ProviderSettingsSchema {
    /** Admin-only: writable from the admin settings page. */
    public const SCOPE_ADMIN = 'admin';
    /** User-only: writable from the personal settings page. */
    public const SCOPE_USER = 'user';
    /** Writable in both scopes; the user value overrides the admin value. */
    public const SCOPE_BOTH = 'both';

    public const TYPE_TEXT = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT = 'select';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_URL = 'url';
    public const TYPE_NUMBER = 'number';

    /**
     * Placeholder for the `options` of a model select. The API expands it to the
     * provider's live model list (cached), falling back to its static registry.
     */
    public const OPTIONS_MODELS = '@models';

    /** Shown inline on the card. */
    public const GROUP_BASIC = 'basic';
    /** Hidden behind a disclosure on the card. */
    public const GROUP_ADVANCED = 'advanced';

    /** Booleans stored as the literal strings 'yes'/'no' (local provider legacy). */
    public const STORAGE_YESNO = 'yesno';
    /** Booleans stored as '1'/'0'. */
    public const STORAGE_BOOL = 'bool';

    /**
     * API key / token field. Stored in CredentialService keyed by provider id,
     * so it carries no config key.
     */
    public static function apiKey(string $title, string $description = '', bool $optional = false): array {
        return [
            'id' => 'api_key',
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_PASSWORD,
            'scope' => self::SCOPE_BOTH,
            'sensitive' => true,
            'optional' => $optional,
            'group' => self::GROUP_BASIC,
        ];
    }

    /** Default model select, backed by the live model list. */
    public static function model(string $appKey, string $userKey, string $default, string $description = ''): array {
        return [
            'id' => 'model',
            'title' => 'Default model',
            'description' => $description,
            'type' => self::TYPE_SELECT,
            'scope' => self::SCOPE_BOTH,
            'key' => $appKey,
            'user_key' => $userKey,
            'options' => self::OPTIONS_MODELS,
            'default' => $default,
            'group' => self::GROUP_BASIC,
        ];
    }

    public static function maxTokens(string $appKey, int $default, string $description = ''): array {
        return [
            'id' => 'max_tokens',
            'title' => 'Max output tokens',
            'description' => $description !== ''
                ? $description
                : 'Upper bound on the length of a single response. Capped by the model\'s own ceiling.',
            'type' => self::TYPE_NUMBER,
            'scope' => self::SCOPE_ADMIN,
            'key' => $appKey,
            'default' => (string)$default,
            'group' => self::GROUP_ADVANCED,
        ];
    }

    public static function timeout(string $appKey, int $default, string $description = ''): array {
        return [
            'id' => 'timeout',
            'title' => 'Request timeout (seconds)',
            'description' => $description !== ''
                ? $description
                : 'How long to wait for a response before giving up.',
            'type' => self::TYPE_NUMBER,
            'scope' => self::SCOPE_ADMIN,
            'key' => $appKey,
            'default' => (string)$default,
            'group' => self::GROUP_ADVANCED,
        ];
    }

    /**
     * Endpoint URL. Always SCOPE_ADMIN — see the class docblock; do not relax
     * this without revisiting the SSRF surface.
     */
    public static function baseUrl(string $appKey, string $title, string $description, string $placeholder = ''): array {
        return [
            'id' => $appKey,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_URL,
            'scope' => self::SCOPE_ADMIN,
            'key' => $appKey,
            'default' => '',
            'placeholder' => $placeholder,
            'group' => self::GROUP_BASIC,
        ];
    }

    public static function checkbox(
        string $id,
        string $appKey,
        string $title,
        string $description,
        bool $default = false,
        string $storage = self::STORAGE_YESNO,
        string $scope = self::SCOPE_ADMIN,
        string $group = self::GROUP_ADVANCED,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_CHECKBOX,
            'scope' => $scope,
            'key' => $appKey,
            'storage' => $storage,
            'default' => $default,
            'group' => $group,
        ];
    }

    public static function select(
        string $id,
        string $appKey,
        string $title,
        string $description,
        array $options,
        string $default = '',
        string $scope = self::SCOPE_ADMIN,
        string $group = self::GROUP_ADVANCED,
        ?string $userKey = null,
    ): array {
        $field = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_SELECT,
            'scope' => $scope,
            'key' => $appKey,
            'options' => array_values($options),
            'default' => $default,
            'group' => $group,
        ];
        if ($userKey !== null) {
            $field['user_key'] = $userKey;
        }
        return $field;
    }

    public static function text(
        string $id,
        string $appKey,
        string $title,
        string $description,
        string $default = '',
        string $scope = self::SCOPE_ADMIN,
        string $group = self::GROUP_ADVANCED,
        string $placeholder = '',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_TEXT,
            'scope' => $scope,
            'key' => $appKey,
            'default' => $default,
            'placeholder' => $placeholder,
            'group' => $group,
        ];
    }

    /**
     * Capability descriptor with every flag defaulted to false, so callers can
     * spread in only what they support and the shape stays complete.
     *
     * @return array{vision: bool, tools: bool, streaming: bool, thinking: bool, effort: bool, native_mcp: bool, documents: bool}
     */
    public static function capabilities(array $overrides = []): array {
        /** @var array{vision: bool, tools: bool, streaming: bool, thinking: bool, effort: bool, native_mcp: bool, documents: bool} $merged */
        $merged = array_merge([
            'vision' => false,
            'tools' => false,
            'streaming' => false,
            'thinking' => false,
            'effort' => false,
            'native_mcp' => false,
            'documents' => false,
        ], $overrides);
        return $merged;
    }

    /** True when the field may be written from the personal settings page. */
    public static function isUserWritable(array $field): bool {
        $scope = $field['scope'] ?? self::SCOPE_ADMIN;
        return $scope === self::SCOPE_USER || $scope === self::SCOPE_BOTH;
    }

    /** True when the field may be written from the admin settings page. */
    public static function isAdminWritable(array $field): bool {
        $scope = $field['scope'] ?? self::SCOPE_ADMIN;
        return $scope === self::SCOPE_ADMIN || $scope === self::SCOPE_BOTH;
    }
}
