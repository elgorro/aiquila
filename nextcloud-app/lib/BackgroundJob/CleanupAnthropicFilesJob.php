<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Db\FileUploadMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily cleanup of stale Anthropic file_id cache entries.
 *
 * Anthropic's Files API evicts uploads after a retention window of roughly
 * 30 days. Rows in `aiquila_file_uploads` older than that point at deleted
 * remote files, so the next request reusing them would 404. This job
 * deletes such rows so the next upload path repopulates the cache.
 */
class CleanupAnthropicFilesJob extends TimedJob {
    /** Anthropic Files API retention window. */
    private const RETENTION_SECONDS = 30 * 86400;

    public function __construct(
        ITimeFactory $time,
        private readonly FileUploadMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run($argument): void {
        $cutoff = $this->time->getTime() - self::RETENTION_SECONDS;
        try {
            $deleted = $this->mapper->deleteOlderThan($cutoff);
            if ($deleted > 0) {
                $this->logger->info('AIquila Files: pruned stale Anthropic file cache rows', [
                    'deleted' => $deleted,
                    'cutoff' => $cutoff,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Files: cleanup job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
