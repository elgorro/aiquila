<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\OpenMetrics;

use Generator;
use OCA\AIquila\Db\ConversationMapper;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use Override;

/**
 * Exports the total number of conversations created across all users.
 */
class ConversationsExporter implements IMetricFamily {
    public function __construct(
        private ConversationMapper $conversationMapper,
    ) {
    }

    #[Override]
    public function name(): string {
        return 'aiquila_conversations_total';
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
        return 'Total AIquila conversations created.';
    }

    #[Override]
    public function metrics(): Generator {
        yield new Metric($this->conversationMapper->countAll());
    }
}
