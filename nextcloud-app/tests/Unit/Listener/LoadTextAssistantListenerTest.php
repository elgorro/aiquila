<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\LoadTextAssistantListener;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The cases where the fallback must stay out of the page. Each returns before
 * ViteAssets is reached, so the absence of a container lookup is the assertion.
 */
class LoadTextAssistantListenerTest extends TestCase {
    private IAppManager $appManager;
    private IUserSession $userSession;
    private LoadTextAssistantListener $listener;

    protected function setUp(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->listener = new LoadTextAssistantListener($this->appManager, $this->userSession);
    }

    public function testSkipsWithoutUser(): void {
        $this->userSession->method('getUser')->willReturn(null);
        $this->appManager->expects($this->never())->method('isEnabledForUser');
        $this->listener->handle(new Event());
    }

    public function testSkipsWhenTextDisabled(): void {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->appManager->expects($this->once())->method('isEnabledForUser')
            ->with('text')->willReturn(false);
        $this->listener->handle(new Event());
    }

    public function testSkipsWhenAssistantEnabled(): void {
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->appManager->expects($this->exactly(2))->method('isEnabledForUser')
            ->willReturnCallback(fn(string $app) => in_array($app, ['text', 'assistant'], true));
        $this->listener->handle(new Event());
    }

    public function testListensToEveryPageWhereTextOpens(): void {
        $this->assertSame([
            'OCA\Files\Event\LoadAdditionalScriptsEvent',
            'OCA\Viewer\Event\LoadViewer',
            'OCA\Text\Event\LoadEditor',
        ], LoadTextAssistantListener::EVENTS);
    }
}
