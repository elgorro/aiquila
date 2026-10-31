<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Flow\FlowConfig;
use OCA\AIquila\Flow\FlowProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One AIquila Flow action, queued by AIquilaOperation when a rule matched.
 * The argument is {fileId, user, action, prompt}.
 */
class FlowOperationJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private FlowProcessor $processor,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /** @param mixed $argument */
    protected function run($argument): void {
        if (!is_array($argument) || !is_int($argument['fileId'] ?? null) || !is_string($argument['user'] ?? null)) {
            return;
        }
        try {
            $config = FlowConfig::parse((string)json_encode([
                'action' => $argument['action'] ?? FlowConfig::ACTION_AUTO,
                'prompt' => $argument['prompt'] ?? '',
            ]));
            $this->processor->process($argument['fileId'], $argument['user'], $config);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Flow: processing failed', [
                'fileId' => $argument['fileId'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
