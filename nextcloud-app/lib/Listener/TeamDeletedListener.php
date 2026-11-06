<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Service\CoworkerService;
use OCA\Circles\Events\CircleDestroyedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stops and deletes a deleted team's coworkers and deletes its projects.
 * Members keep their conversations, as when a project is deleted by hand.
 *
 * @template-implements IEventListener<Event>
 */
class TeamDeletedListener implements IEventListener {
    public function __construct(
        private readonly CoworkerService $coworkers,
        private readonly ProjectMapper $projects,
        private readonly ProjectPathMapper $paths,
        private readonly ConversationMapper $conversations,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof CircleDestroyedEvent) {
            return;
        }
        $teamId = $event->getCircle()->getSingleId();
        $this->coworkers->removeForTeam($teamId);
        foreach ($this->projects->findAllByTeam($teamId) as $project) {
            $this->conversations->clearProject($project->getId());
            $this->paths->deleteByProject($project->getId());
            $this->projects->delete($project);
        }
    }
}
