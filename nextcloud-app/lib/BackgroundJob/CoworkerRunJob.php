<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Executes coworkers whose schedule is due.
 *
 * Runs about once a minute; each tick picks up active, non-paused coworkers with
 * next_run_at <= now and runs them via CoworkerService, which records a run row
 * and recomputes the next run time.
 */
class CoworkerRunJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly CoworkerMapper $mapper,
        private readonly CoworkerService $service,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
    }

    protected function run($argument): void {
        $now = $this->time->getTime();
        try {
            $due = $this->mapper->findDueForRun($now);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Cowork: could not query due coworkers', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($due as $coworker) {
            try {
                $this->service->execute($coworker);
            } catch (\Throwable $e) {
                // execute() captures its own failures; this guards mapper issues.
                $this->logger->error('AIquila Cowork: scheduled execution errored', [
                    'coworker' => $coworker->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
