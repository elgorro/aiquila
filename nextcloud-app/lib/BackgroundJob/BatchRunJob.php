<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Service\BatchRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Starts one manual batch run that {@see BatchRunService::start()} queued.
 *
 * Starting a run never does the work inside the HTTP request: walking the
 * folders and reading up to 200 files, or 200 synchronous model calls, would
 * outlive it. The job either submits a batch (the run turns `pending` and
 * {@see CoworkerBatchPollJob} takes over) or works through the files to the
 * end.
 */
class BatchRunJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly BatchRunService $service,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /** @param mixed $argument `{runId: int}` */
    protected function run($argument): void {
        $runId = is_array($argument) ? (int)($argument['runId'] ?? 0) : 0;
        if ($runId <= 0) {
            return;
        }
        try {
            $this->service->execute($runId);
        } catch (\Throwable $e) {
            // execute() captures its own failures; this guards mapper issues.
            $this->logger->error('AIquila batch run: execution errored', [
                'run' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
