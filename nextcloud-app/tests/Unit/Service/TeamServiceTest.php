<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

require_once __DIR__ . '/../../stubs/circles.php';

use OCA\AIquila\Service\TeamService;
use OCA\Circles\CirclesManager;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class TeamServiceTest extends TestCase {
    private function member(string $userId, int $level, int $type = Member::TYPE_USER): Member {
        $member = $this->createStub(Member::class);
        $member->method('getUserId')->willReturn($userId);
        $member->method('getLevel')->willReturn($level);
        $member->method('getUserType')->willReturn($type);
        return $member;
    }

    /**
     * @param list<string> $enabledMembers accounts that exist, are enabled and are in the team
     */
    private function service(?CirclesManager $circles, array $enabledMembers = [], bool $support = true): TeamService {
        $teams = $this->createStub(ITeamManager::class);
        $teams->method('hasTeamSupport')->willReturn($support);
        $teams->method('getTeamsForUser')->willReturnCallback(function (string $userId) use ($enabledMembers): array {
            if (!in_array($userId, $enabledMembers, true)) {
                return [];
            }
            $team = $this->createStub(Team::class);
            $team->method('getId')->willReturn('team1');
            return [$team];
        });

        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $userId) use ($enabledMembers): ?IUser {
            if (!in_array($userId, $enabledMembers, true)) {
                return null;
            }
            $user = $this->createStub(IUser::class);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($circles);
        return new TeamService($teams, $users, $container);
    }

    private function circles(Member $owner, array $members): CirclesManager {
        $circle = $this->createStub(Circle::class);
        $circle->method('getOwner')->willReturn($owner);
        $circle->method('getMembers')->willReturn($members);
        $circles = $this->createStub(CirclesManager::class);
        $circles->method('getCircle')->willReturn($circle);
        return $circles;
    }

    public function testTheOwnerComesFirstThenAdmins(): void {
        $owner = $this->member('olga', 9);
        $circles = $this->circles($owner, [
            $this->member('mia', 1),
            $this->member('adam', 8),
            $this->member('mod', 4),
            $owner,
        ]);

        $this->assertSame(['olga', 'adam'], $this->service($circles, ['olga', 'adam', 'mia', 'mod'])->administrators('team1'));
    }

    public function testADepartedOwnerIsSkipped(): void {
        $circles = $this->circles($this->member('gone', 9), [$this->member('adam', 8)]);

        $this->assertSame(['adam'], $this->service($circles, ['adam'])->administrators('team1'));
    }

    public function testAGroupCannotBeAnAccount(): void {
        $circles = $this->circles($this->member('staff', 9, 2), [$this->member('adam', 8)]);

        $this->assertSame(['adam'], $this->service($circles, ['adam', 'staff'])->administrators('team1'));
    }

    public function testAMissingTeamIsNull(): void {
        $circles = $this->createStub(CirclesManager::class);
        $circles->method('getCircle')->willThrowException(new CircleNotFoundException());

        $this->assertNull($this->service($circles)->administrators('team1'));
    }

    public function testAnyOtherFailureIsNotAMissingTeam(): void {
        $circles = $this->createStub(CirclesManager::class);
        $circles->method('getCircle')->willThrowException(new \RuntimeException('db'));

        $this->expectException(\RuntimeException::class);
        $this->service($circles)->administrators('team1');
    }

    public function testTheSessionIsAlwaysClosed(): void {
        $circles = $this->createMock(CirclesManager::class);
        $circles->method('getCircle')->willThrowException(new CircleNotFoundException());
        $circles->expects($this->once())->method('stopSession');

        $this->service($circles)->administrators('team1');
    }

    public function testADisabledAccountCannotAct(): void {
        $this->assertFalse($this->service(null, [])->canActFor('team1', 'nobody'));
        $this->assertTrue($this->service(null, ['bob'])->canActFor('team1', 'bob'));
    }

    public function testNoTeamSupportMeansNoTeams(): void {
        $this->assertSame([], $this->service(null, ['bob'], false)->teamIdsFor('bob'));
    }
}
