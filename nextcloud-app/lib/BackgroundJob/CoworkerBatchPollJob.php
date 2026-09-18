<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Picks up coworker runs that are waiting on work finishing elsewhere.
 *
 * A run that handed its items to the Anthropic Batch API cannot finish in the
 * tick that submitted it — a batch is allowed to take up to 24 hours. It parks
 * its state and reports `pending`; this job asks each pending run whether its
 * batch has ended and lets the task type collect the results when it has.
 *
 * Separate from {@see CoworkerRunJob} on purpose. That one runs every minute
 * to dispatch schedules, and folding an API poll into it would turn a cheap
 * local query into a minute-by-minute round trip to Anthropic. Five minutes is
 * a fine granularity for work measured in minutes to hours.
 */
class CoworkerBatchPollJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private readonly CoworkerRunMapper $runMapper,
        private readonly CoworkerService $service,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
    }

    protected function run($argument): void {
        try {
            $pending = $this->runMapper->findPending();
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Cowork: could not query pending runs', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($pending as $run) {
            try {
                $this->service->resumePending($run);
            } catch (\Throwable $e) {
                // resumePending() captures its own failures; this guards
                // mapper issues so one bad row cannot stall the rest.
                $this->logger->error('AIquila Cowork: resuming a pending run errored', [
                    'run' => $run->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
