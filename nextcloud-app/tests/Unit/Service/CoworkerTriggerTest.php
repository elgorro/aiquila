<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Controller\CoworkerTriggerController;
use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;
use OCA\AIquila\Event\CoworkerRunFinishedEvent;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\CoworkerTriggerPolicy;
use OCA\AIquila\Service\CoworkerTriggerService;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coworkers started by something other than their schedule: the admin policy,
 * file and URL triggers, and what a run records and announces.
 */
#[AllowMockObjectsWithoutExpectations]
class CoworkerTriggerTest extends TestCase {
    private const NOW = 1_700_000_000;

    /** @var array<string, string> app config */
    private array $config = [];
    private $mapper;
    private $runMapper;
    private int $triggeredRunsLastHour = 0;

    protected function setUp(): void {
        $this->config = [
            CoworkerTriggerPolicy::KEY_ENABLED => 'yes',
        ];
        $this->mapper = $this->createMock(CoworkerMapper::class);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->runMapper = $this->createStub(CoworkerRunMapper::class);
        $this->runMapper->method('countStartedBySince')->willReturnCallback(fn () => $this->triggeredRunsLastHour);
        $this->runMapper->method('insert')->willReturnCallback(function (CoworkerRun $run): CoworkerRun {
            (new \ReflectionProperty($run, 'id'))->setValue($run, 42);
            return $run;
        });
        $this->runMapper->method('update')->willReturnArgument(0);
    }

    protected function tearDown(): void {
        CoworkerTriggerService::leaveRun(7);
    }

