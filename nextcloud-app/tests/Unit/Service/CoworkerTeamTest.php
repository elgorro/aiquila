<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\TeamService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who a team coworker runs as, and what happens when its team goes.
 */
#[AllowMockObjectsWithoutExpectations]
class CoworkerTeamTest extends TestCase {
    private const NOW = 1_800_000_000;

    private CoworkerMapper&MockObject $mapper;
    private CoworkerRunMapper&MockObject $runMapper;
    private TeamService&MockObject $teams;

    protected function setUp(): void {
        $this->mapper = $this->createMock(CoworkerMapper::class);
        $this->runMapper = $this->createMock(CoworkerRunMapper::class);
        $this->teams = $this->createMock(TeamService::class);
    }

    private function service(): CoworkerService {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new CoworkerService(
            $this->mapper,
            $this->runMapper,
            $this->createStub(CoworkerTaskRegistry::class),
            $this->createStub(LLMProviderFactory::class),
            $time,
            $this->createStub(LoggerInterface::class),
            null,
            $this->teams,
        );
    }

    private function coworker(?string $teamId = 'team1'): Coworker {
        $coworker = new Coworker();
        $coworker->setUserId('alice');
        $coworker->setTeamId($teamId);
        (new \ReflectionProperty($coworker, 'id'))->setValue($coworker, 7);
        return $coworker;
    }

    public function testAPersonalCoworkerIsNotChecked(): void {
        $this->teams->expects($this->never())->method('canActFor');

        $this->assertTrue($this->service()->prepareTeamRun($this->coworker(null)));
    }

    public function testItKeepsItsAccountWhileThatPersonCanActForTheTeam(): void {
        $this->teams->method('canActFor')->willReturn(true);
        $this->teams->expects($this->never())->method('administrators');
        $this->mapper->expects($this->never())->method('update');

        $this->assertTrue($this->service()->prepareTeamRun($this->coworker()));
    }

    public function testItFallsBackToTheTeamOwnerAndStoresWhoItRunsAs(): void {
        $coworker = $this->coworker();
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willReturn(['owner', 'admin']);
        $this->mapper->expects($this->once())->method('update')->with($coworker);

        $this->assertTrue($this->service()->prepareTeamRun($coworker));
        $this->assertSame('owner', $coworker->getUserId());
    }

    public function testANewAccountShowsOnTheRun(): void {
        $coworker = $this->coworker();
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willReturn(['owner']);
        $this->runMapper->method('insert')->willReturnCallback(function (CoworkerRun $run): CoworkerRun {
            (new \ReflectionProperty($run, 'id'))->setValue($run, 42);
            return $run;
        });
        $service = $this->service();

        $service->prepareTeamRun($coworker);
        $run = $service->execute($coworker);

        $this->assertSame('owner', $run->getUserId());
    }

    public function testAVanishedTeamMeansTheCoworkerIsRemoved(): void {
        $coworker = $this->coworker();
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willReturn(null);
        $this->runMapper->expects($this->once())->method('deleteByCoworker')->with(7);
        $this->mapper->expects($this->once())->method('delete')->with($coworker);

        $this->assertFalse($this->service()->prepareTeamRun($coworker));
    }

    /** Circles being disabled says nothing about the team. */
    public function testATeamThatCannotBeCheckedKeepsItsCoworker(): void {
        $coworker = $this->coworker();
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willThrowException(new \RuntimeException('no Circles'));
        $this->mapper->expects($this->never())->method('delete');
        $this->mapper->expects($this->never())->method('update');

        $this->assertFalse($this->service()->prepareTeamRun($coworker));
        $this->assertSame('alice', $coworker->getUserId());
    }

    public function testNobodyToRunAsPausesIt(): void {
        $coworker = $this->coworker();
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willReturn([]);
        $this->mapper->expects($this->never())->method('delete');

        $this->assertFalse($this->service()->prepareTeamRun($coworker));
        $this->assertSame(1, $coworker->getPaused());
        $this->assertStringContainsString('nobody in the team', (string)$coworker->getLastError());
    }

