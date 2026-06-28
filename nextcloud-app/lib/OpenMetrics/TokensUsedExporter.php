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
 * Exports total token consumption across all users, broken down by model,
 * request type and direction (input/output). Aggregated globally — no per-user
 * labels — to keep cardinality bounded and avoid exposing per-user usage.
 */
class TokensUsedExporter implements IMetricFamily {
    public function __construct(
        private UsageStatMapper $usageStatMapper,
    ) {
    }

    #[Override]
    public function name(): string {
        return 'aiquila_tokens_used_total';
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
        return 'Total AIquila tokens consumed, by model, request type and direction.';
    }

    #[Override]
    public function metrics(): Generator {
        foreach ($this->usageStatMapper->sumTokensByModelAndType() as $row) {
            yield new Metric($row['input_tokens'], [
                'model' => $row['model'],
                'request_type' => $row['request_type'],
                'direction' => 'input',
            ]);
            yield new Metric($row['output_tokens'], [
                'model' => $row['model'],
                'request_type' => $row['request_type'],
                'direction' => 'output',
            ]);
        }
    }
}