    private function policy(array $groupsOfUser = []): CoworkerTriggerPolicy {
        $settings = $this->createStub(AppSettings::class);
        $settings->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default);
        $settings->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            $this->config[$key] = $value;
        });
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturn($this->createStub(IUser::class));
        $groups = $this->createStub(IGroupManager::class);
        $groups->method('getUserGroupIds')->willReturn($groupsOfUser);
        return new CoworkerTriggerPolicy($settings, $groups, $users);
    }

    private function time(): ITimeFactory {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return $time;
    }

    private function triggers(?IRootFolder $root = null, ?CoworkerTriggerPolicy $policy = null): CoworkerTriggerService {
        $random = $this->createStub(ISecureRandom::class);
        $random->method('generate')->willReturn(str_repeat('a', 64));
        return new CoworkerTriggerService(
            $this->mapper,
            $this->runMapper,
            $policy ?? $this->policy(),
            $root ?? $this->createStub(IRootFolder::class),
            $random,
            $this->time(),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function coworker(): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setTaskType('docs:summarize');
        $cw->setCronSchedule('0 3 * * *');
        $cw->setIsActive(1);
        $cw->setInputType('folder');
        $cw->setInputPath('/In');
        $cw->setOutputPath('/In/Out');
        $cw->setTriggerFiles(1);
        $cw->setNextRunAt(self::NOW + 86400);
        (new \ReflectionProperty($cw, 'id'))->setValue($cw, 7);
        return $cw;
    }

    // ── Policy ──────────────────────────────────────────────────────────

    public function testThePolicyIsOffUntilAnAdminTurnsItOn(): void {
        $this->config = [];
        $this->assertFalse($this->policy()->isAllowed('alice'));
    }

    public function testOnWithEmptyListsIsEveryone(): void {
        $this->assertTrue($this->policy()->isAllowed('alice'));
    }

    public function testListsLimitItToTheUsersAndGroupsNamed(): void {
        $this->config[CoworkerTriggerPolicy::KEY_USERS] = '["bob"]';
        $this->config[CoworkerTriggerPolicy::KEY_GROUPS] = '["ops"]';
        $this->assertFalse($this->policy(['staff'])->isAllowed('alice'));
        $this->assertTrue($this->policy(['ops'])->isAllowed('alice'));
        $this->assertTrue($this->policy()->isAllowed('bob'));
    }

    public function testSettingThePolicyKeepsWhatIsLeftOutAndCleansIds(): void {
        $policy = $this->policy();
        $policy->set(null, [' bob ', 'bob', ''], null);
        $this->assertSame(['enabled' => true, 'users' => ['bob'], 'groups' => []], $policy->get());
    }

    // ── Requesting a run ────────────────────────────────────────────────

    public function testAFileTriggerMakesARunDueAfterTheDebounce(): void {
        $cw = $this->coworker();
        $this->assertTrue($this->triggers()->request($cw, CoworkerRun::STARTED_BY_FILES));
        $this->assertSame(self::NOW + CoworkerTriggerService::DEBOUNCE_SECONDS, $cw->getTriggerDueAt());
        $this->assertSame(CoworkerRun::STARTED_BY_FILES, $cw->getTriggerSource());
        $this->assertSame(self::NOW + CoworkerTriggerService::DEBOUNCE_SECONDS, $cw->getNextRunAt(), 'the run is pulled forward');
    }

    public function testMoreTriggersBeforeTheRunAreTheSameRun(): void {
        $cw = $this->coworker();
        $cw->setTriggerDueAt(self::NOW + 30);
        $cw->setTriggerSource(CoworkerRun::STARTED_BY_FILES);
        $this->triggers()->request($cw, CoworkerRun::STARTED_BY_FILES);
        $this->assertSame(self::NOW + 30, $cw->getTriggerDueAt(), 'not pushed back by later changes');
    }

    public function testAUrlCallIsNotDebouncedAndTakesOverAWaitingRun(): void {
        $cw = $this->coworker();
        $cw->setTriggerDueAt(self::NOW + 30);
        $cw->setTriggerSource(CoworkerRun::STARTED_BY_FILES);
        $this->triggers()->request($cw, CoworkerRun::STARTED_BY_URL);
        $this->assertSame(self::NOW, $cw->getTriggerDueAt());
        $this->assertSame(CoworkerRun::STARTED_BY_URL, $cw->getTriggerSource());
        $this->assertSame(self::NOW, $cw->getNextRunAt());
    }

    public function testNoRunForAPausedCoworkerOrAnAccountTheAdminDidNotAllow(): void {
        $this->mapper->expects($this->never())->method('update');
        $paused = $this->coworker();
        $paused->setPaused(1);
        $this->assertFalse($this->triggers()->request($paused, CoworkerRun::STARTED_BY_URL));

        $this->config[CoworkerTriggerPolicy::KEY_USERS] = '["bob"]';
        $this->assertFalse($this->triggers()->request($this->coworker(), CoworkerRun::STARTED_BY_URL));
    }

    public function testTheHourlyLimitStopsAStorm(): void {
        $this->mapper->expects($this->never())->method('update');
        $this->triggeredRunsLastHour = CoworkerTriggerService::MAX_TRIGGERED_RUNS_PER_HOUR;
        $this->assertFalse($this->triggers()->request($this->coworker(), CoworkerRun::STARTED_BY_FILES));
    }

    // ── File changes ────────────────────────────────────────────────────

    /** A tree /In (id 10) containing /In/Out (id 11), as the coworker's user sees it. */
    private function root(): IRootFolder {
        $in = $this->createStub(Folder::class);
        $in->method('getId')->willReturn(10);
        $out = $this->createStub(Folder::class);
        $out->method('getId')->willReturn(11);
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('get')->willReturnCallback(fn (string $path) => $path === '/In/Out' ? $out : $in);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);
        return $root;
    }

    /** A file whose parent folders have the given ids, innermost first, up to the root (1). */
    private function file(string $name, array $parentIds): File {
        $chain = [];
        $top = $this->createStub(Folder::class);
        $top->method('getId')->willReturn(1);
        $top->method('getParent')->willReturn($top);
        $next = $top;
        foreach (array_reverse($parentIds) as $id) {
            $folder = $this->createStub(Folder::class);
            $folder->method('getId')->willReturn($id);
            $folder->method('getParent')->willReturn($next);
            $next = $folder;
        }
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getParent')->willReturn($next);
        return $file;
    }

    public function testAFileInTheInputFolderTriggersIt(): void {
        $cw = $this->coworker();
        $this->mapper->method('findFileTriggered')->willReturn([$cw]);
        $this->mapper->expects($this->once())->method('update');
        $this->triggers($this->root())->fileChanged($this->file('report.pdf', [20, 10]));
        $this->assertSame(self::NOW + CoworkerTriggerService::DEBOUNCE_SECONDS, $cw->getTriggerDueAt());
    }

    public function testFilesInTheOutputFolderElsewhereOrHiddenDoNot(): void {
        $this->mapper->method('findFileTriggered')->willReturn([$this->coworker()]);
        $this->mapper->expects($this->never())->method('update');
        $triggers = $this->triggers($this->root());
        $triggers->fileChanged($this->file('summary.md', [11, 10]));
        $triggers->fileChanged($this->file('other.pdf', [30]));
        $triggers->fileChanged($this->file('.hidden', [10]));
        $triggers->fileChanged($this->file('upload.pdf.part', [10]));
    }

    public function testFilesTheCoworkersOwnRunWritesDoNotTriggerIt(): void {
        $this->mapper->method('findFileTriggered')->willReturn([$this->coworker()]);
        $this->mapper->expects($this->never())->method('update');
        CoworkerTriggerService::enterRun(7);
        $this->triggers($this->root())->fileChanged($this->file('report.summary.md', [10]));
    }

    public function testNothingIsLookedUpWhileTriggersAreOff(): void {
        $this->config = [];
        $this->mapper->expects($this->never())->method('findFileTriggered');
        $this->triggers($this->root(), $this->policy())->fileChanged($this->file('a.pdf', [10]));
    }

    // ── Trigger URL secret ──────────────────────────────────────────────

    public function testOnlyTheHashOfTheSecretIsStored(): void {
        $cw = $this->coworker();
        $token = $this->triggers()->createToken($cw);
        $this->assertSame(hash('sha256', $token), $cw->getTriggerTokenHash());
        $this->assertTrue(CoworkerTriggerService::tokenMatches($cw, $token));
        $this->assertFalse(CoworkerTriggerService::tokenMatches($cw, $token . 'x'));
        $this->assertFalse(CoworkerTriggerService::tokenMatches($cw, ''));
        $this->triggers()->revokeToken($cw);
        $this->assertFalse(CoworkerTriggerService::tokenMatches($cw, $token));
    }

    public function testTheBearerHeaderIsParsedPerRfc6750(): void {
        $this->assertSame('abc123', CoworkerTriggerController::bearerToken('Bearer abc123'));
        $this->assertSame('abc123', CoworkerTriggerController::bearerToken('bearer abc123'));
        $this->assertNull(CoworkerTriggerController::bearerToken('Basic abc123'));
        $this->assertNull(CoworkerTriggerController::bearerToken('Bearer'));
        $this->assertNull(CoworkerTriggerController::bearerToken(''));
    }

    public function testAWrongSecretAndAnUnknownCoworkerLookTheSameAndAreThrottled(): void {
        $cw = $this->coworker();
        $cw->setTriggerTokenHash(hash('sha256', 'right'));
        $this->mapper->method('findById')->willReturnCallback(fn (int $id) => $id === 7 ? $cw : throw new \OCP\AppFramework\Db\DoesNotExistException(''));
        foreach ([[7, 'Bearer wrong'], [8, 'Bearer right'], [7, '']] as [$id, $header]) {
            $request = $this->createStub(IRequest::class);
            $request->method('getHeader')->willReturn($header);
            $controller = new CoworkerTriggerController('aiquila', $request, $this->mapper, $this->triggers(), $this->createStub(LoggerInterface::class));
            $response = $controller->trigger($id);
            $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), "$id $header");
            // getHeaders() reaches into the server container, so read the field.
            $headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
            $this->assertStringStartsWith('Bearer', $headers['WWW-Authenticate']);
            $this->assertTrue($response->isThrottled());
        }
    }

    public function testTheRightSecretMakesARunDue(): void {
        $cw = $this->coworker();
        $cw->setTriggerTokenHash(hash('sha256', 'right'));
        $this->mapper->method('findById')->willReturn($cw);
        $request = $this->createStub(IRequest::class);
        $request->method('getHeader')->willReturn('Bearer right');
        $controller = new CoworkerTriggerController('aiquila', $request, $this->mapper, $this->triggers(), $this->createStub(LoggerInterface::class));
        $response = $controller->trigger(7);
        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $this->assertSame(['coworkerId' => 7, 'dueAt' => self::NOW], $response->getData());
    }

    // ── What a run records and announces ────────────────────────────────

    private function service(?IEventDispatcher $events = null, ?CoworkerTriggerPolicy $policy = null): CoworkerService {
        $taskType = $this->createStub(CoworkerTaskType::class);
        $taskType->method('run')->willReturn(['itemsTotal' => 1, 'itemsProcessed' => 1, 'summary' => 'ok']);
        $registry = $this->createStub(CoworkerTaskRegistry::class);
        $registry->method('get')->willReturn($taskType);
        $registry->method('has')->willReturn(true);
        return new CoworkerService(
            $this->mapper,
            $this->runMapper,
            $registry,
            $this->createStub(LLMProviderFactory::class),
            $this->time(),
            $this->createStub(LoggerInterface::class),
            null,
            null,
            $policy ?? $this->policy(),
            $events,
        );
    }

    public function testARunADueTriggerStartsRecordsItAndClearsIt(): void {
        $cw = $this->coworker();
        $cw->setScheduled(0);
        $cw->setTriggerDueAt(self::NOW - 5);
        $cw->setTriggerSource(CoworkerRun::STARTED_BY_URL);
        $this->mapper->method('findById')->willReturn($this->coworker());

        $run = $this->service()->execute($cw);

        $this->assertSame(CoworkerRun::STARTED_BY_URL, $run->getStartedBy());
        $this->assertNull($cw->getTriggerDueAt());
        $this->assertNull($cw->getNextRunAt(), 'unscheduled and nothing pending: no next run');
    }

    public function testATriggerThatCameInDuringTheRunIsKept(): void {
        $cw = $this->coworker();
        $stored = $this->coworker();
        $stored->setTriggerDueAt(self::NOW + 60);
        $stored->setTriggerSource(CoworkerRun::STARTED_BY_FILES);
        $this->mapper->method('findById')->willReturn($stored);

        $run = $this->service()->execute($cw);

        $this->assertSame(CoworkerRun::STARTED_BY_SCHEDULE, $run->getStartedBy());
        $this->assertSame(self::NOW + 60, $cw->getTriggerDueAt());
        $this->assertSame(self::NOW + 60, $cw->getNextRunAt(), 'earlier than the 03:00 schedule');
    }

    public function testAFinishedRunIsAnnouncedForWebhooks(): void {
        $this->mapper->method('findById')->willReturn($this->coworker());
        $events = $this->createMock(IEventDispatcher::class);
        $events->expects($this->once())->method('dispatchTyped')->with($this->callback(
            fn ($event) => $event instanceof CoworkerRunFinishedEvent
                && $event->getWebhookSerializable()['coworkerId'] === 7
                && $event->getWebhookSerializable()['status'] === CoworkerRun::STATUS_SUCCESS
                && $event->getWebhookSerializable()['startedBy'] === CoworkerRun::STARTED_BY_SCHEDULE
        ));
        $this->service($events)->execute($this->coworker());
    }

    public function testTurningOnFileTriggersNeedsThePolicy(): void {
        $this->config[CoworkerTriggerPolicy::KEY_USERS] = '["bob"]';
        $cw = $this->coworker();
        $cw->setTriggerFiles(0);
        $this->expectException(ValidationException::class);
        $this->service()->applyUpdate($cw, ['trigger_files' => true]);
    }

    public function testACoworkerWithoutInputCannotRunOnFileChanges(): void {
        $cw = $this->coworker();
        $this->expectException(ValidationException::class);
        $this->service()->applyUpdate($cw, ['input_type' => 'none']);
    }
}
