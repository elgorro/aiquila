<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\ProjectController;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Service\TeamService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who may move a project between teams (update with teamId).
 */
class ProjectControllerTest extends TestCase {
    private ?Project $saved = null;

    /**
     * @param list<string> $teamsOfCaller teams the caller belongs to
     * @param list<string>|\Throwable|null $administrators of the project's team
     */
    private function controller(string $caller, Project $project, array $teamsOfCaller, array|\Throwable|null $administrators = []): ProjectController {
        $projects = $this->createStub(ProjectMapper::class);
        $projects->method('findAccessible')->willReturn($project);
        $projects->method('update')->willReturnCallback(function (Project $p): Project {
            $this->saved = $p;
            return $p;
        });
        $paths = $this->createStub(ProjectPathMapper::class);
        $paths->method('findByProject')->willReturn([]);

        $teams = $this->createStub(TeamService::class);
        $teams->method('teamIdsFor')->willReturn($teamsOfCaller);
        if ($administrators instanceof \Throwable) {
            $teams->method('administrators')->willThrowException($administrators);
        } else {
            $teams->method('administrators')->willReturn($administrators);
        }

        return new ProjectController(
            'aiquila',
            $this->createStub(IRequest::class),
            $projects,
            $paths,
            $this->createStub(ConversationMapper::class),
            $caller,
            $this->createStub(LoggerInterface::class),
            $teams,
        );
    }

    private function project(?string $teamId, string $creator = 'alice'): Project {
        $p = new Project();
        $p->setId(7);
        $p->setUserId($creator);
        $p->setTeamId($teamId);
        $p->setTitle('Roadmap');
        return $p;
    }

    public function testTheCreatorMovesAProjectToAnotherOfTheirTeams(): void {
        $res = $this->controller('alice', $this->project('team-a'), ['team-a', 'team-b'])
            ->update(7, teamId: 'team-b');
        $this->assertSame(200, $res->getStatus());
        $this->assertSame('team-b', $res->getData()['teamId']);
    }

    public function testTheCreatorMakesATeamProjectPersonal(): void {
        $res = $this->controller('alice', $this->project('team-a'), ['team-a'])->update(7, teamId: '');
        $this->assertSame(200, $res->getStatus());
        $this->assertNull($res->getData()['teamId']);
    }

    public function testNobodyMovesAProjectToATeamTheyAreNotIn(): void {
        $res = $this->controller('alice', $this->project('team-a'), ['team-a'])->update(7, teamId: 'team-x');
        $this->assertSame(400, $res->getStatus());
        $this->assertSame('You are not a member of that team', $res->getData()['error']);
        $this->assertNull($this->saved);
    }

    public function testATeamAdminMovesTheTeamsProjectToAnotherTeam(): void {
        $res = $this->controller('bob', $this->project('team-a'), ['team-a', 'team-b'], ['carol', 'bob'])
            ->update(7, teamId: 'team-b');
        $this->assertSame(200, $res->getStatus());
        $this->assertSame('team-b', $res->getData()['teamId']);
        // The creator stays the creator.
        $this->assertSame('alice', $res->getData()['userId']);
    }

    public function testATeamAdminCannotMakeSomeoneElsesProjectPersonal(): void {
        $res = $this->controller('bob', $this->project('team-a'), ['team-a'], ['bob'])->update(7, teamId: '');
        $this->assertSame(400, $res->getStatus());
        $this->assertSame('Only the creator can make a team project personal', $res->getData()['error']);
        $this->assertNull($this->saved);
    }

    public function testATeamAdminStillNeedsToBelongToTheNewTeam(): void {
        $res = $this->controller('bob', $this->project('team-a'), ['team-a'], ['bob'])->update(7, teamId: 'team-b');
        $this->assertSame(400, $res->getStatus());
        $this->assertSame('You are not a member of that team', $res->getData()['error']);
    }

    public function testAPlainMemberCannotMoveIt(): void {
        $res = $this->controller('dave', $this->project('team-a'), ['team-a', 'team-b'], ['bob'])
            ->update(7, teamId: 'team-b');
        $this->assertSame(400, $res->getStatus());
        $this->assertSame('Only the creator or an owner or admin of its team can move a project', $res->getData()['error']);
        $this->assertNull($this->saved);
    }

    public function testWhenTheAdministratorsCannotBeReadOnlyTheCreatorMayMoveIt(): void {
        $res = $this->controller('bob', $this->project('team-a'), ['team-a', 'team-b'], new \RuntimeException('Circles disabled'))
            ->update(7, teamId: 'team-b');
        $this->assertSame(400, $res->getStatus());
        $this->assertNull($this->saved);
    }

    public function testAMemberMayEditAProjectWhileKeepingItsTeam(): void {
        $res = $this->controller('dave', $this->project('team-a'), ['team-a'])
            ->update(7, title: 'Roadmap 2027', teamId: 'team-a');
        $this->assertSame(200, $res->getStatus());
        $this->assertSame('Roadmap 2027', $res->getData()['title']);
        $this->assertSame('team-a', $res->getData()['teamId']);
    }
}
