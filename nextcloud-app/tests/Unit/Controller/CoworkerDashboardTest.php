<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\CoworkerController;
use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class CoworkerDashboardTest extends TestCase {
    private CoworkerService $service;
    private IConfig $config;
    private CoworkerController $ctrl;

    protected function setUp(): void {
        $this->service = $this->createMock(CoworkerService::class);
        $this->config = $this->createMock(IConfig::class);
        $registry = $this->createMock(CoworkerTaskRegistry::class);
        $request = $this->createMock(IRequest::class);
        $this->ctrl = new CoworkerController(
            'aiquila', $request, $this->service, $registry, $this->config, 'alice'
        );
    }

    public function testGetReturnsStoredSelection(): void {
        $this->config->method('getUserValue')
            ->with('alice', 'aiquila', 'dashboard_coworker', '')
            ->willReturn('7');

        $res = $this->ctrl->getDashboardCoworker();
        $this->assertSame(['coworkerId' => 7], $res->getData());
    }

    public function testGetReturnsNullWhenUnset(): void {
        $this->config->method('getUserValue')->willReturn('');

        $res = $this->ctrl->getDashboardCoworker();
        $this->assertSame(['coworkerId' => null], $res->getData());
    }

    public function testSetPersistsOwnedCoworker(): void {
        $this->service->expects($this->once())
            ->method('findForUser')->with(7, 'alice')
            ->willReturn(new Coworker());
        $this->config->expects($this->once())
            ->method('setUserValue')->with('alice', 'aiquila', 'dashboard_coworker', '7');

        $res = $this->ctrl->setDashboardCoworker(7);
        $this->assertSame(['coworkerId' => 7], $res->getData());
    }

    public function testSetRejectsForeignCoworker(): void {
        $this->service->method('findForUser')
            ->willThrowException(new DoesNotExistException('nope'));
        $this->config->expects($this->never())->method('setUserValue');

        $res = $this->ctrl->setDashboardCoworker(99);
        $this->assertSame(404, $res->getStatus());
    }

    public function testSetNullClearsSelection(): void {
        $this->config->expects($this->once())
            ->method('deleteUserValue')->with('alice', 'aiquila', 'dashboard_coworker');

        $res = $this->ctrl->setDashboardCoworker(null);
        $this->assertSame(['coworkerId' => null], $res->getData());
    }
}
