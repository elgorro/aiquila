<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Classification helper behind LLMProviderInterface::probe().
 *
 * A probe answers two questions the stored configuration cannot: does this key
 * reach this endpoint at all, and is the configured model actually on offer.
 * The four states map straight onto the light on a provider card — grey, red,
 * orange, green — and `reason` is the stable token that ends up in the log line
 * accompanying a red or orange light.
 *
 * Providers have no typed exception hierarchy to lean on: both the SDK and the
 * OpenAI-compatible HTTP path surface failures as arbitrary \Throwables whose
 * messages carry the status code. The sniffing here mirrors what the existing
 * errorMessage() implementations already do for chat errors, kept in one place
 * so every provider classifies identically.
 */
final class ProviderProbe {
    public const STATE_UNCONFIGURED = 'unconfigured';
    public const STATE_ERROR = 'error';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_OK = 'ok';

    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_UNAUTHORIZED = 'unauthorized';
    public const REASON_RATE_LIMITED = 'rate_limited';
    public const REASON_UNREACHABLE = 'unreachable';
    public const REASON_MODEL_MISSING = 'model_missing';
    public const REASON_OK = 'ok';

    /** @return array{state: string, reason: string, message: string, model: string} */
    public static function unconfigured(string $message, string $model = ''): array {
        return self::result(self::STATE_UNCONFIGURED, self::REASON_NOT_CONFIGURED, $message, $model);
    }

    /**
     * Outcome of a successful listing: green when the configured model is among
     * the ids the provider returned, orange when it is not. A provider that
     * answers with an empty list is reachable but cannot serve anything, which
     * is the same practical situation.
     *
     * @param list<string> $models
     * @return array{state: string, reason: string, message: string, model: string}
     */
    public static function fromModels(array $models, string $model): array {
        if ($models === []) {
            return self::result(
                self::STATE_DEGRADED,
                self::REASON_MODEL_MISSING,
                'The provider answered but offers no models.',
                $model,
            );
        }
        if ($model !== '' && !in_array($model, $models, true)) {
            return self::result(
                self::STATE_DEGRADED,
                self::REASON_MODEL_MISSING,
                'The provider is reachable but does not offer "' . $model . '". Pick another model.',
                $model,
            );
        }
        return self::result(self::STATE_OK, self::REASON_OK, 'Reachable, and the configured model is available.', $model);
    }

    /** @return array{state: string, reason: string, message: string, model: string} */
    public static function unauthorized(string $message, string $model = ''): array {
        return self::result(self::STATE_ERROR, self::REASON_UNAUTHORIZED, $message, $model);
    }

    /** @return array{state: string, reason: string, message: string, model: string} */
    public static function unreachable(string $message, string $model = ''): array {
        return self::result(self::STATE_ERROR, self::REASON_UNREACHABLE, $message, $model);
    }

    /** @return array{state: string, reason: string, message: string, model: string} */
    public static function rateLimited(string $message, string $model = ''): array {
        return self::result(self::STATE_DEGRADED, self::REASON_RATE_LIMITED, $message, $model);
    }

    /**
     * Outcome of a failed listing. $message is the provider's own human-readable
     * rendering of the error (errorMessage()), so the wording stays per-provider
     * while the classification stays shared.
     *
     * Rate limiting is orange rather than red: the key is valid and the endpoint
     * answered, the request just has to wait.
     *
     * @return array{state: string, reason: string, message: string, model: string}
     */
    public static function fromThrowable(\Throwable $e, string $message, string $model = ''): array {
        $raw = $e->getMessage();
        if (self::mentions($raw, ['401', '403', 'unauthorized', 'forbidden', 'invalid api key', 'authentication'])) {
            return self::result(self::STATE_ERROR, self::REASON_UNAUTHORIZED, $message, $model);
        }
        if (self::mentions($raw, ['429', 'rate limit', 'too many requests', 'quota'])) {
            return self::result(self::STATE_DEGRADED, self::REASON_RATE_LIMITED, $message, $model);
        }
        return self::result(self::STATE_ERROR, self::REASON_UNREACHABLE, $message, $model);
    }

    /** @param list<string> $needles */
    private static function mentions(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array{state: string, reason: string, message: string, model: string} */
    private static function result(string $state, string $reason, string $message, string $model): array {
        return ['state' => $state, 'reason' => $reason, 'message' => $message, 'model' => $model];
    }
}
