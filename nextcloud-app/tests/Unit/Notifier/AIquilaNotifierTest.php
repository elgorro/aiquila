<?php

namespace OCA\AIquila\Tests\Unit\Notifier;

use OCA\AIquila\Notifier\AIquilaNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class AIquilaNotifierTest extends TestCase {
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;
    private AIquilaNotifier $notifier;

    protected function setUp(): void {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->urlGenerator->method('imagePath')->willReturn('/apps/aiquila/img/app-dark.svg');
        $this->notifier = new AIquilaNotifier($this->urlGenerator, $this->l10n);
    }

    public function testGetId(): void {
        $this->assertSame('aiquila', $this->notifier->getID());
    }

    public function testGetName(): void {
        $this->assertSame('AIquila', $this->notifier->getName());
    }

    public function testPrepareThrowsForOtherApp(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('other_app');

        $this->expectException(\InvalidArgumentException::class);
        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskSuccess(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_success');
        $notification->method('getSubjectParameters')->willReturn(['Summarization']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('AIquila task completed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Summarization task has completed successfully.');
        $notification->expects($this->once())->method('setIcon')
            ->with('/apps/aiquila/img/app-dark.svg');

        // Chain methods return self
        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Text generation', 'API timeout']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('AIquila task failed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Text generation task failed: API timeout');
        $notification->expects($this->once())->method('setIcon');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithoutError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Image analysis', '']);

        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Image analysis task failed.');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareUnknownSubjectThrows(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('aiquila');
        $notification->method('getSubject')->willReturn('unknown_subject');

        $this->expectException(\InvalidArgumentException::class);
        $this->notifier->prepare($notification, 'en');
    }
}
