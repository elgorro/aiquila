<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

use OCA\AIquila\Service\HetznerModels;

/**
 * Hetzner Inference provider (https://inference.hetzner.com/api/v1).
 *
 * Hetzner's Experiments platform serves open-weight models over an
 * OpenAI-compatible REST API from datacenters in Germany and Finland, so all
 * wire-format handling comes from AbstractOpenAiCompatibleProvider. Specifics:
 *   - Bearer token from https://experiments.hetzner.com/inference (required).
 *   - The model line-up changes; the live /models listing is authoritative and
 *     HetznerModels is only the fallback.
 *   - Some of the served models are multimodal and some are text-only, so image
 *     input is decided per model via HetznerModels::supportsVision().
 *   - Per-key quotas surface as HTTP 429: 4M in / 100k out tokens per 60s,
 *     500M / 5M per day, and — the one that bites the settings pages — a limit
 *     of 10 requests per 60s.
 *
 * The base URL is overridable through the app config key `hetzner_base_url`
 * because the service is experimental and may move. It is admin-scope only and
 * never user-settable: the server makes outbound requests to whatever is
 * configured, so letting users set it would be an SSRF vector.
 */
class HetznerProvider extends AbstractOpenAiCompatibleProvider {
    private const PROVIDER_ID = 'hetzner';

    /** Default endpoint; overridable via the `hetzner_base_url` app config key. */
    public const DEFAULT_API_BASE = 'https://inference.hetzner.com/api/v1';

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
     * appended — Hetzner's version segment is `/api/v1`.
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
        return array_merge(parent::getSettingsSchema(), [
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
        return 'No Hetzner Inference token configured. Create one at https://experiments.hetzner.com/inference.';
    }

    protected function errorMessage(\Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, '401') !== false || stripos($msg, 'unauthorized') !== false) {
            return 'Hetzner Inference rejected the token. Check it in the AIquila settings — tokens are created at https://experiments.hetzner.com/inference.';
        }
        if (stripos($msg, '429') !== false) {
            return 'Hetzner Inference rate limit reached (per-token quota). Please try again shortly.';
        }
        if (stripos($msg, '503') !== false || stripos($msg, '502') !== false) {
            return 'Hetzner Inference is currently unavailable. The service is experimental and offered without an availability guarantee.';
        }
        return 'Hetzner Inference error: ' . $msg;
    }
}
