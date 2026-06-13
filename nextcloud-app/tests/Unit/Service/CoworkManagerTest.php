<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\CoworkerTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Service\CoworkManager;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CoworkManagerTest extends TestCase {
    private $service;
    private $mapper;
    private $registry;
    private $rootFolder;
    private $logger;
    private CoworkManager $manager;

    protected function setUp(): void {
        $this->service = $this->createMock(CoworkerService::class);
        $this->mapper = $this->createMock(CoworkerMapper::class);
        $this->registry = $this->createMock(CoworkerTaskRegistry::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->manager = new CoworkManager(
            $this->service,
            $this->mapper,
            $this->registry,
            $this->rootFolder,
            $this->logger,
        );
    }

    private function coworker(int $id = 5, string $app = 'myapp'): Coworker {
        $cw = new Coworker();
        $cw->setUserId('alice');
        $cw->setOwnerApp($app);
        $cw->setTaskType('vision:classify');
        $cw->setCronSchedule('0 3 * * *');
        $cw->setInputPath('/Photos');
        $cw->setOptions('{}');
        $cw->setLastStatus('success');
        // id is protected on the mock Entity; set via reflection for assertions.
        $ref = new \ReflectionProperty($cw, 'id');
        $ref->setAccessible(true);
        $ref->setValue($cw, $id);
        return $cw;
    }

    public function testRegisterCreatesOwnedCoworker(): void {
        $created = $this->coworker();
        $this->service->expects($this->once())
            ->method('create')
            ->with('alice', $this->callback(fn($d) => !isset($d['id'])), 'myapp')
            ->willReturn($created);

        $result = $this->manager->register('myapp', 'alice', ['id' => 99, 'title' => 't']);
        $this->assertSame(5, $result['id']);
        $this->assertSame('myapp', $result['ownerApp']);
    }

    public function testUpdateResolvesByApp(): void {
        $cw = $this->coworker();
        $this->mapper->expects($this->once())
            ->method('findByIdAndApp')->with(5, 'myapp')->willReturn($cw);
        $this->service->expects($this->once())
            ->method('applyUpdate')->with($cw, ['title' => 'x'])->willReturn($cw);

        $this->manager->update('myapp', 5, ['title' => 'x']);
    }

    public function testForeignAppCannotResolve(): void {
        $this->mapper->method('findByIdAndApp')
            ->with(5, 'otherapp')
            ->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(DoesNotExistException::class);
        $this->manager->get('otherapp', 5);
    }

    public function testDeregisterDeletesEntity(): void {
        $cw = $this->coworker();
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->service->expects($this->once())->method('deleteEntity')->with($cw);

        $this->manager->deregister('myapp', 5);
    }

    public function testRegisterTaskTypeDelegates(): void {
        $type = $this->createMock(CoworkerTaskType::class);
        $this->registry->expects($this->once())->method('register')->with($type);
        $this->manager->registerTaskType($type);
    }

    public function testVerifyNotOwned(): void {
        $this->mapper->method('findByIdAndApp')
            ->willThrowException(new DoesNotExistException('nope'));

        $result = $this->manager->verify('otherapp', 5);
        $this->assertFalse($result['owned']);
        $this->assertFalse($result['valid']);
    }

    public function testVerifyHappyPath(): void {
        $cw = $this->coworker();
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->registry->method('has')->willReturn(true);

        $task = $this->createMock(CoworkerTaskType::class);
        $task->method('validateOptions'); // no throw
        $this->registry->method('get')->willReturn($task);

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->with('/Photos')->willReturn(true);
        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($folder);

        $result = $this->manager->verify('myapp', 5);
        $this->assertTrue($result['owned']);
        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['issues']);
        $this->assertSame('success', $result['lastStatus']);
    }

    public function testVerifyUnknownTaskType(): void {
        $cw = $this->coworker();
        $cw->setTaskType('bogus:type');
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->registry->method('has')->willReturn(false);

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(true);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $result = $this->manager->verify('myapp', 5);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unknown task type', implode(' ', $result['issues']));
    }

    public function testVerifyInvalidCron(): void {
        $cw = $this->coworker();
        $cw->setCronSchedule('not a cron');
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->registry->method('has')->willReturn(true);
        $this->registry->method('get')->willReturn($this->createMock(CoworkerTaskType::class));

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(true);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $result = $this->manager->verify('myapp', 5);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid cron schedule', implode(' ', $result['issues']));
    }

    public function testVerifyInvalidOptions(): void {
        $cw = $this->coworker();
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->registry->method('has')->willReturn(true);

        $task = $this->createMock(CoworkerTaskType::class);
        $task->method('validateOptions')
            ->willThrowException(new \InvalidArgumentException('maxTags out of range'));
        $this->registry->method('get')->willReturn($task);

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(true);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $result = $this->manager->verify('myapp', 5);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid options', implode(' ', $result['issues']));
    }

    public function testVerifyMissingInputPath(): void {
        $cw = $this->coworker();
        $this->mapper->method('findByIdAndApp')->willReturn($cw);
        $this->registry->method('has')->willReturn(true);
        $this->registry->method('get')->willReturn($this->createMock(CoworkerTaskType::class));

        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->with('/Photos')->willReturn(false);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);

        $result = $this->manager->verify('myapp', 5);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('input path not found', implode(' ', $result['issues']));
    }
}
