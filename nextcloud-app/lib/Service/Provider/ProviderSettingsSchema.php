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
    /** Multi-line free text (currently the local provider's extra headers). */
    public const TYPE_TEXTAREA = 'textarea';
    /** Multiple values from an async lookup; the value is a list of ids. */
    public const TYPE_MULTISELECT = 'multiselect';

    /**
     * Placeholder for the `options` of a model select. The API expands it to the
     * provider's live model list (cached), falling back to its static registry.
     */
    public const OPTIONS_MODELS = '@models';

    /**
     * Placeholder for the `options` of a principal picker. There is no fixed
     * option list — the client queries the principals endpoint as the admin
     * types, scoped by `principal_type`.
     */
    public const OPTIONS_PRINCIPALS = '@principals';

    /** Which side of the principals endpoint a picker queries. */
    public const PRINCIPAL_USER = 'user';
    public const PRINCIPAL_GROUP = 'group';

    /** Shown inline on the card. */
    public const GROUP_BASIC = 'basic';
    /** Hidden behind a disclosure on the card. */
    public const GROUP_ADVANCED = 'advanced';

    /**
     * Not stored in IConfig at all: the value is a list of principals held in
     * `aiquila_provider_access` and written through ProviderAccessService.
     */
    public const STORAGE_ACCESS = 'access';

    /**
     * `format` values. A format is an extra validation rule applied by
     * ProviderSettingsService::encode() on top of the type check, so the rules
     * live in one place instead of in each provider.
     */
    /** A single HTTP header name, e.g. `X-API-Key`. */
    public const FORMAT_HEADER_NAME = 'header_name';
    /** A `Name: value` block, one header per line. */
    public const FORMAT_HEADERS = 'headers';
    /** An absolute path to a file readable by the web server. */
    public const FORMAT_FILE_PATH = 'file_path';

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
        ?string $format = null,
        ?array $visibleIf = null,
    ): array {
        return self::visibility([
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_TEXT,
            'scope' => $scope,
            'key' => $appKey,
            'default' => $default,
            'placeholder' => $placeholder,
            'group' => $group,
        ] + ($format !== null ? ['format' => $format] : []), $visibleIf);
    }

    /**
     * Multi-line secret held in CredentialService under its own name rather
     * than in the per-provider API-key slot, for the second credential a
     * provider needs (the local provider's extra request headers). Like every
     * `sensitive` field the value is never returned to the client — only
     * whether one is stored.
     */
    public static function secretTextarea(
        string $id,
        string $secret,
        string $title,
        string $description,
        string $placeholder = '',
        ?string $format = null,
        ?array $visibleIf = null,
    ): array {
        return self::visibility([
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_TEXTAREA,
            'scope' => self::SCOPE_ADMIN,
            'sensitive' => true,
            'secret' => $secret,
            'optional' => true,
            'placeholder' => $placeholder,
            'group' => self::GROUP_ADVANCED,
        ] + ($format !== null ? ['format' => $format] : []), $visibleIf);
    }

    /** Single-line named secret; see secretTextarea() for the storage rules. */
    public static function secret(
        string $id,
        string $secret,
        string $title,
        string $description,
        ?array $visibleIf = null,
    ): array {
        return self::visibility([
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_PASSWORD,
            'scope' => self::SCOPE_ADMIN,
            'sensitive' => true,
            'secret' => $secret,
            'optional' => true,
            'group' => self::GROUP_ADVANCED,
        ], $visibleIf);
    }

    /**
     * Attach a display condition: the field is only rendered when the named
     * sibling field currently holds one of `in`.
     *
     * Presentation only — the settings page has no business showing a Basic
     * username while the mode is `bearer`. It is deliberately *not* a write
     * guard: scope is what decides who may write a field, and a hidden field
     * whose stored value stays put is the right behaviour when an admin
     * switches modes back and forth.
     */
    private static function visibility(array $field, ?array $visibleIf): array {
        if ($visibleIf !== null) {
            $field['visible_if'] = $visibleIf;
        }
        return $field;
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

    /**
     * The four access-control lists every provider carries.
     *
     * These are not declared by the providers themselves — access control is a
     * property of the app, not of any one provider — so ProviderSettingsService
     * appends them to every admin-scope description. They are SCOPE_ADMIN, which
     * is what keeps them off the personal settings page: isUserWritable() already
     * filters them out there, and writeUser() would reject them anyway.
     *
     * @return list<array<string, mixed>>
     */
    public static function accessLists(): array {
        return [
            self::principalList(
                'allowed_users',
                self::PRINCIPAL_USER,
                'Allowed users',
                'Only these users may use this provider. Leave empty to allow everyone.',
            ),
            self::principalList(
                'allowed_groups',
                self::PRINCIPAL_GROUP,
                'Allowed groups',
                'Only members of these groups may use this provider. Leave empty to allow everyone.',
            ),
            self::principalList(
                'blocked_users',
                self::PRINCIPAL_USER,
                'Blocked users',
                'These users may never use this provider, even if an allow list names them.',
            ),
            self::principalList(
                'blocked_groups',
                self::PRINCIPAL_GROUP,
                'Blocked groups',
                'Members of these groups may never use this provider, even if an allow list names them.',
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function principalList(string $id, string $principalType, string $title, string $description): array {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'type' => self::TYPE_MULTISELECT,
            'scope' => self::SCOPE_ADMIN,
            'storage' => self::STORAGE_ACCESS,
            'principal_type' => $principalType,
            'options' => self::OPTIONS_PRINCIPALS,
            'default' => [],
            'group' => self::GROUP_ADVANCED,
        ];
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
