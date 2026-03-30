<?php

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Listener\TaskFailedListener;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\TaskProcessing\Events\TaskFailedEvent;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskFailedListenerTest extends TestCase {
    private INotificationManager $notificationManager;
    private LoggerInterface $logger;
    private TaskFailedListener $listener;

    protected function setUp(): void {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new TaskFailedListener($this->notificationManager, $this->logger);
    }

    public function testIgnoresNonAiquilaProvider(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('other_app:text2text');

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $this->notificationManager->expects($this->never())->method('notify');
        $this->listener->handle($event);
    }

    public function testCreatesNotificationOnFailure(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('aiquila:text2text');
        $task->method('getUserId')->willReturn('testuser');
        $task->method('getTaskTypeId')->willReturn('core:text2text');
        $task->method('getId')->willReturn(99);
        $task->method('getErrorMessage')->willReturn('API rate limit exceeded');

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', ['Text generation', 'API rate limit exceeded']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify')->with($notification);

        $this->listener->handle($event);
    }

    public function testTruncatesLongErrorMessage(): void {
        $longError = str_repeat('x', 300);

        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('aiquila:text2text');
        $task->method('getUserId')->willReturn('testuser');
        $task->method('getTaskTypeId')->willReturn('core:text2text');
        $task->method('getId')->willReturn(100);
        $task->method('getErrorMessage')->willReturn($longError);

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', $this->callback(function (array $params) {
                return strlen($params[1]) <= 204; // 200 + '…' (3 bytes)
            }));

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $this->listener->handle($event);
    }

    public function testHandlesNullErrorMessage(): void {
        $task = $this->createMock(Task::class);
        $task->method('getProviderId')->willReturn('aiquila:text2text');
        $task->method('getUserId')->willReturn('testuser');
        $task->method('getTaskTypeId')->willReturn('core:text2text');
        $task->method('getId')->willReturn(101);
        $task->method('getErrorMessage')->willReturn(null);

        $event = $this->createMock(TaskFailedEvent::class);
        $event->method('getTask')->willReturn($task);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $notification->expects($this->once())->method('setSubject')
            ->with('task_failure', ['Text generation', '']);

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $this->listener->handle($event);
    }
}
