<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\InstructionsController;
use OCA\AIquila\Service\InstructionsService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InstructionsControllerTest extends TestCase {
    private function controller(InstructionsService $service): InstructionsController {
        return new InstructionsController('aiquila', $this->createStub(IRequest::class), $service, $this->createStub(LoggerInterface::class));
    }

    public function testBothRoutesAreAdminOnly(): void {
        $class = new \ReflectionClass(InstructionsController::class);
        $this->assertEmpty($class->getMethod('get')->getAttributes(NoAdminRequired::class));
        $this->assertEmpty($class->getMethod('update')->getAttributes(NoAdminRequired::class));
    }

    public function testUpdateSavesTextAndScopes(): void {
        $service = $this->createMock(InstructionsService::class);
        $service->expects($this->once())->method('setInstanceInstructions')->with('We are ACME.');
        $service->expects($this->once())->method('setScopes')->with(['chat' => true, 'coworkers' => false, 'tasks' => true]);
        $service->method('getScopes')->willReturn(InstructionsService::SCOPE_DEFAULTS);

        $response = $this->controller($service)->update('We are ACME.', ['chat' => true, 'coworkers' => 'false', 'tasks' => '1']);

        $this->assertSame(200, $response->getStatus());
    }

    public function testNullLeavesEverythingAlone(): void {
        $service = $this->createMock(InstructionsService::class);
        $service->expects($this->never())->method('setInstanceInstructions');
        $service->expects($this->never())->method('setScopes');
        $service->method('getScopes')->willReturn(InstructionsService::SCOPE_DEFAULTS);

        $this->controller($service)->update();
    }

    public function testRejectsOversizedInstructions(): void {
        $service = $this->createMock(InstructionsService::class);
        $service->expects($this->never())->method('setInstanceInstructions');
        $service->expects($this->never())->method('setScopes');

        $response = $this->controller($service)->update(str_repeat('x', InstructionsService::MAX_LENGTH + 1), ['chat' => false]);

        $this->assertSame(400, $response->getStatus());
    }
}
