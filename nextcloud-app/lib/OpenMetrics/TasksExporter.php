<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\OpenMetrics;

use Generator;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use Override;

/**
 * Exports the total number of coworker (task) runs processed, labelled by status,
 * aggregated across all users.
 */
class TasksExporter implements IMetricFamily {
    public function __construct(
        private CoworkerRunMapper $coworkerRunMapper,
    ) {
    }

    #[Override]
    public function name(): string {
        return 'aiquila_tasks_total';
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
        return 'Total AIquila coworker task runs processed, by status.';
    }

    #[Override]
    public function metrics(): Generator {
        foreach ($this->coworkerRunMapper->countByStatus() as $status => $count) {
            yield new Metric($count, ['status' => $status]);
        }
    }
}
