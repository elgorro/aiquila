<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\BackgroundJob;

use OCA\AIquila\Service\ContextChatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Re-indexes a single conversation in Context Chat off the request path.
 *
 * Enqueued (fire-and-forget) by the conversation controller after a message is
 * persisted, so chat responses never wait on indexing. The job is idempotent —
 * it simply re-submits the conversation's current transcript.
 */
class IndexConversationJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly ContextChatService $service,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $id = (int)($argument['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        try {
            $this->service->indexConversation($id);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Context Chat: index job failed', [
                'conversationId' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
