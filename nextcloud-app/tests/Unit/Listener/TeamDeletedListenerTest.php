<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Listener;

require_once __DIR__ . '/../../stubs/circles.php';

use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Listener\TeamDeletedListener;
use OCA\AIquila\Service\CoworkerService;
use OCA\Circles\Events\CircleDestroyedEvent;
use OCA\Circles\Model\Circle;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class TeamDeletedListenerTest extends TestCase {
    public function testItRemovesTheTeamsCoworkersAndProjects(): void {
        $project = new Project();
        (new \ReflectionProperty($project, 'id'))->setValue($project, 5);

        $coworkers = $this->createMock(CoworkerService::class);
        $coworkers->expects($this->once())->method('removeForTeam')->with('team1');
        $projects = $this->createMock(ProjectMapper::class);
        $projects->expects($this->once())->method('findAllByTeam')->with('team1')->willReturn([$project]);
        $projects->expects($this->once())->method('delete')->with($project);
        $paths = $this->createMock(ProjectPathMapper::class);
        $paths->expects($this->once())->method('deleteByProject')->with(5);
        $conversations = $this->createMock(ConversationMapper::class);
        $conversations->expects($this->once())->method('clearProject')->with(5);

        $circle = $this->createStub(Circle::class);
        $circle->method('getSingleId')->willReturn('team1');
        $event = $this->createStub(CircleDestroyedEvent::class);
        $event->method('getCircle')->willReturn($circle);

        (new TeamDeletedListener($coworkers, $projects, $paths, $conversations))->handle($event);
    }

    public function testItIgnoresOtherEvents(): void {
        $coworkers = $this->createMock(CoworkerService::class);
        $coworkers->expects($this->never())->method('removeForTeam');

        (new TeamDeletedListener(
            $coworkers,
            $this->createStub(ProjectMapper::class),
            $this->createStub(ProjectPathMapper::class),
            $this->createStub(ConversationMapper::class),
        ))->handle(new Event());
    }
}