    public function testDeletingATeamStopsItsRunAndDeletesTheCoworker(): void {
        $coworker = $this->coworker();
        $run = new CoworkerRun();
        $run->setStatus(CoworkerRun::STATUS_RUNNING);
        $this->mapper->method('findAllByTeam')->willReturn([$coworker]);
        $this->runMapper->method('findActiveForCoworker')->willReturn($run);
        $this->mapper->expects($this->once())->method('delete')->with($coworker);

        $this->service()->removeForTeam('team1');

        $this->assertSame(CoworkerRun::STATUS_CANCELLED, $run->getStatus());
    }

    public function testACoworkerWithoutARunIsDeletedToo(): void {
        $this->mapper->method('findAllByTeam')->willReturn([$this->coworker()]);
        $this->runMapper->method('findActiveForCoworker')->willReturn(null);
        $this->mapper->expects($this->once())->method('delete');

        $this->service()->removeForTeam('team1');
    }

    public function testOneFailureDoesNotKeepTheOthersRunning(): void {
        $first = $this->coworker();
        $second = $this->coworker();
        $this->mapper->method('findAllByTeam')->willReturn([$first, $second]);
        $this->runMapper->method('findActiveForCoworker')->willReturn(null);
        $this->mapper->expects($this->exactly(2))->method('delete')
            ->willReturnOnConsecutiveCalls($this->throwException(new \RuntimeException('db')), $second);

        $this->service()->removeForTeam('team1');
    }

    public function testAnyMemberCanCreateOneForTheirTeam(): void {
        $this->teams->method('teamIdsFor')->willReturn(['team1']);
        $this->mapper->method('insert')->willReturnArgument(0);

        $coworker = $this->service()->create('bob', ['teamId' => 'team1', 'cronSchedule' => '0 3 * * *']);

        $this->assertSame('team1', $coworker->getTeamId());
        $this->assertSame('bob', $coworker->getUserId());
    }

    public function testNobodyCanCreateOneForAnotherTeam(): void {
        $this->teams->method('teamIdsFor')->willReturn(['team1']);

        $this->expectException(ValidationException::class);
        $this->service()->create('bob', ['teamId' => 'team2']);
    }

    /** ICoworkManager's calling app owns those, and its contract has no team. */
    public function testAnAppCannotCreateOneForATeam(): void {
        $this->mapper->method('insert')->willReturnArgument(0);

        $coworker = $this->service()->create('bob', ['teamId' => 'team1', 'cronSchedule' => '0 3 * * *'], 'someapp');

        $this->assertNull($coworker->getTeamId());
    }

    public function testMembersSeeEveryRunOfATeamCoworker(): void {
        $this->teams->method('teamIdsFor')->willReturn(['team1']);
        $this->mapper->method('findAccessible')->willReturn($this->coworker());
        $this->runMapper->expects($this->once())->method('findByCoworker')->with(7, null, 20)->willReturn([]);

        $this->service()->listRuns(7, 'bob');
    }

    public function testAPersonalCoworkerStaysPrivateEvenInATeam(): void {
        $this->teams->method('teamIdsFor')->willReturn(['team1']);
        $this->mapper->method('findAccessible')->willReturn($this->coworker(null));
        $this->runMapper->expects($this->once())->method('findByCoworker')->with(7, 'bob', 20)->willReturn([]);

        $this->service()->listRuns(7, 'bob');
    }

    public function testRunNowRefusesWhenTheTeamIsGone(): void {
        $this->teams->method('teamIdsFor')->willReturn(['team1']);
        $this->teams->method('canActFor')->willReturn(false);
        $this->teams->method('administrators')->willReturn(null);
        $this->mapper->method('findAccessible')->willReturn($this->coworker());

        $this->expectException(DoesNotExistException::class);
        $this->service()->runNow(7, 'bob');
    }
}
