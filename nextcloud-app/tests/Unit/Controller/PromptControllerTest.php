<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\PromptController;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\InstructionsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromptControllerTest extends TestCase {
    private function controller(PromptMapper $mapper): PromptController {
        return new PromptController('aiquila', $this->createStub(IRequest::class), $mapper, 'alice', $this->createStub(LoggerInterface::class));
    }

    private function prompt(int $id, string $scope, string $title = 'T'): Prompt {
        $p = new Prompt();
        $p->setId($id);
        $p->setScope($scope);
        $p->setTitle($title);
        $p->setContent('body');
        return $p;
    }

    /**
     * Admin routes rely on the framework's admin check, which applies to every
     * method without #[NoAdminRequired]. That attribute must never reach them.
     */
    public function testOnlyUserRoutesSkipTheAdminCheck(): void {
        $class = new \ReflectionClass(PromptController::class);
        foreach (['index', 'create', 'update', 'destroy'] as $method) {
            $this->assertNotEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
        foreach (['adminIndex', 'adminCreate', 'adminUpdate', 'adminDestroy'] as $method) {
            $this->assertEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
    }

    public function testUserCreateIsAlwaysUserScope(): void {
        $mapper = $this->createMock(PromptMapper::class);
        $mapper->expects($this->once())->method('insert')->willReturnCallback(static function (Prompt $p): Prompt {
            $p->setId(5);
            return $p;
        });

        $response = $this->controller($mapper)->create('Summary', 'Summarise this.', '');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('user', $data['scope']);
        $this->assertSame('Summary', $data['title']);
        $this->assertNull($data['description']);
    }

    public function testAdminCreateIsInstanceScope(): void {
        $mapper = $this->createStub(PromptMapper::class);
        $mapper->method('insert')->willReturnArgument(0);

        $data = $this->controller($mapper)->adminCreate('Policy', 'No secrets.')->getData();

        $this->assertSame('instance', $data['scope']);
    }

    public function testUserCannotUpdateAnInstancePrompt(): void {
        // findByIdAndUser only matches user-scope rows the caller owns.
        $mapper = $this->createMock(PromptMapper::class);
        $mapper->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));
        $mapper->expects($this->never())->method('update');
        $mapper->expects($this->never())->method('findInstanceById');

        $this->assertSame(404, $this->controller($mapper)->update(9, 'Hijacked')->getStatus());
        $this->assertSame(404, $this->controller($mapper)->destroy(9)->getStatus());
    }

    public function testUpdateKeepsOmittedFields(): void {
        $prompt = $this->prompt(3, 'user', 'Old');
        $prompt->setDescription('desc');
        $mapper = $this->createStub(PromptMapper::class);
        $mapper->method('findByIdAndUser')->willReturn($prompt);

        $data = $this->controller($mapper)->update(3, 'New')->getData();

        $this->assertSame('New', $data['title']);
        $this->assertSame('body', $data['content']);
        $this->assertSame('desc', $data['description']);
    }

    public function testRejectsMissingAndOversizedFields(): void {
        $mapper = $this->createMock(PromptMapper::class);
        $mapper->expects($this->never())->method('insert');
        $ctrl = $this->controller($mapper);

        $this->assertSame(400, $ctrl->create('  ', 'x')->getStatus());
        $this->assertSame(400, $ctrl->create('t', '  ')->getStatus());
        $this->assertSame(400, $ctrl->create('t', str_repeat('x', InstructionsService::MAX_LENGTH + 1))->getStatus());
        $this->assertSame(400, $ctrl->create(str_repeat('x', 256), 'x')->getStatus());
    }

    public function testIndexListsInstancePromptsBeforeTheUsersOwn(): void {
        $mapper = $this->createStub(PromptMapper::class);
        $mapper->method('findAllInstance')->willReturn([$this->prompt(1, 'instance')]);
        $mapper->method('findAllByUser')->willReturn([$this->prompt(2, 'user')]);

        $data = $this->controller($mapper)->index()->getData();

        $this->assertSame([1, 2], array_column($data, 'id'));
        $this->assertSame(['instance', 'user'], array_column($data, 'scope'));
    }
}
