<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\HetznerModels;

/**
 * Hetzner Inference provider (https://inference.hetzner.com/v1).
 *
 * Hetzner's Experiments platform serves open-weight models over an
 * OpenAI-compatible REST API from datacenters in Germany and Finland, so all
 * wire-format handling comes from AbstractOpenAiCompatibleProvider. Specifics:
 *   - Bearer token from the Hetzner experiment (required). No new tokens are
 *     issued and existing ones end with the experiment; see EXPERIMENT_NOTICE.
 *   - The model line-up changes; the live /models listing is authoritative and
 *     HetznerModels is only the fallback.
 *   - Some of the served models are multimodal and some are text-only, so image
 *     input is decided per model via HetznerModels::supportsVision().
 *   - Per-key quotas surface as HTTP 429: 4M in / 100k out tokens per 60s and
 *     — the one that bites the settings pages — a limit of 10 requests per 60s.
 *
 * The base URL is overridable through the app config key `hetzner_base_url`
 * because the service is experimental and may move. It is admin-scope only and
 * never user-settable: the server makes outbound requests to whatever is
 * configured, so letting users set it would be an SSRF vector.
 */
class HetznerProvider extends AbstractOpenAiCompatibleProvider {
    private const PROVIDER_ID = 'hetzner';

    /** Default endpoint; overridable via the `hetzner_base_url` app config key. */
    public const DEFAULT_API_BASE = 'https://inference.hetzner.com/v1';

    /** Shown wherever an admin or user can act on the end of the experiment. */
    public const EXPERIMENT_NOTICE = 'Hetzner is replacing the Inference experiment with an official product. Experiment tokens stop working on 8 December 2026 and no new ones are issued; Hetzner will announce the migration details at launch.';

    public function getId(): string {
        return self::PROVIDER_ID;
    }

    public function getLabel(): string {
        return 'Hetzner Inference (EU)';
    }

    protected function apiBase(): string {
        $configured = self::normalizeBaseUrl($this->config->getAppValue(self::APP_NAME, 'hetzner_base_url', ''));
        return $configured !== '' ? $configured : self::DEFAULT_API_BASE;
    }

    /**
     * Normalize an admin-entered endpoint: trim, drop the trailing slash and
     * require an http(s) URL with a host. Non-http(s) input yields '' so the
     * default endpoint is used. Unlike the local provider no `/v1` segment is
     * appended: an override names the full base URL, version segment included.
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
        return $url;
    }

    public function getModel(?string $userId = null): string {
        if ($userId) {
            $userModel = $this->config->getUserValue($userId, self::APP_NAME, 'user_model_hetzner', '');
            if ($userModel !== '') {
                return $userModel;
            }
        }
        return $this->config->getAppValue(self::APP_NAME, 'model_hetzner', HetznerModels::DEFAULT_MODEL);
    }

    public function getMaxTokens(?string $userId = null): int {
        $stored = (int)$this->config->getAppValue(self::APP_NAME, 'max_tokens_hetzner', (string)HetznerModels::DEFAULT_MAX_TOKENS);
        return min($stored, HetznerModels::getMaxTokenCeiling($this->getModel($userId)));
    }

    protected function defaultModel(): string {
        return HetznerModels::DEFAULT_MODEL;
    }

    protected function defaultMaxTokens(): int {
        return HetznerModels::DEFAULT_MAX_TOKENS;
    }

    /**
     * Adds the endpoint override. Admin-scope only: the service is
     * experimental and may move, but a user-settable endpoint would be an SSRF
     * vector — see the class docblock.
     */
    public function getSettingsSchema(): array {
        $fields = array_map(
            static function (array $field): array {
                if ($field['id'] === 'api_key') {
                    $field['description'] .= ' ' . self::EXPERIMENT_NOTICE;
                }
                return $field;
            },
            parent::getSettingsSchema(),
        );
        return array_merge($fields, [
            ProviderSettingsSchema::baseUrl(
                'hetzner_base_url',
                'API endpoint',
                'Leave blank to use ' . self::DEFAULT_API_BASE . '. Override only if Hetzner moves the service.',
                self::DEFAULT_API_BASE,
            ),
        ]);
    }

    /** Image input depends on the selected model; unknown ids are assumed capable. */
    protected function supportsVisionInput(?string $userId = null): bool {
        return HetznerModels::supportsVision($this->getModel($userId));
    }

    // ── Errors ──────────────────────────────────────────────────────────────

    protected function notConfiguredMessage(): string {
        return 'No Hetzner Inference token configured. ' . self::EXPERIMENT_NOTICE;
    }

    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (preg_match('/\b40[13]\b|unauthorized|forbidden/i', $msg) === 1) {
            return 'Hetzner Inference rejected the token. Check it in the AIquila settings. ' . self::EXPERIMENT_NOTICE;
        }
        if (preg_match('/\b(?:404|410)\b/', $msg) === 1) {
            return 'Hetzner Inference: model or endpoint not found. Check the model in the AIquila settings; the experiment is moving to an official product with different models.';
        }
        if (stripos($msg, '429') !== false) {
            return 'Hetzner Inference rate limit reached (per-token quota). Please try again shortly.';
        }
        if (stripos($msg, '503') !== false || stripos($msg, '502') !== false) {
            return 'Hetzner Inference is currently unavailable. The experiment is moving to an official product and has no availability guarantee.';
        }
        return 'Hetzner Inference error: ' . $msg;
    }
}
