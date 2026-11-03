<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\OpenMetrics;

use Generator;
use OCA\AIquila\Db\UsageStatMapper;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use Override;

/**
 * Exports prompt-cache tokens across all users, by model, request type and
 * kind: `creation` (written to the cache, billed at a premium) and `read`
 * (served from it, billed at a discount). A prefix that should cache but
 * keeps showing `creation` without `read` has something drifting into it.
 *
 * Kept apart from `aiquila_tokens_used_total` so existing sums over that
 * family keep meaning input + output. Rows from providers that report no
 * cache tokens add nothing; a model/type pair without any is skipped.
 */
class PromptCacheTokensExporter implements IMetricFamily {
    public function __construct(
        private UsageStatMapper $usageStatMapper,
    ) {
    }

    #[Override]
    public function name(): string {
        return 'aiquila_prompt_cache_tokens_total';
    }

    #[Override]
    public function type(): MetricType {
        return MetricType::counter;
    }

    #[Override]
    public function unit(): string {
        return '';
    }

    #[Override]
    public function help(): string {
        return 'Total AIquila prompt-cache tokens, by model, request type and kind (creation/read).';
    }

    #[Override]
    public function metrics(): Generator {
        foreach ($this->usageStatMapper->sumTokensByModelAndType() as $row) {
            if ($row['cache_creation_tokens'] === 0 && $row['cache_read_tokens'] === 0) {
                continue;
            }
            yield new Metric($row['cache_creation_tokens'], [
                'model' => $row['model'],
                'request_type' => $row['request_type'],
                'kind' => 'creation',
            ]);
            yield new Metric($row['cache_read_tokens'], [
                'model' => $row['model'],
                'request_type' => $row['request_type'],
                'kind' => 'read',
            ]);
        }
    }
}
