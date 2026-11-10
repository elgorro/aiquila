<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Event;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IWebhookCompatibleEvent;

/**
 * A coworker run ended: successfully, with an error, or cancelled.
 *
 * Dispatched in-process once the run is stored. It implements
 * IWebhookCompatibleEvent, so an administrator can forward it to another system
 * with the Webhook listeners app (`OCA\AIquila\Event\CoworkerRunFinishedEvent`),
 * which makes a coworker a step in someone else's workflow. Runs end in a
 * background job with no signed-in user, so filter on `userId` in the payload
 * rather than with the webhook's user filter.
 */
class CoworkerRunFinishedEvent extends Event implements IWebhookCompatibleEvent {
    public function __construct(
        private readonly Coworker $coworker,
        private readonly CoworkerRun $run,
    ) {
        parent::__construct();
    }

    public function getCoworker(): Coworker {
        return $this->coworker;
    }

    public function getRun(): CoworkerRun {
        return $this->run;
    }

    /**
     * @return array{coworkerId: int, title: string, userId: string, teamId: ?string, taskType: string, runId: int, status: string, startedBy: ?string, itemsTotal: int, itemsProcessed: int, summary: ?string, error: ?string, startedAt: int, finishedAt: ?int}
     */
    public function getWebhookSerializable(): array {
        return [
            'coworkerId' => $this->coworker->getId(),
            'title' => $this->coworker->getTitle(),
            'userId' => $this->run->getUserId(),
            'teamId' => $this->coworker->getTeamId(),
            'taskType' => $this->coworker->getTaskType(),
            'runId' => $this->run->getId(),
            'status' => $this->run->getStatus(),
            'startedBy' => $this->run->getStartedBy(),
            'itemsTotal' => $this->run->getItemsTotal(),
            'itemsProcessed' => $this->run->getItemsProcessed(),
            'summary' => $this->run->getSummary(),
            'error' => $this->run->getError(),
            'startedAt' => $this->run->getStartedAt(),
            'finishedAt' => $this->run->getFinishedAt(),
        ];
    }
}
