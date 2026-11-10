<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\Service\CoworkerTriggerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

/**
 * Hands file additions and changes to the coworker triggers.
 *
 * Runs inside the request that changed the file, so it only marks runs as due
 * (CoworkerTriggerService); it never runs a coworker, and a failure here never
 * fails the upload.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent|NodeRenamedEvent|NodeCopiedEvent>
 */
class CoworkerFileTriggerListener implements IEventListener {
    public const EVENTS = [
        NodeCreatedEvent::class,
        NodeWrittenEvent::class,
        NodeRenamedEvent::class,
        NodeCopiedEvent::class,
    ];

    public function __construct(
        private readonly CoworkerTriggerService $triggers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        $node = match (true) {
            $event instanceof NodeCreatedEvent, $event instanceof NodeWrittenEvent => $event->getNode(),
            // A file moved or copied into the folder is new there.
            $event instanceof NodeRenamedEvent, $event instanceof NodeCopiedEvent => $event->getTarget(),
            default => null,
        };
        if ($node === null) {
            return;
        }
        try {
            $this->triggers->fileChanged($node);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Cowork: could not check file triggers', ['error' => $e->getMessage()]);
        }
    }
}
