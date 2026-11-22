<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\UsageExport;

use OCA\AIquila\Service\UsageStatService;

/**
 * What a usage export covers and how it is laid out, validated once at the
 * edge (controller or occ) so the mapper and writer can trust it.
 *
 * Periods are half-open, `[from, to)`, in Unix seconds. A date without a time
 * is a whole UTC day: `from=2026-01-01` starts at its midnight and
 * `to=2026-01-31` ends at the following one, so a month reads naturally.
 */
final class UsageExportFilter {
    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';
    public const FORMATS = [self::FORMAT_CSV, self::FORMAT_JSON];

    public const GRANULARITY_REQUEST = 'request';
    public const GRANULARITY_DAY = 'day';
    public const GRANULARITY_WEEK = 'week';
    public const GRANULARITY_MONTH = 'month';
    public const GRANULARITIES = [self::GRANULARITY_REQUEST, self::GRANULARITY_DAY, self::GRANULARITY_WEEK, self::GRANULARITY_MONTH];

    // Column widths in Version0001Date20260218000000 / Version0031Date20261121000000.
    private const MAX_MODEL = 100;
    private const MAX_PROVIDER = 32;

    /**
     * @param self::FORMAT_* $format
     * @param self::GRANULARITY_* $granularity
     */
    private function __construct(
        public readonly string $format,
        public readonly string $granularity,
        public readonly ?int $from,
        public readonly ?int $to,
        public readonly ?string $model,
        public readonly ?string $provider,
        public readonly ?string $requestType,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a message fit to show the caller
     */
    public static function fromInput(
        ?string $format = null,
        ?string $granularity = null,
        ?string $from = null,
        ?string $to = null,
        ?string $model = null,
        ?string $provider = null,
        ?string $requestType = null,
    ): self {
        $format = self::blank($format) ?? self::FORMAT_CSV;
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('format must be one of: ' . implode(', ', self::FORMATS));
        }
        $granularity = self::blank($granularity) ?? self::GRANULARITY_REQUEST;
        if (!in_array($granularity, self::GRANULARITIES, true)) {
            throw new \InvalidArgumentException('granularity must be one of: ' . implode(', ', self::GRANULARITIES));
        }
        $requestType = self::blank($requestType);
        if ($requestType !== null && !in_array($requestType, UsageStatService::TYPES, true)) {
            throw new \InvalidArgumentException('requestType must be one of: ' . implode(', ', UsageStatService::TYPES));
        }
        $model = self::blank($model);
        if ($model !== null && mb_strlen($model) > self::MAX_MODEL) {
            throw new \InvalidArgumentException('model is too long');
        }
        $provider = self::blank($provider);
        if ($provider !== null && mb_strlen($provider) > self::MAX_PROVIDER) {
            throw new \InvalidArgumentException('provider is too long');
        }

        $fromTs = self::time($from, 'from', false);
        $toTs = self::time($to, 'to', true);
        if ($fromTs !== null && $toTs !== null && $toTs <= $fromTs) {
            throw new \InvalidArgumentException('to must be after from');
        }

        return new self($format, $granularity, $fromTs, $toTs, $model, $provider, $requestType);
    }

    public function isAggregated(): bool {
        return $this->granularity !== self::GRANULARITY_REQUEST;
    }

    /**
     * ISO 8601 date (`2026-01-31`) or date-time with an offset
     * (`2026-01-31T12:00:00Z`, `…+01:00`). A bare date is a UTC day; as the
     * end of a period it means "through the end of that day".
     */
    private static function time(?string $value, string $name, bool $isEnd): ?int {
        $value = self::blank($value);
        if ($value === null) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException($name . ' is not a valid date');
            }
            return $isEnd ? $date->modify('+1 day')->getTimestamp() : $date->getTimestamp();
        }
        // RFC 3339 profile of ISO 8601: a time always carries its offset, so
        // nothing depends on the server's timezone.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/i', $value) !== 1) {
            throw new \InvalidArgumentException($name . ' must be an ISO 8601 date (YYYY-MM-DD) or date-time with offset (YYYY-MM-DDTHH:MM:SSZ)');
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException($name . ' is not a valid date-time');
        }
        // DateTimeImmutable rolls 2026-02-30 over into March instead of failing.
        if ($time->format('Y-m-d') !== substr($value, 0, 10)) {
            throw new \InvalidArgumentException($name . ' is not a valid date-time');
        }
        return $time->getTimestamp();
    }

    private static function blank(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
