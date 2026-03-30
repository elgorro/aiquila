<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\TaskSuccessfulListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\TaskProcessing\Events\TaskSuccessfulEvent;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskSuccessfulListenerTest extends TestCase {
    private INotificationManager $notificationManager;
    private LoggerInterface $logger;
    private TaskSuccessfulListener $listener;

    protected function setUp(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new TaskSuccessfulListener($this->notificationManager, $this->logger);
    }

    public function testIgnoresNonAiquilaProvider(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('other_app:text2text');

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testIgnoresNullUser(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('aiquila:text2text');
        $task->method('getUserId')->willReturn(null);

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testCreatesNotificationOnSuccess(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('aiquila:text2text');
        $task->method('getUserId')->willReturn('testuser');
        $task->method('getTaskTypeId')->willReturn('core:text2text:summary');
        $task->method('getId')->willReturn(42);

        $event = $this->createMock(TaskSuccessfulEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setUser')->with('testuser');
        $notification->expects($this->once())->method('setObject')->with('task_processing', '42');
        $notification->expects($this->once())->method('setSubject')->with('task_success', ['Summarization']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify')->with($notification);

        $this->listener->handle($event);
    }

    public function testTaskTypeLabelMapping(): void {
        $this->assertSame('Summarization', TaskSuccessfulListener::getTaskTypeLabel('core:text2text:summary'));
        $this->assertSame('Image analysis', TaskSuccessfulListener::getTaskTypeLabel('core:image2text'));
        $this->assertSame('Unknown', TaskSuccessfulListener::getTaskTypeLabel('core:unknown'));
    }
}
