<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Service\CoworkerService;
use OCA\Circles\Events\CircleDestroyedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stops and deletes a deleted team's coworkers. Its projects stay with the
 * people who created them.
 *
 * @template-implements IEventListener<Event>
 */
class TeamDeletedListener implements IEventListener {
    public function __construct(
        private readonly CoworkerService $coworkers,
        private readonly ProjectMapper $projects,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof CircleDestroyedEvent) {
            return;
        }
        $teamId = $event->getCircle()->getSingleId();
        $this->coworkers->removeForTeam($teamId);
        $this->projects->releaseFromTeam($teamId);
    }
}
